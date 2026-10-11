<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step 2: one topic -> a finished article, in two stages.
 *
 * Stage A asks only for the outline (strict JSON): title, meta, lead,
 * takeaways, the H2/H3 skeleton with a per-section hint about which element
 * types belong there, and the FAQ. Cheap, verifiable, and it's where the SEO
 * structure is actually decided.
 *
 * Stage B asks for the body of each section separately, as a restricted HTML
 * fragment. One request per section keeps every answer short enough that the
 * model holds the format and doesn't get cut off by max_tokens — which is
 * exactly what happens when you ask for a 1500-word article in one go.
 */
class LAA_Article_Generator {

	/** Mirrors the prompt's own guidance; the hard ceiling is title_max_chars. */
	const TITLE_TARGET_CHARS = 70;

	const OUTLINE_SYSTEM = <<<'PROMPT'
Ты редактор и SEO-специалист. Готовишь план статьи, которую потом напишешь по разделам.

Верни СТРОГО JSON-объект (без markdown-обёртки, без пояснений вокруг):
{
  "title": "H1 статьи, до 70 символов, содержит главный ключ в естественной формулировке",
  "slug": "latinskiy-slug-cherez-defis",
  "meta_title": "title для поисковика, до 60 символов",
  "meta_description": "120-160 символов, содержит главный ключ, описывает пользу статьи, без воды",
  "lead": "2-3 предложения — прямой ответ на вопрос заголовка. Человек должен получить суть, даже если закроет страницу",
  "takeaways": ["3-5 коротких тезисов — что читатель поймёт из статьи"],
  "primary_keyword": "главный ключ",
  "secondary_keywords": ["3-6 дополнительных ключей"],
  "sections": [
    {
      "h2": "Заголовок раздела — утверждение или вопрос, не одно слово",
      "intent": "что именно раскрыть в этом разделе и чего НЕ дублировать из соседних",
      "h3": ["подзаголовки, если раздел распадается на части; иначе пустой массив"],
      "elements": ["paragraphs", "list"]
    }
  ],
  "faq": [{"q": "вопрос", "a": "ответ в 2-3 предложения"}],
  "tags": ["3-6 тегов"]
}

Допустимые значения в "elements": paragraphs, list, numbered_list, steps, table, quote, code.
Расставляй их осмысленно: таблица — там, где есть что сравнивать или перечислить по параметрам;
нумерованный список — там, где есть порядок действий; цитата — там, где есть тезис-вывод.
Не ставь одинаковый набор элементов во все разделы: статья должна читаться как живой текст,
а не как шаблон.

Требования:
— Разделы не пересекаются по смыслу. Каждый добавляет что-то новое.
— Первый раздел не повторяет лид, последний не называется «Заключение» без содержания.
— Заголовки разделов информативные: по оглавлению должно быть понятно, о чём статья.
— Без эмодзи. Без штампов: «в современном мире», «не секрет, что», «уникальный», «мощный»,
  «революционный», «просто и легко», «и это только начало».
— Не выдумывай цифры, даты, названия продуктов и цитаты людей.
— Язык: русский. Термины — в принятом в индустрии написании.
PROMPT;

	const SECTION_SYSTEM = <<<'PROMPT'
Ты пишешь ОДИН раздел статьи. Не вступление ко всей статье и не заключение — только этот раздел.

Верни только HTML-фрагмент, без markdown, без ```-обёртки, без пояснений.

Разрешённые теги: <p> <h3> <h4> <ul> <ol> <li> <table> <thead> <tbody> <tr> <th> <td>
<blockquote> <pre> <code> <strong> <em> <a>.
НЕ используй: <h1>, <h2> (заголовок раздела уже стоит на странице), <img>, <div>, <span>,
<script>, инлайновые стили и атрибуты class.

Как писать:
— Абзац — 2-4 предложения. Монолитных абзацев быть не должно.
— Первое предложение раздела сразу по делу, без разгона и без повторения заголовка.
— Список — когда перечисляешь однородное; <ol> — когда важен порядок шагов.
— Таблица — когда сравниваешь по параметрам: шапка <thead> обязательна, 2-5 столбцов,
  3-8 строк, в ячейках короткие формулировки, а не абзацы.
— <blockquote> — один короткий тезис-вывод, своими словами, без приписывания кому-то.
— <pre><code> — только если нужен код или пример промпта; код не выдумывай под видом рабочего.
— <strong> — на термин или на вывод, не на половину предложения.
— Ссылки <a> ставь только если URL дан тебе в задании. Своих URL не придумывай.
— Без эмодзи, без штампов, без обращений «дорогой читатель», без «итак» и «давайте разберёмся».
— Не повторяй то, что по заданию раскрывается в других разделах.
— Не выдумывай цифры, даты, цитаты и названия.
— Используй ТОЛЬКО те структурные элементы, которые перечислены в задании.
  Не добавляй таблицу, цитату или код, если их там нет.
— Поисковые фразы даны тебе для понимания темы, а НЕ для дословной вставки.
  Никогда не вставляй фразу, если от неё ломается грамматика: «Обработка email агенты
  требует...» — недопустимо. Пиши так, как сказал бы человек; при необходимости меняй
  форму слов или вообще не используй фразу в этом разделе.
— Язык: русский.
PROMPT;

	const FAQ_FALLBACK_COUNT = 4;

	/**
	 * @param array $topic Row from the queue table.
	 * @param int   $plan_id
	 * @return array{ok:bool,article:array,error:string}
	 */
	public static function generate( array $topic, $plan_id ) {
		$meta = LAA_Meta_Fields::get_plan_settings( $plan_id );

		if ( ! LAA_Settings::llm_ready( $meta['llm_provider'] ) ) {
			return self::fail( 'Не заданы ключи доступа к модели' );
		}

		$client  = new LAA_LLM_Client( $meta['llm_provider'] );
		$outline = self::request_outline( $client, $topic, $meta );

		if ( null === $outline ) {
			return self::fail( 'План статьи: ' . ( $client->last_error() ?: 'модель не ответила' ) );
		}

		$outline = self::normalise_outline( $outline, $topic, $meta );
		if ( empty( $outline['sections'] ) ) {
			return self::fail( 'В плане статьи не оказалось ни одного раздела' );
		}

		$outline = self::shorten_titles( $client, $outline, $meta );

		// Word budget per section, so the whole article lands near the target
		// length instead of whatever the model feels like producing.
		$target_words   = (int) ( ( $meta['min_words'] + $meta['max_words'] ) / 2 );
		$section_count  = count( $outline['sections'] );
		$words_per_part = max( 120, (int) round( ( $target_words - 120 ) / $section_count ) );

		$failed_sections = 0;
		foreach ( $outline['sections'] as $i => $section ) {
			$html = self::request_section( $client, $outline, $section, $i, $meta, $words_per_part, $topic );
			if ( '' === $html ) {
				++$failed_sections;
				LAA_Log::error( sprintf( 'Тема #%d: раздел «%s» не сгенерирован — %s', (int) $topic['id'], $section['h2'], $client->last_error() ?: 'пустой ответ' ) );
			}
			$outline['sections'][ $i ]['html'] = $html;
		}

		// A couple of empty sections we can live with — the builder drops them.
		// Everything empty means the provider is down, and publishing a husk
		// of H2s with no text would be worse than failing the run.
		if ( $failed_sections === $section_count ) {
			return self::fail( 'Ни один раздел не сгенерирован: ' . ( $client->last_error() ?: 'пустые ответы модели' ) );
		}

		return array(
			'ok'      => true,
			'article' => $outline,
			'error'   => '',
		);
	}

	/**
	 * Brings title and meta_title inside their limits by asking the model to
	 * rewrite them — never by cutting. Whatever can't be shortened is kept in
	 * full, and the reason lands in title_notes for the checklist to report.
	 */
	private static function shorten_titles( LAA_LLM_Client $client, array $outline, array $meta ) {
		$outline['title_notes'] = array();

		$context = array(
			'keyword'  => $outline['primary_keyword'],
			'audience' => $meta['audience'],
		);

		$title_max = (int) LAA_Settings::get( 'title_max_chars', 250 );
		$result    = LAA_Title_Shortener::shorten( $client, $outline['title'], $title_max, $context );
		$outline['title'] = $result['title'];
		if ( '' !== $result['note'] ) {
			$outline['title_notes'][] = $result['note'];
		}

		// meta_title falls back to the H1, so shorten it after the H1 is settled.
		$meta_title = '' !== trim( $outline['meta_title'] ) ? $outline['meta_title'] : $outline['title'];
		$meta_max   = (int) LAA_Settings::get( 'meta_title_max_chars', 60 );
		$result     = LAA_Title_Shortener::shorten( $client, $meta_title, $meta_max, $context );
		$outline['meta_title'] = $result['title'];
		if ( '' !== $result['note'] ) {
			$outline['title_notes'][] = 'meta_title: ' . $result['note'];
		}

		return $outline;
	}

	private static function request_outline( LAA_LLM_Client $client, array $topic, array $meta ) {
		$required = array();
		if ( $meta['require_table'] ) {
			$required[] = 'хотя бы в одном разделе в "elements" должна быть "table"';
		}
		if ( $meta['require_lists'] ) {
			$required[] = 'минимум в двух разделах должны быть "list" или "numbered_list"';
		}
		if ( $meta['require_quote'] ) {
			$required[] = 'ровно в одном разделе должна быть "quote"';
		}
		if ( $meta['require_faq'] ) {
			$required[] = 'в "faq" нужно ' . self::FAQ_FALLBACK_COUNT . '-6 реальных вопросов, которые задаёт человек по этой теме';
		} else {
			$required[] = '"faq" оставь пустым массивом';
		}

		$parts   = array();
		$parts[] = 'Тема статьи: ' . $topic['title'];
		if ( ! empty( $topic['keywords'] ) ) {
			$parts[] = 'Поисковые фразы: ' . $topic['keywords'];
		}
		if ( ! empty( $topic['angle'] ) ) {
			$parts[] = 'Ракурс: ' . $topic['angle'];
		}
		if ( $meta['theme'] ) {
			$parts[] = 'Направление сайта: ' . $meta['theme'];
		}
		if ( $meta['audience'] ) {
			$parts[] = 'Аудитория: ' . $meta['audience'];
		}
		if ( $meta['tone'] ) {
			$parts[] = 'Тон: ' . $meta['tone'];
		}
		$parts[] = sprintf( 'Разделов (H2): от %d до %d.', $meta['sections_min'], $meta['sections_max'] );
		$parts[] = sprintf(
			'Заголовок статьи (title) — не длиннее %d символов. meta_title — не длиннее %d символов, это отдельная короткая формулировка для поисковой выдачи, она может отличаться от title. Заголовки разделов — не длиннее %d символов.',
			(int) LAA_Settings::get( 'title_max_chars', 250 ) < self::TITLE_TARGET_CHARS ? (int) LAA_Settings::get( 'title_max_chars', 250 ) : self::TITLE_TARGET_CHARS,
			(int) LAA_Settings::get( 'meta_title_max_chars', 60 ),
			self::TITLE_TARGET_CHARS + 20
		);
		$parts[] = sprintf( 'Объём готовой статьи: %d-%d слов — распределяй разделы так, чтобы в этот объём уложиться.', $meta['min_words'], $meta['max_words'] );
		$parts[] = 'Обязательно: ' . implode( '; ', $required ) . '.';
		if ( $meta['banned_words'] ) {
			$parts[] = 'Не используй эти слова и обороты: ' . $meta['banned_words'];
		}
		if ( $meta['extra_prompt'] ) {
			$parts[] = 'Дополнительные указания: ' . $meta['extra_prompt'];
		}

		return $client->json( self::OUTLINE_SYSTEM, implode( "\n", $parts ), 3000, (float) LAA_Settings::get( 'llm_temperature_plan', 0.7 ) );
	}

	private static function request_section( LAA_LLM_Client $client, array $outline, array $section, $index, array $meta, $words, array $topic ) {
		$others = array();
		foreach ( $outline['sections'] as $i => $other ) {
			if ( $i === $index ) {
				continue;
			}
			$others[] = $other['h2'];
		}

		$element_hints = self::describe_elements( $section['elements'] );

		$parts   = array();
		$parts[] = 'Статья: ' . $outline['title'];
		$parts[] = 'Лид статьи (его повторять не надо): ' . $outline['lead'];
		$parts[] = 'Раздел, который ты пишешь: ' . $section['h2'];
		if ( ! empty( $section['intent'] ) ) {
			$parts[] = 'Что раскрыть: ' . $section['intent'];
		}
		if ( ! empty( $section['h3'] ) ) {
			$parts[] = 'Используй эти подзаголовки <h3> в этом порядке: ' . implode( ' | ', $section['h3'] );
		}
		if ( $others ) {
			$parts[] = 'Остальные разделы статьи (их содержание не дублируй): ' . implode( ' | ', $others );
		}
		$parts[] = 'Объём раздела: примерно ' . $words . ' слов.';
		$parts[] = 'Элементы, которые нужно использовать в этом разделе: ' . $element_hints . '.';
		$forbidden = self::forbidden_elements( $section['elements'] );
		if ( $forbidden ) {
			$parts[] = 'В этом разделе НЕ используй: ' . implode( ', ', $forbidden ) . '.';
		}
		if ( ! empty( $outline['primary_keyword'] ) ) {
			// Deliberately framed as "about", not "insert": asked to place a
			// keyword, the model writes "Обработка email агенты требует...".
			$parts[] = 'Раздел должен быть по теме «' . $outline['primary_keyword'] . '». Дословно вставлять эту фразу не нужно.';
		}
		if ( $meta['banned_words'] ) {
			$parts[] = 'Запрещённые слова и обороты: ' . $meta['banned_words'];
		}
		if ( $meta['extra_prompt'] ) {
			$parts[] = 'Дополнительные указания: ' . $meta['extra_prompt'];
		}

		// Roughly 2 tokens per Russian word, plus headroom for the markup.
		$max_tokens = min( 4000, (int) ( $words * 3 ) + 400 );

		$html = $client->text( self::SECTION_SYSTEM, implode( "\n", $parts ), $max_tokens, (float) LAA_Settings::get( 'llm_temperature_body', 0.6 ) );
		return $html ? LAA_LLM_Client::strip_fence( $html ) : '';
	}

	/** The structural elements this section did NOT ask for, named for the prompt. */
	private static function forbidden_elements( array $elements ) {
		$all = array(
			'table' => 'таблицы',
			'quote' => 'цитаты <blockquote>',
			'code'  => 'блоки кода',
		);

		$out = array();
		foreach ( $all as $key => $label ) {
			if ( ! in_array( $key, $elements, true ) ) {
				$out[] = $label;
			}
		}
		return $out;
	}

	private static function describe_elements( array $elements ) {
		$map = array(
			'paragraphs'   => 'связные абзацы',
			'list'         => 'маркированный список <ul>',
			'numbered_list' => 'нумерованный список <ol>',
			'steps'        => 'нумерованный список шагов <ol>',
			'table'        => 'таблица <table> с шапкой <thead>',
			'quote'        => 'короткая цитата-вывод <blockquote>',
			'code'         => 'пример кода или промпта в <pre><code>',
		);

		$out = array();
		foreach ( $elements as $element ) {
			if ( isset( $map[ $element ] ) ) {
				$out[ $map[ $element ] ] = true;
			}
		}
		if ( empty( $out ) ) {
			$out['связные абзацы'] = true;
		}
		return implode( ', ', array_keys( $out ) );
	}

	/** Models are inconsistent about shapes — coerce everything to what the builder expects. */
	private static function normalise_outline( array $outline, array $topic, array $meta ) {
		$title = trim( (string) ( $outline['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) $topic['title'];
		}

		$sections_raw = $outline['sections'] ?? array();
		$sections     = array();

		foreach ( (array) $sections_raw as $section ) {
			if ( is_string( $section ) ) {
				$section = array( 'h2' => $section );
			}
			if ( ! is_array( $section ) || empty( $section['h2'] ) ) {
				continue;
			}
			$sections[] = array(
				'h2'       => LAA_Article_Builder::normalise_title( sanitize_text_field( $section['h2'] ) ),
				'intent'   => sanitize_textarea_field( $section['intent'] ?? '' ),
				'h3'       => array_values(
					array_filter(
						array_map(
							function ( $h3 ) {
								return LAA_Article_Builder::normalise_title( sanitize_text_field( $h3 ) );
							},
							(array) ( $section['h3'] ?? array() )
						)
					)
				),
				'elements' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $section['elements'] ?? array() ) ) ) ),
				'html'     => '',
			);
		}

		// The model routinely puts "quote" in six sections out of six however
		// firmly the prompt says "exactly one" (seen live: 6 quotes in one
		// article). Capping it here is deterministic; the prompt isn't.
		$sections = self::cap_element( $sections, 'quote', $meta['require_quote'] ? 1 : 0 );
		$sections = self::cap_element( $sections, 'table', $meta['require_table'] ? 2 : 0 );

		// Respect the ceiling even when the model overshoots — the word budget
		// is divided by the section count, so 14 sections would give stubs.
		if ( count( $sections ) > (int) $meta['sections_max'] ) {
			$sections = array_slice( $sections, 0, (int) $meta['sections_max'] );
		}

		$faq = array();
		foreach ( (array) ( $outline['faq'] ?? array() ) as $item ) {
			if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
				continue;
			}
			$faq[] = array(
				// Becomes an <h3>, so it gets the same ceiling as any heading.
				'q' => LAA_Article_Builder::normalise_title( sanitize_text_field( $item['q'] ) ),
				'a' => sanitize_textarea_field( $item['a'] ),
			);
		}

		return array(
			'title'              => LAA_Article_Builder::normalise_title( $title ),
			'slug'               => sanitize_title( $outline['slug'] ?? '' ),
			'meta_title'         => LAA_Article_Builder::normalise_title( sanitize_text_field( $outline['meta_title'] ?? $title ) ),
			'meta_description'   => sanitize_text_field( $outline['meta_description'] ?? '' ),
			'lead'               => sanitize_textarea_field( $outline['lead'] ?? '' ),
			'takeaways'          => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $outline['takeaways'] ?? array() ) ) ) ),
			'primary_keyword'    => sanitize_text_field( $outline['primary_keyword'] ?? '' ),
			'secondary_keywords' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $outline['secondary_keywords'] ?? array() ) ) ) ),
			'sections'           => $sections,
			'faq'                => $meta['require_faq'] ? $faq : array(),
			'tags'               => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $outline['tags'] ?? array() ) ) ) ),
			'title_notes'        => array(),
		);
	}

	/**
	 * Keeps at most $limit sections carrying $element, dropping it from the
	 * rest. The middle of the article is where a pull-quote actually works,
	 * so the kept one is picked from there rather than from the first section.
	 */
	private static function cap_element( array $sections, $element, $limit ) {
		$carrying = array();
		foreach ( $sections as $i => $section ) {
			if ( in_array( $element, $section['elements'], true ) ) {
				$carrying[] = $i;
			}
		}

		if ( count( $carrying ) <= $limit ) {
			return $sections;
		}

		$keep = array();
		if ( $limit > 0 ) {
			$middle = (int) floor( ( count( $carrying ) - 1 ) / 2 );
			$keep[] = $carrying[ $middle ];
			for ( $n = 1; count( $keep ) < $limit; $n++ ) {
				if ( isset( $carrying[ $middle + $n ] ) ) {
					$keep[] = $carrying[ $middle + $n ];
				} elseif ( isset( $carrying[ $middle - $n ] ) ) {
					$keep[] = $carrying[ $middle - $n ];
				} else {
					break;
				}
			}
		}

		foreach ( $carrying as $i ) {
			if ( in_array( $i, $keep, true ) ) {
				continue;
			}
			$sections[ $i ]['elements'] = array_values( array_diff( $sections[ $i ]['elements'], array( $element ) ) );
		}

		return $sections;
	}

	private static function fail( $error ) {
		return array(
			'ok'      => false,
			'article' => array(),
			'error'   => $error,
		);
	}
}

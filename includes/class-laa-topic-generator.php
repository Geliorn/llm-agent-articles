<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step 1 of the pipeline: a plan's theme -> a list of concrete article
 * topics, each with its keywords and angle. Writes them into the queue as
 * `pending` (or `approved`, if the plan says so) — never publishes anything.
 */
class LAA_Topic_Generator {

	const SYSTEM_PROMPT = <<<'PROMPT'
Ты редактор-стратег: составляешь контент-план для сайта. Твоя задача — предложить
конкретные темы статей по заданному направлению.

Верни СТРОГО JSON-объект (без markdown-обёртки, без пояснений вокруг):
{
  "topics": [
    {
      "title": "Заголовок статьи — как он будет стоять на сайте",
      "keywords": "2-5 поисковых фраз через запятую, по которым эту статью будут искать",
      "angle": "1-2 предложения: что именно раскрыть и чем эта статья отличается от соседних"
    }
  ]
}

Требования к темам:
— Каждая тема отвечает на ОДИН конкретный вопрос читателя, а не на «всё о предмете».
— Заголовок звучит так, как человек формулирует задачу или вопрос, а не как раздел учебника.
— Темы не пересекаются между собой: если две темы раскрываются одним и тем же текстом — оставь одну.
— Идёшь от частого и практичного к более узкому.
— Без эмодзи, без штампов («всё о», «полное руководство», «топ лучших», «секреты», «простыми словами»).
— Заголовок до 70 символов.
— keywords — реальные поисковые формулировки, в нижнем регистре, без «купить» и прочей коммерции,
  если направление не коммерческое.
— Язык: русский. Термины — в принятом в индустрии написании (RAG, MCP, LLM, fine-tuning).
PROMPT;

	/**
	 * @param int      $plan_id
	 * @param int|null $count   Override the plan's batch size.
	 * @return array{ok:bool,added:int,duplicates:int,error:string}
	 */
	public static function generate( $plan_id, $count = null ) {
		$plan = get_post( $plan_id );
		if ( ! $plan || LAA_CPT::PLAN !== $plan->post_type ) {
			return self::fail( 'Контент-план не найден' );
		}

		$meta  = LAA_Meta_Fields::get_plan_settings( $plan_id );
		$count = $count ? (int) $count : (int) $meta['topics_batch'];
		$count = max( 1, min( 60, $count ) );

		if ( ! LAA_Settings::llm_ready( $meta['llm_provider'] ) ) {
			return self::fail( 'Не заданы ключи доступа к модели — проверьте настройки' );
		}

		$client = new LAA_LLM_Client( $meta['llm_provider'] );
		$parsed = $client->json( self::SYSTEM_PROMPT, self::build_user_prompt( $plan, $meta, $count ), 4000, (float) LAA_Settings::get( 'llm_temperature_plan', 0.7 ) );

		if ( null === $parsed ) {
			$error = $client->last_error() ?: 'модель не ответила';
			LAA_Log::error( "План «{$plan->post_title}»: не удалось получить темы — {$error}" );
			return self::fail( $error );
		}

		$topics = $parsed['topics'] ?? ( isset( $parsed[0] ) ? $parsed : array() );
		if ( ! is_array( $topics ) || empty( $topics ) ) {
			return self::fail( 'Модель вернула пустой список тем' );
		}

		$status = $meta['topics_autoapprove'] ? LAA_Topics::APPROVED : LAA_Topics::PENDING;
		$rows   = array();

		foreach ( $topics as $topic ) {
			if ( is_string( $topic ) ) {
				$topic = array( 'title' => $topic );
			}
			if ( ! is_array( $topic ) || empty( $topic['title'] ) ) {
				continue;
			}
			$rows[] = array(
				'plan_id'  => $plan_id,
				'title'    => LAA_Article_Builder::normalise_title( sanitize_text_field( $topic['title'] ) ),
				'keywords' => sanitize_text_field( $topic['keywords'] ?? '' ),
				'angle'    => sanitize_textarea_field( $topic['angle'] ?? '' ),
				'status'   => $status,
				'source'   => 'llm',
			);
		}

		$result = LAA_Topics::add_many( $rows );
		update_post_meta( $plan_id, '_laa_last_topics_gen_at', time() );

		LAA_Log::add(
			sprintf(
				'План «%s»: предложено тем %d, добавлено %d, дубликатов %d%s',
				$plan->post_title,
				count( $rows ),
				$result['added'],
				$result['duplicates'],
				LAA_Topics::APPROVED === $status ? ' (автоодобрение)' : ''
			)
		);

		return array(
			'ok'         => true,
			'added'      => $result['added'],
			'duplicates' => $result['duplicates'],
			'error'      => '',
		);
	}

	private static function build_user_prompt( WP_Post $plan, array $meta, $count ) {
		$existing = LAA_Topics::existing_titles( $plan->ID, 200 );

		$parts = array();
		$parts[] = 'Направление: ' . ( $meta['theme'] ?: $plan->post_title );
		if ( $meta['audience'] ) {
			$parts[] = 'Аудитория: ' . $meta['audience'];
		}
		if ( $meta['tone'] ) {
			$parts[] = 'Тон изложения: ' . $meta['tone'];
		}
		$parts[] = 'Сколько тем нужно: ' . $count;

		if ( ! empty( $existing ) ) {
			$parts[] = "Эти темы уже есть в плане — не предлагай их и не предлагай их перефразировки:\n— " . implode( "\n— ", array_slice( $existing, 0, 200 ) );
		}
		if ( $meta['extra_prompt'] ) {
			$parts[] = 'Дополнительные указания: ' . $meta['extra_prompt'];
		}

		return implode( "\n\n", $parts );
	}

	private static function fail( $error ) {
		return array(
			'ok'         => false,
			'added'      => 0,
			'duplicates' => 0,
			'error'      => $error,
		);
	}
}

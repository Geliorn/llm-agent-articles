<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a finished article plan into N short theses for the infographic.
 *
 * A separate call rather than another field in the outline JSON: the outline
 * prompt is already long and every extra required field makes the model more
 * likely to drop or mangle one of them. This one is small, cheap and only
 * runs when the feature is on.
 */
class LAA_Theses_Generator {

	const SYSTEM_PROMPT = <<<'PROMPT'
Ты редактор. Из плана статьи делаешь тезисы для инфографики.

Верни СТРОГО JSON-объект (без markdown-обёртки, без пояснений вокруг):
{
  "points": [
    {"label": "1-3 слова — о чём пункт", "text": "законченная мысль одним предложением"}
  ]
}

Правила:
— Ровно столько пунктов, сколько попрошено. Не больше и не меньше.
— Каждый пункт — отдельная мысль. Пункты не пересекаются и не пересказывают друг друга.
— text — законченное предложение, без двоеточия на конце и без отсылок «см. выше»,
  «как уже говорилось». Пункт должен быть понятен сам по себе, вне статьи.
— Жёстко соблюдай предел длины: текст, который не влезет, придётся переписывать заново.
— label — существительное или короткая именная группа, не предложение. Без точки.
— Пункты идут в логическом порядке: от первого шага к последнему, или от главного к частному.
— Без эмодзи, без штампов, без восклицательных знаков.
— Не выдумывай цифры, названия и факты, которых нет в плане статьи.
— Язык: русский.
PROMPT;

	/**
	 * @param LAA_LLM_Client $client
	 * @param array          $article Normalised outline.
	 * @param int            $count
	 * @param int            $max_chars Character budget per thesis.
	 * @param bool           $with_labels
	 * @return array{ok:bool,points:array,error:string}
	 */
	public static function generate( LAA_LLM_Client $client, array $article, $count, $max_chars, $with_labels = true ) {
		$count     = max( 3, min( 12, (int) $count ) );
		$max_chars = max( 40, (int) $max_chars );

		$parsed = $client->json(
			self::SYSTEM_PROMPT,
			self::build_prompt( $article, $count, $max_chars, $with_labels ),
			1600,
			0.5
		);

		if ( ! is_array( $parsed ) ) {
			return array(
				'ok'     => false,
				'points' => array(),
				'error'  => $client->last_error() ?: 'модель не вернула тезисы',
			);
		}

		$raw    = $parsed['points'] ?? ( isset( $parsed[0] ) ? $parsed : array() );
		$points = array();

		foreach ( (array) $raw as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'text' => $item );
			}
			if ( ! is_array( $item ) || empty( $item['text'] ) ) {
				continue;
			}
			$points[] = array(
				'label' => $with_labels ? LAA_Article_Builder::normalise_title( $item['label'] ?? '' ) : '',
				'text'  => LAA_Article_Builder::normalise_title( $item['text'] ),
			);
		}

		if ( count( $points ) < 3 ) {
			return array(
				'ok'     => false,
				'points' => $points,
				'error'  => sprintf( 'Модель вернула %d пунктов — для инфографики мало', count( $points ) ),
			);
		}

		// More than asked is harmless to trim — these are independent points,
		// not a text being cut.
		if ( count( $points ) > $count ) {
			$points = array_slice( $points, 0, $count );
		}

		return array(
			'ok'     => true,
			'points' => $points,
			'error'  => '',
		);
	}

	private static function build_prompt( array $article, $count, $max_chars, $with_labels ) {
		$sections = array();
		foreach ( (array) $article['sections'] as $section ) {
			$sections[] = $section['h2'];
		}

		$parts   = array();
		$parts[] = 'Статья: ' . $article['title'];
		if ( ! empty( $article['lead'] ) ) {
			$parts[] = 'Лид: ' . $article['lead'];
		}
		if ( ! empty( $article['takeaways'] ) ) {
			$parts[] = "Тезисы из статьи:\n— " . implode( "\n— ", $article['takeaways'] );
		}
		if ( $sections ) {
			$parts[] = "Разделы статьи:\n— " . implode( "\n— ", $sections );
		}

		$parts[] = sprintf( 'Нужно пунктов: %d.', $count );
		$parts[] = sprintf( 'Предел длины text: %d символов. Это жёсткий предел — считай символы.', $max_chars );
		$parts[] = $with_labels
			? 'label заполняй: 1-3 слова.'
			: 'label оставляй пустой строкой — он не используется.';

		return implode( "\n\n", $parts );
	}
}

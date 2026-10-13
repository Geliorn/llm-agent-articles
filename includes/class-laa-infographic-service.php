<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates one infographic: theses -> layout -> attachment.
 *
 * The interesting part is what happens when a thesis doesn't fit its cell.
 * We never cut the text — same rule as for titles. Instead:
 *   1) ask the model to rewrite the offending theses inside the measured
 *      character budget, then re-render;
 *   2) if something still overflows, drop whole points (the longest first)
 *      until it fits — a missing point is honest, half a sentence is not;
 *   3) below the minimum number of points, give up and skip the infographic.
 */
class LAA_Infographic_Service {

	const MIN_POINTS = 3;

	/**
	 * @return array{ok:bool,attachment_id:int,error:string,notes:array,points:array}
	 */
	public static function create_for_post( $post_id, array $article, array $meta ) {
		$preset_id = $meta['infographic_preset'] ?: LAA_Settings::get( 'infographic_default_preset', 'grid' );
		$preset    = LAA_Infographic_Presets::get( $preset_id );

		$count = (int) $meta['infographic_points'];
		if ( $count < self::MIN_POINTS ) {
			$count = 'list' === $preset['layout'] ? 8 : 6;
		}

		$notes = array();

		$budget = self::char_budget( $preset, $count );
		$client = new LAA_LLM_Client( $meta['llm_provider'] );

		$generated = LAA_Theses_Generator::generate(
			$client,
			$article,
			$count,
			$budget,
			(bool) $preset['label_enabled']
		);

		if ( ! $generated['ok'] ) {
			return self::result( false, 0, 'Тезисы: ' . $generated['error'], $notes );
		}

		$points   = $generated['points'];
		$rendered = self::render_fitting( $client, $preset, $points, $article['title'], $notes );

		if ( ! $rendered['ok'] ) {
			return self::result( false, 0, $rendered['error'], $notes );
		}

		$bytes = (string) file_get_contents( $rendered['path'] ); // phpcs:ignore
		@unlink( $rendered['path'] ); // phpcs:ignore

		$alt = self::build_alt( $article['title'], $rendered['points'] );

		$attachment_id = LAA_Image_Service::sideload_bytes(
			$bytes,
			$post_id,
			$alt,
			LAA_Image_Composer::extension_for( $preset['format'] ),
			$rendered['mime']
		);

		if ( ! $attachment_id ) {
			return self::result( false, 0, 'Не удалось сохранить инфографику в медиабиблиотеку', $notes );
		}

		return array(
			'ok'            => true,
			'attachment_id' => (int) $attachment_id,
			'error'         => '',
			'notes'         => $notes,
			'points'        => $rendered['points'],
		);
	}

	/**
	 * Renders, and if anything overflows, fixes the content rather than the
	 * picture. Returns the points actually drawn, so the caller can reuse the
	 * same wording for the alt text and the caption.
	 */
	public static function render_fitting( LAA_LLM_Client $client, array $preset, array $points, $title, array &$notes ) {
		$background = null;
		if ( 'library' === $preset['background_mode'] ) {
			$source     = LAA_Image_Service::pick_source( $title );
			$background = $source['path'] ?: null;
			if ( ! $background ) {
				$notes[] = 'Пул картинок пуст — инфографика собрана на сплошном фоне';
			}
		}

		$tmp    = LAA_Image_Service::temp_path( LAA_Image_Composer::extension_for( $preset['format'] ) );
		$result = LAA_Infographic_Composer::compose( $preset, $points, $title, $background, $tmp );

		if ( ! $result['ok'] ) {
			return array(
				'ok'     => false,
				'error'  => $result['error'],
				'points' => $points,
			);
		}
		if ( empty( $result['overflow'] ) ) {
			return array(
				'ok'     => true,
				'path'   => $result['path'],
				'mime'   => $result['mime'],
				'points' => $points,
			);
		}

		// Pass 1 — rewrite the offending theses shorter, keeping the meaning.
		$rewritten = 0;
		foreach ( $result['overflow'] as $item ) {
			$index = (int) $item['index'];
			if ( ! isset( $points[ $index ] ) ) {
				continue;
			}
			$short = LAA_Title_Shortener::shorten( $client, $points[ $index ]['text'], (int) $item['fits_chars'] );
			if ( $short['ok'] ) {
				$points[ $index ]['text'] = $short['title'];
				++$rewritten;
			}
		}
		if ( $rewritten ) {
			$notes[] = sprintf( 'Переписано тезисов, чтобы влезли в блок: %d', $rewritten );
		}

		$result = LAA_Infographic_Composer::compose( $preset, $points, $title, $background, $tmp );
		if ( $result['ok'] && empty( $result['overflow'] ) ) {
			return array(
				'ok'     => true,
				'path'   => $result['path'],
				'mime'   => $result['mime'],
				'points' => $points,
			);
		}

		// Pass 2 — drop whole points, longest first. A missing point is honest;
		// a sentence cut in half is not.
		$dropped = 0;
		while ( count( $points ) > self::MIN_POINTS ) {
			$longest = 0;
			foreach ( $points as $i => $point ) {
				if ( mb_strlen( $point['text'] ) > mb_strlen( $points[ $longest ]['text'] ) ) {
					$longest = $i;
				}
			}
			array_splice( $points, $longest, 1 );
			++$dropped;

			$result = LAA_Infographic_Composer::compose( $preset, $points, $title, $background, $tmp );
			if ( $result['ok'] && empty( $result['overflow'] ) ) {
				$notes[] = sprintf( 'Убрано пунктов, не поместившихся в макет: %d', $dropped );
				return array(
					'ok'     => true,
					'path'   => $result['path'],
					'mime'   => $result['mime'],
					'points' => $points,
				);
			}
		}

		@unlink( $tmp ); // phpcs:ignore

		return array(
			'ok'     => false,
			'error'  => 'Тезисы не помещаются в макет даже после сокращения — проверьте пресет (поля, число колонок, минимальный кегль)',
			'points' => $points,
		);
	}

	/**
	 * Character budget per thesis, derived from the preset rather than guessed:
	 * the model is told this number, so it has to match what actually fits.
	 */
	public static function char_budget( array $preset, $count ) {
		$columns = 'list' === $preset['layout'] ? 1 : max( 1, (int) $preset['columns'] );
		$rows    = (int) ceil( $count / $columns );

		$padding = (int) $preset['padding'];
		$area_w  = (int) $preset['width'] - $padding * 2;
		$area_h  = (int) $preset['height'] - $padding * 2;

		// Rough allowances for the heading and footer — this is a prompt hint,
		// not the layout itself, which measures for real.
		if ( $preset['title_enabled'] ) {
			$area_h -= (int) round( $preset['title_size'] * 1.2 * 2 ) + (int) $preset['title_gap'];
		}
		if ( $preset['footer_enabled'] ) {
			$area_h -= (int) round( $preset['footer_size'] * 1.3 ) + (int) $preset['footer_gap'];
		}

		$cell_w = (int) floor( ( $area_w - (int) $preset['gap_x'] * ( $columns - 1 ) ) / $columns );
		$cell_h = (int) floor( ( $area_h - (int) $preset['gap_y'] * ( $rows - 1 ) ) / max( 1, $rows ) );

		$inner_pad = $preset['plate_enabled'] ? (int) $preset['plate_padding'] : 0;
		$text_w    = $cell_w - $inner_pad * 2;
		$text_h    = $cell_h - $inner_pad * 2;

		$size = (int) $preset['text_size'];
		if ( 'list' === $preset['layout'] ) {
			$text_w -= (int) $preset['badge_size'] + (int) $preset['badge_gap'];
		} else {
			$text_h -= (int) $preset['badge_size'] + (int) $preset['badge_gap'];
			if ( $preset['label_enabled'] ) {
				$text_h -= (int) round( $preset['label_size'] * 1.2 ) + (int) $preset['label_gap'];
			}
		}

		$lines = max( 1, (int) floor( $text_h / max( 1, $size * (float) $preset['text_line_height'] ) ) );

		// ~0.52em per Cyrillic character at this font — close enough for a hint.
		$chars_per_line = max( 8, (int) floor( $text_w / max( 1, $size * 0.52 ) ) );

		return max( 40, (int) floor( $chars_per_line * $lines * 0.9 ) );
	}

	private static function build_alt( $title, array $points ) {
		$texts = array();
		foreach ( $points as $point ) {
			$texts[] = $point['text'];
		}
		return 'Инфографика: ' . $title . '. ' . implode( ' ', $texts );
	}

	private static function result( $ok, $attachment_id, $error, array $notes, array $points = array() ) {
		return array(
			'ok'            => (bool) $ok,
			'attachment_id' => (int) $attachment_id,
			'error'         => $error,
			'notes'         => $notes,
			'points'        => $points,
		);
	}
}

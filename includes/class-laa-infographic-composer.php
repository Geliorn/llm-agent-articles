<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws a simple infographic: a heading, N numbered items laid out either as
 * a grid of blocks or as a vertical list, and an optional footer.
 *
 * Extends LAA_Image_Composer to reuse its background pipeline (crop, filters,
 * tint, gradient, PNG mask, logo), its shape and text primitives and its file
 * output. Only the layout is new.
 *
 * The one rule that shapes the whole thing: every item shares a single font
 * size. Per-item autofit would be easy and would look broken — six blocks in
 * six different sizes read as a bug, not as a design.
 */
class LAA_Infographic_Composer extends LAA_Image_Composer {

	/** Below this the text stops being readable at any sane viewing size. */
	const ABSOLUTE_MIN_TEXT_SIZE = 11;

	/**
	 * @param array       $preset
	 * @param array       $items           [ ['label' => string, 'text' => string], ... ]
	 * @param string      $title           Heading; '' to skip.
	 * @param string|null $background_path Library image, when the preset asks for one.
	 * @param string      $out_path
	 * @return array{ok:bool,path:string,mime:string,error:string,overflow:array,text_size:int}
	 *
	 * Named compose() rather than render(): the parent's render() takes a
	 * single caption string, and PHP requires an override to keep the
	 * signature — this one needs the item list instead.
	 */
	public static function compose( array $preset, array $items, $title, $background_path, $out_path ) {
		if ( ! self::available() ) {
			return self::result( false, '', '', 'Расширение GD недоступно' );
		}
		if ( ! self::text_available() ) {
			return self::result( false, '', '', 'GD собран без FreeType — текст на картинку нанести нельзя' );
		}

		$preset = wp_parse_args( $preset, LAA_Infographic_Presets::schema() );
		$items  = self::normalise_items( $items );

		if ( empty( $items ) ) {
			return self::result( false, '', '', 'Нет ни одного тезиса для инфографики' );
		}

		$font = self::resolve_font( array( 'caption_font' => $preset['font'] ) );
		if ( '' === $font ) {
			return self::result( false, '', '', 'Не найден TTF-шрифт — загрузите его на экране «Обложки»' );
		}

		$width  = (int) $preset['width'];
		$height = (int) $preset['height'];

		$canvas = self::new_canvas( $width, $height, $preset['background'] );
		self::draw_background( $canvas, $preset, $background_path );

		$padding = (int) $preset['padding'];
		$area    = array(
			'x' => $padding,
			'y' => $padding,
			'w' => $width - $padding * 2,
			'h' => $height - $padding * 2,
		);

		if ( $area['w'] < 100 || $area['h'] < 100 ) {
			imagedestroy( $canvas );
			return self::result( false, '', '', 'Поля съели всё полотно — уменьшите отступ' );
		}

		// Heading and footer claim their space first; the items get the rest.
		$area = self::draw_heading( $canvas, $preset, $font, $title, $area );
		$area = self::reserve_footer( $canvas, $preset, $font, $area );

		$fit = self::fit_items( $preset, $font, $items, $area );
		if ( ! $fit['ok'] && empty( $fit['cells'] ) ) {
			imagedestroy( $canvas );
			return self::result( false, '', '', $fit['error'] );
		}

		self::draw_items( $canvas, $preset, $font, $items, $fit );
		self::draw_footer( $canvas, $preset, $font );
		self::apply_logo( $canvas, $preset );

		$saved = self::save( $canvas, $preset, $out_path );
		imagedestroy( $canvas );

		if ( ! $saved['ok'] ) {
			return self::result( false, '', '', $saved['error'] );
		}

		return self::result( true, $saved['path'], $saved['mime'], '', $fit['overflow'], $fit['text_size'] );
	}

	// ----------------------------------------------------------- background

	protected static function draw_background( $canvas, array $preset, $background_path ) {
		if ( 'library' === $preset['background_mode'] && $background_path && is_readable( $background_path ) ) {
			$source = @imagecreatefromstring( (string) file_get_contents( $background_path ) ); // phpcs:ignore
			if ( $source ) {
				self::draw_source( $canvas, $source, $preset );
				imagedestroy( $source );
				self::apply_filters( $canvas, $preset );
			}
		}

		self::apply_tint( $canvas, $preset );
		self::apply_gradient( $canvas, $preset );
		self::apply_mask( $canvas, $preset );
	}

	// -------------------------------------------------------------- heading

	/** Draws the title and returns the area left for the items. */
	protected static function draw_heading( $canvas, array $preset, $font, $title, array $area ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		if ( ! $preset['title_enabled'] || '' === $title ) {
			return $area;
		}

		$size      = (int) $preset['title_size'];
		$min       = (int) $preset['title_min_size'];
		$max_lines = (int) $preset['title_max_lines'];

		$lines = self::wrap( $font, $size, $title, $area['w'] );
		while ( count( $lines ) > $max_lines && $size > $min ) {
			$size -= 2;
			$lines = self::wrap( $font, $size, $title, $area['w'] );
		}
		if ( count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, 0, $max_lines );
		}

		$line_height = (int) round( $size * (float) $preset['title_line_height'] );
		$color       = self::colour( $canvas, $preset['title_color'], '#ffffff' );

		self::draw_lines( $canvas, $lines, $font, $size, $line_height, $area['x'], $area['y'], $area['w'], $color, $preset['title_align'] );

		$used = $line_height * count( $lines ) + (int) $preset['title_gap'];

		return array(
			'x' => $area['x'],
			'y' => $area['y'] + $used,
			'w' => $area['w'],
			'h' => $area['h'] - $used,
		);
	}

	// --------------------------------------------------------------- footer

	protected static function footer_text( array $preset ) {
		$text = trim( (string) $preset['footer_text'] );
		if ( '' === $text ) {
			// Sensible default: the site this runs on.
			$text = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return $text;
	}

	protected static function reserve_footer( $canvas, array $preset, $font, array $area ) {
		if ( ! $preset['footer_enabled'] || '' === self::footer_text( $preset ) ) {
			return $area;
		}

		$used = (int) round( (int) $preset['footer_size'] * 1.3 ) + (int) $preset['footer_gap'];

		// Remembered so draw_footer() can place it without recomputing the area.
		self::$footer_box = array(
			'x' => $area['x'],
			'y' => $area['y'] + $area['h'] - $used + (int) $preset['footer_gap'],
			'w' => $area['w'],
		);

		return array(
			'x' => $area['x'],
			'y' => $area['y'],
			'w' => $area['w'],
			'h' => $area['h'] - $used,
		);
	}

	/** @var array|null */
	protected static $footer_box = null;

	protected static function draw_footer( $canvas, array $preset, $font ) {
		if ( ! self::$footer_box ) {
			return;
		}

		$text = self::footer_text( $preset );
		$size = (int) $preset['footer_size'];
		$box  = self::$footer_box;

		self::draw_lines(
			$canvas,
			array( $text ),
			$font,
			$size,
			(int) round( $size * 1.3 ),
			$box['x'],
			$box['y'],
			$box['w'],
			self::colour( $canvas, $preset['footer_color'], '#8b9ab4' ),
			$preset['footer_align']
		);

		self::$footer_box = null;
	}

	// ------------------------------------------------------------ item grid

	/**
	 * Finds the one font size at which every item fits its cell, and returns
	 * the computed cells. Items that don't fit even at the minimum are listed
	 * in `overflow` so the caller can ask the model to shorten them rather
	 * than silently cutting the text.
	 */
	protected static function fit_items( array $preset, $font, array $items, array $area ) {
		$count   = count( $items );
		$columns = max( 1, min( (int) $preset['columns'], $count ) );
		$rows    = (int) ceil( $count / $columns );

		$gap_x = (int) $preset['gap_x'];
		$gap_y = (int) $preset['gap_y'];

		$cell_w = (int) floor( ( $area['w'] - $gap_x * ( $columns - 1 ) ) / $columns );
		$cell_h = (int) floor( ( $area['h'] - $gap_y * ( $rows - 1 ) ) / $rows );

		if ( $cell_w < 80 || $cell_h < 50 ) {
			return array(
				'ok'       => false,
				'cells'    => array(),
				'error'    => sprintf( 'Ячейка получилась %d×%d — слишком мало. Уменьшите число пунктов, поля или зазоры.', $cell_w, $cell_h ),
				'overflow' => array(),
				'text_size' => 0,
			);
		}

		$inner_pad = $preset['plate_enabled'] ? (int) $preset['plate_padding'] : 0;
		$inner_w   = $cell_w - $inner_pad * 2;
		$inner_h   = $cell_h - $inner_pad * 2;

		$is_list  = 'list' === $preset['layout'];
		$max_size = (int) $preset['text_size'];
		$min_size = max( self::ABSOLUTE_MIN_TEXT_SIZE, (int) $preset['text_min_size'] );
		$base     = max( 1, (int) $preset['text_size'] );

		$chosen   = 0;
		$measured = array();
		$metrics  = array();

		// The badge scales with the text, so it has to be recomputed per
		// candidate size — a fixed 38px circle above the text eats a third of a
		// short cell and makes layouts fail that would otherwise fit.
		for ( $size = $max_size; $size >= $min_size; $size-- ) {
			$ratio = $size / $base;

			$badge_size = 'none' === $preset['badge_style'] ? 0 : max( 14, (int) round( (int) $preset['badge_size'] * $ratio ) );
			$badge_gap  = 0 === $badge_size ? 0 : max( 5, (int) round( (int) $preset['badge_gap'] * $ratio ) );

			// In a list the badge sits left of the text; in a grid the cells are
			// too narrow for that, so it goes above.
			$text_w = $is_list ? $inner_w - $badge_size - $badge_gap : $inner_w;
			$head_h = $is_list ? 0 : ( $badge_size ? $badge_size + $badge_gap : 0 );

			if ( $text_w < 60 ) {
				continue;
			}

			$candidate = self::measure_all( $preset, $font, $items, $size, $text_w );

			$needed = 0;
			foreach ( $candidate as $m ) {
				$needed = max( $needed, $m['height'] );
			}

			$metrics  = compact( 'badge_size', 'badge_gap', 'text_w', 'head_h' );
			$measured = $candidate;
			$chosen   = $size;

			if ( $head_h + $needed <= $inner_h ) {
				break;
			}
		}

		if ( ! $chosen ) {
			return array(
				'ok'        => false,
				'cells'     => array(),
				'error'     => 'Под текст в ячейке осталось меньше 60 px — уменьшите маркер, число колонок или поля.',
				'overflow'  => array(),
				'text_size' => 0,
			);
		}

		$overflow = array();
		foreach ( $measured as $i => $m ) {
			if ( $metrics['head_h'] + $m['height'] > $inner_h ) {
				$overflow[] = array(
					'index'      => $i,
					'text'       => $items[ $i ]['text'],
					'fits_chars' => self::estimate_fitting_chars( $font, $chosen, $metrics['text_w'], $inner_h - $metrics['head_h'], $preset, $items[ $i ] ),
				);
			}
		}

		$cells = array();
		foreach ( $items as $i => $item ) {
			$col = $i % $columns;
			$row = (int) floor( $i / $columns );

			$cells[] = array(
				'x'      => $area['x'] + $col * ( $cell_w + $gap_x ),
				'y'      => $area['y'] + $row * ( $cell_h + $gap_y ),
				'w'      => $cell_w,
				'h'      => $cell_h,
				'inner'  => $inner_pad,
				'text_w' => $metrics['text_w'],
				'head_h' => $metrics['head_h'],
				'lines'  => $measured[ $i ]['lines'],
				'label'  => $measured[ $i ]['label_lines'],
			);
		}

		return array(
			'ok'         => empty( $overflow ),
			'cells'      => $cells,
			'error'      => '',
			'overflow'   => $overflow,
			'text_size'  => $chosen,
			'is_list'    => $is_list,
			'badge_size' => $metrics['badge_size'],
			'badge_gap'  => $metrics['badge_gap'],
		);
	}

	/** Wraps every item at one size and returns line sets plus the height each needs. */
	protected static function measure_all( array $preset, $font, array $items, $size, $text_w ) {
		$ratio       = (int) $preset['text_size'] > 0 ? $size / (int) $preset['text_size'] : 1;
		$label_size  = max( 8, (int) round( (int) $preset['label_size'] * $ratio ) );
		$line_height = (int) round( $size * (float) $preset['text_line_height'] );
		$label_lh    = (int) round( $label_size * 1.2 );

		$out = array();
		foreach ( $items as $item ) {
			$label_lines = array();
			$label_h     = 0;

			if ( $preset['label_enabled'] && '' !== $item['label'] ) {
				$label_lines = self::wrap( $font, $label_size, $item['label'], $text_w );
				$label_h     = $label_lh * count( $label_lines ) + (int) $preset['label_gap'];
			}

			$lines = self::wrap( $font, $size, $item['text'], $text_w );

			$out[] = array(
				'lines'       => $lines,
				'label_lines' => $label_lines,
				'height'      => $label_h + $line_height * count( $lines ),
			);
		}

		return $out;
	}

	/** Rough character budget for an item that overflowed — fed to the shortener. */
	protected static function estimate_fitting_chars( $font, $size, $text_w, $available_h, array $preset, array $item ) {
		$line_height = max( 1, (int) round( $size * (float) $preset['text_line_height'] ) );
		$lines       = max( 1, (int) floor( $available_h / $line_height ) );

		// Measure a representative string rather than guessing an average glyph
		// width: Cyrillic at the same point size is noticeably wider than Latin.
		$sample = 'строка обычного текста в тезисе';
		$per_px = mb_strlen( $sample ) / max( 1, self::text_width( $font, $size, $sample ) );

		$budget = (int) floor( $text_w * $per_px * $lines * 0.92 );

		if ( $preset['label_enabled'] && '' !== $item['label'] ) {
			$budget -= mb_strlen( $item['label'] );
		}

		return max( 30, $budget );
	}

	protected static function draw_items( $canvas, array $preset, $font, array $items, array $fit ) {
		$size        = (int) $fit['text_size'];
		$ratio       = (int) $preset['text_size'] > 0 ? $size / (int) $preset['text_size'] : 1;
		$label_size  = max( 8, (int) round( (int) $preset['label_size'] * $ratio ) );
		$line_height = (int) round( $size * (float) $preset['text_line_height'] );
		$label_lh    = (int) round( $label_size * 1.2 );

		$text_color  = self::colour( $canvas, $preset['text_color'], '#c7d2e4' );
		$label_color = self::colour( $canvas, $preset['label_color'], '#ffffff' );

		foreach ( $fit['cells'] as $i => $cell ) {
			if ( $preset['plate_enabled'] ) {
				self::rounded_rect(
					$canvas,
					$cell['x'],
					$cell['y'],
					$cell['x'] + $cell['w'],
					$cell['y'] + $cell['h'],
					(int) $preset['plate_radius'],
					LAA_Image_Presets::hex_to_rgb( $preset['plate_color'] ),
					(int) $preset['plate_alpha']
				);

				if ( (int) $preset['plate_border_width'] > 0 ) {
					self::stroke_rounded_rect(
						$canvas,
						$cell['x'],
						$cell['y'],
						$cell['x'] + $cell['w'],
						$cell['y'] + $cell['h'],
						(int) $preset['plate_radius'],
						(int) $preset['plate_border_width'],
						LAA_Image_Presets::hex_to_rgb( $preset['plate_border_color'] )
					);
				}
			}

			$x = $cell['x'] + $cell['inner'];
			$y = $cell['y'] + $cell['inner'];

			// Centre the content vertically: cells are sized by the tallest item,
			// so short ones would otherwise hang at the top with a hole under them.
			$content_h = $cell['head_h'];
			if ( ! empty( $cell['label'] ) ) {
				$content_h += $label_lh * count( $cell['label'] ) + (int) $preset['label_gap'];
			}
			$content_h += $line_height * count( $cell['lines'] );

			$slack = max( 0, ( $cell['h'] - $cell['inner'] * 2 ) - $content_h );
			$y    += (int) floor( $slack / 2 );

			if ( $fit['badge_size'] > 0 ) {
				self::draw_badge( $canvas, $preset, $font, $i + 1, $x, $y, $fit['badge_size'] );
			}

			$text_x = $fit['is_list'] ? $x + $fit['badge_size'] + $fit['badge_gap'] : $x;
			$text_y = $fit['is_list'] ? $y : $y + $cell['head_h'];

			if ( ! empty( $cell['label'] ) ) {
				self::draw_lines( $canvas, $cell['label'], $font, $label_size, $label_lh, $text_x, $text_y, $cell['text_w'], $label_color, 'left', (int) $preset['label_uppercase'] );
				$text_y += $label_lh * count( $cell['label'] ) + (int) $preset['label_gap'];
			}

			self::draw_lines( $canvas, $cell['lines'], $font, $size, $line_height, $text_x, $text_y, $cell['text_w'], $text_color, 'left' );
		}
	}

	protected static function draw_badge( $canvas, array $preset, $font, $number, $x, $y, $size ) {
		$style    = $preset['badge_style'];
		$bg       = LAA_Image_Presets::hex_to_rgb( $preset['badge_bg'], array( 59, 130, 246 ) );
		$fg       = self::colour( $canvas, $preset['badge_color'], '#ffffff' );

		if ( 'bullet' === $style ) {
			$dot = max( 6, (int) round( $size * 0.34 ) );
			$c   = imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 );
			imagealphablending( $canvas, true );
			imagefilledellipse( $canvas, $x + (int) round( $size / 2 ), $y + (int) round( $size / 2 ), $dot, $dot, $c );
			return;
		}

		if ( 'circle' === $style ) {
			$c = imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 );
			imagealphablending( $canvas, true );
			imagefilledellipse( $canvas, $x + (int) round( $size / 2 ), $y + (int) round( $size / 2 ), $size, $size, $c );
		} elseif ( 'square' === $style ) {
			self::rounded_rect( $canvas, $x, $y, $x + $size, $y + $size, (int) round( $size * 0.25 ), $bg, 100 );
		}

		$glyph_size = max( 8, (int) round( $size * ( 'plain' === $style ? 0.62 : 0.46 ) ) );
		$colour     = 'plain' === $style ? imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 ) : $fg;
		$label      = (string) $number;

		$box    = imagettfbbox( $glyph_size, 0, $font, $label );
		$text_w = $box ? abs( $box[2] - $box[0] ) : $glyph_size;
		// Centre on the digit's own ink, not on the font's full line box.
		$ink_top    = $box ? abs( $box[7] ) : $glyph_size;
		$ink_bottom = $box ? abs( $box[1] ) : 0;
		$ink_h      = $ink_top - $ink_bottom;

		$tx = $x + (int) round( ( $size - $text_w ) / 2 );
		$ty = $y + (int) round( ( $size + $ink_h ) / 2 );

		imagettftext( $canvas, $glyph_size, 0, $tx, $ty, $colour, $font, $label );
	}

	// -------------------------------------------------------------- helpers

	/** Draws wrapped lines from a top-left origin; returns the height used. */
	protected static function draw_lines( $canvas, array $lines, $font, $size, $line_height, $x, $y, $max_width, $color, $align = 'left', $uppercase = 0 ) {
		$metrics = imagettfbbox( $size, 0, $font, self::METRIC_SAMPLE );
		$ascent  = $metrics ? abs( $metrics[7] ) : (int) round( $size * 0.8 );

		imagealphablending( $canvas, true );

		foreach ( $lines as $i => $line ) {
			if ( $uppercase ) {
				$line = mb_strtoupper( $line );
			}

			$line_w = self::text_width( $font, $size, $line );

			switch ( $align ) {
				case 'center':
					$lx = $x + (int) round( ( $max_width - $line_w ) / 2 );
					break;
				case 'right':
					$lx = $x + ( $max_width - $line_w );
					break;
				default:
					$lx = $x;
			}

			imagettftext( $canvas, $size, 0, $lx, $y + $ascent + $i * $line_height, $color, $font, $line );
		}

		return $line_height * count( $lines );
	}

	/** Rounded-rect outline, drawn as nested rings so thickness stays inside. */
	protected static function stroke_rounded_rect( $canvas, $x1, $y1, $x2, $y2, $radius, $thickness, array $rgb ) {
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], 0 );
		imagealphablending( $canvas, true );

		for ( $i = 0; $i < $thickness; $i++ ) {
			$r = max( 0, $radius - $i );
			imagesetthickness( $canvas, 1 );
			// Straight segments plus corner arcs: cheaper and crisper than
			// filling two rounded rects and subtracting.
			imageline( $canvas, $x1 + $i + $r, $y1 + $i, $x2 - $i - $r, $y1 + $i, $color );
			imageline( $canvas, $x1 + $i + $r, $y2 - $i, $x2 - $i - $r, $y2 - $i, $color );
			imageline( $canvas, $x1 + $i, $y1 + $i + $r, $x1 + $i, $y2 - $i - $r, $color );
			imageline( $canvas, $x2 - $i, $y1 + $i + $r, $x2 - $i, $y2 - $i - $r, $color );

			if ( $r > 0 ) {
				$d = $r * 2;
				imagearc( $canvas, $x1 + $i + $r, $y1 + $i + $r, $d, $d, 180, 270, $color );
				imagearc( $canvas, $x2 - $i - $r, $y1 + $i + $r, $d, $d, 270, 360, $color );
				imagearc( $canvas, $x1 + $i + $r, $y2 - $i - $r, $d, $d, 90, 180, $color );
				imagearc( $canvas, $x2 - $i - $r, $y2 - $i - $r, $d, $d, 0, 90, $color );
			}
		}
	}

	protected static function colour( $canvas, $hex, $fallback ) {
		$rgb = LAA_Image_Presets::hex_to_rgb( $hex, LAA_Image_Presets::hex_to_rgb( $fallback ) );
		return imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], 0 );
	}

	protected static function normalise_items( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'text' => $item );
			}
			$text = trim( wp_strip_all_tags( (string) ( $item['text'] ?? '' ) ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'label' => trim( wp_strip_all_tags( (string) ( $item['label'] ?? '' ) ) ),
				'text'  => preg_replace( '/\s+/u', ' ', $text ),
			);
		}
		return $out;
	}

	protected static function result( $ok, $path, $mime, $error, $overflow = array(), $text_size = 0 ) {
		return array(
			'ok'        => (bool) $ok,
			'path'      => $path,
			'mime'      => $mime,
			'error'     => $error,
			'overflow'  => $overflow,
			'text_size' => (int) $text_size,
		);
	}
}

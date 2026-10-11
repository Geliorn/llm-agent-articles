<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Overlay presets: the "flexible настройка" half of the cover module — which
 * filter, which mask, where the caption sits and how big it is. Stored in
 * their own option (not in the settings blob) so a tuned set can be exported
 * as JSON and imported on another site of the network.
 */
class LAA_Image_Presets {

	const OPTION_KEY = 'laa_image_presets';

	public static function positions() {
		return array(
			'top-left'      => 'Сверху слева',
			'top-center'    => 'Сверху по центру',
			'top-right'     => 'Сверху справа',
			'middle-left'   => 'По центру слева',
			'middle-center' => 'По центру',
			'middle-right'  => 'По центру справа',
			'bottom-left'   => 'Снизу слева',
			'bottom-center' => 'Снизу по центру',
			'bottom-right'  => 'Снизу справа',
		);
	}

	public static function gradient_directions() {
		return array(
			'to-bottom' => 'Сверху вниз',
			'to-top'    => 'Снизу вверх',
			'to-right'  => 'Слева направо',
			'to-left'   => 'Справа налево',
		);
	}

	public static function fit_modes() {
		return array(
			'cover'   => 'Заполнить и обрезать',
			'contain' => 'Вписать целиком (с полями)',
		);
	}

	public static function mask_modes() {
		return array(
			'stretch' => 'Растянуть на всё полотно',
			'tile'    => 'Замостить',
			'center'  => 'По центру, как есть',
		);
	}

	/** Every field a preset has, with its default. Single source of truth: the
	 *  admin form, the sanitiser and the composer all read this list. */
	public static function schema() {
		return array(
			// canvas
			'name'                  => 'Новый пресет',
			// 3:2 to match what the theme actually displays. A featured image
			// rendered with `aspect-ratio:3/2; object-fit:cover` crops anything
			// wider horizontally — at 1200×630 that ate ~127px off each side and
			// clipped the left edge of every caption line. Keep the preset's
			// ratio equal to the theme's, or move the caption inside the crop.
			'width'                 => 1200,
			'height'                => 800,
			'fit'                   => 'cover',
			'focal'                 => 'middle-center',
			'background'            => '#101418', // shows through in `contain` mode

			// filters
			'grayscale'             => 0,
			'brightness'            => -10,   // -255..255
			'contrast'              => -5,    // -100..100 (GD: negative = more contrast)
			'blur'                  => 0,     // 0..10 passes
			'colorize_hex'          => '',
			'colorize_strength'     => 0,     // 0..100

			// solid tint layer
			'tint_hex'              => '#0b1220',
			'tint_alpha'            => 35,    // 0..100

			// gradient layer
			'gradient_enabled'      => 1,
			'gradient_direction'    => 'to-bottom',
			'gradient_from_hex'     => '#000000',
			'gradient_from_alpha'   => 0,
			'gradient_to_hex'       => '#000000',
			'gradient_to_alpha'     => 75,

			// semi-transparent PNG mask
			'mask_id'               => 0,
			'mask_mode'             => 'stretch',
			'mask_alpha'            => 100,   // 0..100

			// border
			'border_width'          => 0,
			'border_color'          => '#ffffff',
			'border_inset'          => 0,
			'corner_radius'         => 0,

			// caption
			'caption_enabled'       => 1,
			'caption_source'        => 'title', // title | custom | none
			'caption_text'          => '',
			'caption_font'          => '',      // path; empty = autodetect
			'caption_size'          => 54,
			'caption_autofit'       => 1,
			'caption_min_size'      => 30,
			'caption_line_height'   => 1.22,
			'caption_color'         => '#ffffff',
			'caption_align'         => 'left',  // left | center | right
			'caption_position'      => 'bottom-left',
			'caption_offset_x'      => 64,
			'caption_offset_y'      => 56,
			'caption_max_width'     => 80,      // % of canvas width
			'caption_max_lines'     => 4,
			'caption_uppercase'     => 0,
			'caption_letter_spacing' => 0,

			// caption shadow
			'shadow_enabled'        => 1,
			'shadow_color'          => '#000000',
			'shadow_alpha'          => 60,
			'shadow_offset_x'       => 2,
			'shadow_offset_y'       => 3,

			// caption backing plate
			'plate_enabled'         => 0,
			'plate_color'           => '#000000',
			'plate_alpha'           => 55,
			'plate_padding'         => 28,
			'plate_radius'          => 10,

			// logo / watermark
			'logo_id'               => 0,
			'logo_position'         => 'top-right',
			'logo_width'            => 14,     // % of canvas width
			'logo_alpha'            => 90,
			'logo_offset_x'         => 48,
			'logo_offset_y'         => 44,

			// output
			'format'                => 'jpg',  // jpg | webp | png
			'quality'               => 86,
		);
	}

	/** @return array<string,array> keyed by preset id */
	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::seed_presets();
		}
		$out = array();
		foreach ( $stored as $id => $preset ) {
			$out[ $id ] = wp_parse_args( is_array( $preset ) ? $preset : array(), self::schema() );
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::get_all();
		if ( isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		$default = LAA_Settings::get( 'default_preset', 'default' );
		if ( isset( $all[ $default ] ) ) {
			return $all[ $default ];
		}
		return $all ? reset( $all ) : self::schema();
	}

	public static function save( $id, array $values ) {
		$all = self::get_all();
		$id  = sanitize_key( $id );
		if ( '' === $id ) {
			$id = 'preset-' . substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
		}
		$all[ $id ] = self::sanitize( $values );
		update_option( self::OPTION_KEY, $all );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::get_all();
		unset( $all[ $id ] );
		if ( empty( $all ) ) {
			$all = self::seed_presets();
		}
		update_option( self::OPTION_KEY, $all );
	}

	/** Coerce every field to its expected type and range. */
	public static function sanitize( array $values ) {
		$schema = self::schema();
		$out    = array();

		$int_ranges = array(
			'width'                 => array( 320, 3000 ),
			'height'                => array( 180, 3000 ),
			'grayscale'             => array( 0, 1 ),
			'brightness'            => array( -255, 255 ),
			'contrast'              => array( -100, 100 ),
			'blur'                  => array( 0, 10 ),
			'colorize_strength'     => array( 0, 100 ),
			'tint_alpha'            => array( 0, 100 ),
			'gradient_enabled'      => array( 0, 1 ),
			'gradient_from_alpha'   => array( 0, 100 ),
			'gradient_to_alpha'     => array( 0, 100 ),
			'mask_id'               => array( 0, PHP_INT_MAX ),
			'mask_alpha'            => array( 0, 100 ),
			'border_width'          => array( 0, 80 ),
			'border_inset'          => array( 0, 200 ),
			'corner_radius'         => array( 0, 200 ),
			'caption_enabled'       => array( 0, 1 ),
			'caption_size'          => array( 10, 300 ),
			'caption_autofit'       => array( 0, 1 ),
			'caption_min_size'      => array( 8, 300 ),
			'caption_offset_x'      => array( 0, 2000 ),
			'caption_offset_y'      => array( 0, 2000 ),
			'caption_max_width'     => array( 10, 100 ),
			'caption_max_lines'     => array( 1, 10 ),
			'caption_uppercase'     => array( 0, 1 ),
			'caption_letter_spacing' => array( -10, 40 ),
			'shadow_enabled'        => array( 0, 1 ),
			'shadow_alpha'          => array( 0, 100 ),
			'shadow_offset_x'       => array( -40, 40 ),
			'shadow_offset_y'       => array( -40, 40 ),
			'plate_enabled'         => array( 0, 1 ),
			'plate_alpha'           => array( 0, 100 ),
			'plate_padding'         => array( 0, 200 ),
			'plate_radius'          => array( 0, 120 ),
			'logo_id'               => array( 0, PHP_INT_MAX ),
			'logo_width'            => array( 2, 100 ),
			'logo_alpha'            => array( 0, 100 ),
			'logo_offset_x'         => array( 0, 2000 ),
			'logo_offset_y'         => array( 0, 2000 ),
			'quality'               => array( 1, 100 ),
		);

		foreach ( $schema as $key => $default ) {
			$value = $values[ $key ] ?? $default;

			if ( isset( $int_ranges[ $key ] ) ) {
				$out[ $key ] = max( $int_ranges[ $key ][0], min( $int_ranges[ $key ][1], (int) $value ) );
				continue;
			}

			switch ( $key ) {
				case 'caption_line_height':
					$out[ $key ] = max( 0.8, min( 3.0, (float) $value ) );
					break;
				case 'background':
				case 'colorize_hex':
				case 'tint_hex':
				case 'gradient_from_hex':
				case 'gradient_to_hex':
				case 'border_color':
				case 'caption_color':
				case 'shadow_color':
				case 'plate_color':
					$out[ $key ] = self::sanitize_hex( $value, $default );
					break;
				case 'fit':
					$out[ $key ] = isset( self::fit_modes()[ $value ] ) ? $value : $default;
					break;
				case 'focal':
				case 'caption_position':
				case 'logo_position':
					$out[ $key ] = isset( self::positions()[ $value ] ) ? $value : $default;
					break;
				case 'gradient_direction':
					$out[ $key ] = isset( self::gradient_directions()[ $value ] ) ? $value : $default;
					break;
				case 'mask_mode':
					$out[ $key ] = isset( self::mask_modes()[ $value ] ) ? $value : $default;
					break;
				case 'caption_align':
					$out[ $key ] = in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : $default;
					break;
				case 'caption_source':
					$out[ $key ] = in_array( $value, array( 'title', 'custom', 'none' ), true ) ? $value : $default;
					break;
				case 'format':
					$out[ $key ] = in_array( $value, array( 'jpg', 'webp', 'png' ), true ) ? $value : $default;
					break;
				case 'caption_font':
					// A path, not arbitrary text — but validated for real at
					// render time by LAA_Image_Composer::resolve_font().
					$out[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'caption_text':
					$out[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		if ( '' === trim( $out['name'] ) ) {
			$out['name'] = 'Без названия';
		}
		if ( $out['caption_min_size'] > $out['caption_size'] ) {
			$out['caption_min_size'] = $out['caption_size'];
		}

		return $out;
	}

	public static function sanitize_hex( $value, $default = '#000000' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$clean = sanitize_hex_color( 0 === strpos( $value, '#' ) ? $value : '#' . $value );
		return $clean ? $clean : $default;
	}

	/** @return array{0:int,1:int,2:int} */
	public static function hex_to_rgb( $hex, $fallback = array( 0, 0, 0 ) ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/** Two starting points, so the screen isn't empty on a fresh install. */
	public static function seed_presets() {
		$base = self::schema();

		$dark = wp_parse_args(
			array(
				'name'                => 'Тёмный с подписью снизу',
				'brightness'          => -15,
				'tint_hex'            => '#0b1220',
				'tint_alpha'          => 40,
				'gradient_enabled'    => 1,
				'gradient_direction'  => 'to-bottom',
				'gradient_from_alpha' => 0,
				'gradient_to_alpha'   => 80,
				'caption_position'    => 'bottom-left',
				'caption_align'       => 'left',
				'caption_size'        => 54,
			),
			$base
		);

		$plate = wp_parse_args(
			array(
				'name'             => 'Подпись на плашке по центру',
				'grayscale'        => 1,
				'blur'             => 1,
				'tint_hex'         => '#1b2735',
				'tint_alpha'       => 30,
				'gradient_enabled' => 0,
				'caption_position' => 'middle-center',
				'caption_align'    => 'center',
				'caption_max_width' => 72,
				'plate_enabled'    => 1,
				'plate_alpha'      => 62,
				'shadow_enabled'   => 0,
			),
			$base
		);

		return array(
			'default' => $dark,
			'plate'   => $plate,
		);
	}
}

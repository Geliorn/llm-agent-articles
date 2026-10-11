<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the cover strategy for one article and attaches the result:
 *   generate — ask an image model, optionally fall back to the library;
 *   library  — take a picture from the configured pool and run it through
 *              an overlay preset (filter + mask + border + caption);
 *   none     — leave the post without a featured image.
 * Both image paths end at sideload(): one attachment, one thumbnail, alt
 * text set from the article title.
 */
class LAA_Image_Service {

	/**
	 * @return array{ok:bool,attachment_id:int,mode:string,error:string}
	 */
	public static function attach_cover( $post_id, array $article, array $meta ) {
		$mode = $meta['image_mode'];
		if ( 'inherit' === $mode ) {
			$mode = LAA_Settings::get( 'image_mode', 'library' );
		}

		if ( 'none' === $mode ) {
			return self::result( true, 0, 'none', '' );
		}

		$title = $article['title'];

		if ( 'generate' === $mode ) {
			$generated = self::from_generator( $post_id, $title, $meta );
			if ( $generated['ok'] ) {
				return $generated;
			}
			if ( ! LAA_Settings::get( 'image_fallback_library' ) ) {
				return $generated;
			}
			LAA_Log::error( 'Генерация картинки не удалась, беру из библиотеки: ' . $generated['error'] );
		}

		return self::from_library( $post_id, $title, $meta );
	}

	// ----------------------------------------------------------- generator

	private static function from_generator( $post_id, $title, array $meta ) {
		$prompt = LAA_Image_Generator::build_prompt( $title, $meta['theme'], '' );
		$result = LAA_Image_Generator::generate( $prompt );

		if ( ! $result['ok'] ) {
			return self::result( false, 0, 'generate', $result['error'] );
		}

		$extension     = 'image/png' === $result['mime'] ? 'png' : 'jpg';
		$attachment_id = self::sideload_bytes( $result['bytes'], $post_id, $title, $extension, $result['mime'] );

		if ( ! $attachment_id ) {
			return self::result( false, 0, 'generate', 'Не удалось сохранить сгенерированную картинку в медиабиблиотеку' );
		}

		set_post_thumbnail( $post_id, $attachment_id );
		return self::result( true, $attachment_id, 'generate', '' );
	}

	// ------------------------------------------------------------- library

	private static function from_library( $post_id, $title, array $meta ) {
		if ( ! LAA_Image_Composer::available() ) {
			return self::result( false, 0, 'library', 'Расширение GD недоступно — обложка не собрана' );
		}

		$source_id = self::pick_source( $title );
		if ( ! $source_id['path'] ) {
			return self::result( false, 0, 'library', 'Пул картинок пуст — укажите источник в настройках' );
		}

		$preset_id = $meta['image_preset'] ?: LAA_Settings::get( 'default_preset', 'default' );
		$preset    = LAA_Image_Presets::get( $preset_id );

		$caption = '';
		if ( 'title' === $preset['caption_source'] ) {
			$caption = $title;
		} elseif ( 'custom' === $preset['caption_source'] ) {
			$caption = $preset['caption_text'];
		}

		$tmp = self::temp_path( LAA_Image_Composer::extension_for( $preset['format'] ) );

		$rendered = LAA_Image_Composer::render( $preset, $source_id['path'], $caption, $tmp );
		if ( ! $rendered['ok'] ) {
			return self::result( false, 0, 'library', $rendered['error'] );
		}
		if ( '' !== $rendered['error'] ) {
			LAA_Log::error( 'Обложка: ' . $rendered['error'] );
		}

		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore

		$attachment_id = self::sideload_bytes( $bytes, $post_id, $title, LAA_Image_Composer::extension_for( $preset['format'] ), $rendered['mime'] );
		if ( ! $attachment_id ) {
			return self::result( false, 0, 'library', 'Не удалось сохранить обложку в медиабиблиотеку' );
		}

		set_post_thumbnail( $post_id, $attachment_id );
		return self::result( true, $attachment_id, 'library', '' );
	}

	/**
	 * One image out of the configured pool. round_robin is the default because
	 * random repeats visibly on a small pool — three articles in a row on the
	 * same photo looks broken even when it isn't.
	 *
	 * @return array{id:int,path:string}
	 */
	public static function pick_source( $title = '' ) {
		$candidates = self::pool();
		if ( empty( $candidates ) ) {
			return array(
				'id'   => 0,
				'path' => '',
			);
		}

		switch ( LAA_Settings::get( 'library_strategy', 'round_robin' ) ) {
			case 'random':
				$chosen = $candidates[ array_rand( $candidates ) ];
				break;

			case 'by_keyword':
				$chosen = self::best_match( $candidates, $title );
				break;

			case 'round_robin':
			default:
				$cursor = (int) LAA_Settings::get( 'library_cursor', 0 );
				$chosen = $candidates[ $cursor % count( $candidates ) ];
				LAA_Settings::update( array( 'library_cursor' => ( $cursor + 1 ) % max( 1, count( $candidates ) ) ) );
		}

		return $chosen;
	}

	/** @return array<int,array{id:int,path:string,text:string}> */
	public static function pool() {
		$source = LAA_Settings::get( 'library_source', 'ids' );

		if ( 'folder' === $source ) {
			return self::pool_from_folder();
		}
		if ( 'term' === $source ) {
			return self::pool_from_term();
		}
		return self::pool_from_ids();
	}

	private static function pool_from_ids() {
		$ids = array_filter( array_map( 'intval', preg_split( '/[^0-9]+/', (string) LAA_Settings::get( 'library_ids', '' ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$path = get_attached_file( $id );
			if ( $path && is_readable( $path ) ) {
				$out[] = array(
					'id'   => $id,
					'path' => $path,
					'text' => trim( get_the_title( $id ) . ' ' . (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
				);
			}
		}
		return $out;
	}

	private static function pool_from_term() {
		$raw = (string) LAA_Settings::get( 'library_term', '' );
		if ( false === strpos( $raw, ':' ) ) {
			return array();
		}
		list( $taxonomy, $term_id ) = array_map( 'trim', explode( ':', $raw, 2 ) );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => (int) $term_id,
					),
				),
			)
		);

		$out = array();
		foreach ( $attachments as $id ) {
			$path = get_attached_file( $id );
			if ( $path && is_readable( $path ) ) {
				$out[] = array(
					'id'   => $id,
					'path' => $path,
					'text' => trim( get_the_title( $id ) . ' ' . (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * A plain folder inside uploads — the simplest way to drop 30 stock
	 * pictures on a site without importing each one into the media library.
	 */
	private static function pool_from_folder() {
		$folder = trim( (string) LAA_Settings::get( 'library_folder', '' ), '/' );
		if ( '' === $folder ) {
			return array();
		}

		$upload = wp_upload_dir();
		$base   = realpath( trailingslashit( $upload['basedir'] ) . $folder );
		$root   = realpath( $upload['basedir'] );

		// Keep the setting from escaping uploads via ../
		if ( ! $base || ! $root || 0 !== strpos( $base, $root ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) glob( trailingslashit( $base ) . '*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE ) as $path ) {
			if ( is_readable( $path ) ) {
				$out[] = array(
					'id'   => 0,
					'path' => $path,
					'text' => pathinfo( $path, PATHINFO_FILENAME ),
				);
			}
		}
		return $out;
	}

	/** Scores pool entries by word overlap with the title; ties go random. */
	private static function best_match( array $candidates, $title ) {
		$words = array_filter(
			array_map( 'mb_strtolower', preg_split( '/[^\p{L}\p{N}]+/u', (string) $title, -1, PREG_SPLIT_NO_EMPTY ) ),
			function ( $word ) {
				return mb_strlen( $word ) > 3;
			}
		);

		$best  = array();
		$score = 0;

		foreach ( $candidates as $candidate ) {
			$text    = mb_strtolower( $candidate['text'] );
			$matches = 0;
			foreach ( $words as $word ) {
				if ( false !== mb_strpos( $text, $word ) ) {
					++$matches;
				}
			}
			if ( $matches > $score ) {
				$score = $matches;
				$best  = array( $candidate );
			} elseif ( $matches === $score ) {
				$best[] = $candidate;
			}
		}

		return $best ? $best[ array_rand( $best ) ] : $candidates[ array_rand( $candidates ) ];
	}

	// ------------------------------------------------------------ plumbing

	/** Writes bytes into the media library as an attachment of $post_id. */
	public static function sideload_bytes( $bytes, $post_id, $title, $extension, $mime ) {
		if ( '' === $bytes ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$slug     = LAA_Article_Builder::slugify( $title );
		$filename = ( $slug ? $slug : 'cover' ) . '-' . wp_generate_password( 6, false, false ) . '.' . $extension;

		$uploaded = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $uploaded['error'] ) ) {
			LAA_Log::error( 'Сохранение картинки: ' . $uploaded['error'] );
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime ?: 'image/jpeg',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$uploaded['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return 0;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		// Alt text matters for the checker and for image search — the article
		// title is the only honest description we have of a composed cover.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $title ) );
		update_post_meta( $attachment_id, '_laa_generated', 1 );

		return (int) $attachment_id;
	}

	public static function temp_path( $extension ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'laa-tmp';
		wp_mkdir_p( $dir );
		return trailingslashit( $dir ) . 'laa-' . wp_generate_password( 10, false, false ) . '.' . $extension;
	}

	private static function result( $ok, $attachment_id, $mode, $error ) {
		return array(
			'ok'            => $ok,
			'attachment_id' => (int) $attachment_id,
			'mode'          => $mode,
			'error'         => $error,
		);
	}
}

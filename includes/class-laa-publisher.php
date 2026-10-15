<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The end of the pipeline: generate -> build -> link -> check -> insert ->
 * cover -> terms -> SEO meta. One topic in, one post out, and the queue row
 * updated either way.
 */
class LAA_Publisher {

	/**
	 * @param array $topic Row from the queue table.
	 * @return array{ok:bool,post_id:int,error:string,report:array}
	 */
	public static function write_topic( array $topic ) {
		$plan_id = (int) $topic['plan_id'];
		$meta    = LAA_Meta_Fields::get_plan_settings( $plan_id );

		$generated = LAA_Article_Generator::generate( $topic, $plan_id );
		if ( ! $generated['ok'] ) {
			return self::fail( $generated['error'] );
		}

		$article = $generated['article'];

		// The builder needs to know whether to leave a slot for the infographic
		// before it assembles anything.
		$meta['infographic_wanted']    = self::infographic_wanted( $meta );
		$meta['infographic_placement'] = self::infographic_placement( $meta );

		$built   = LAA_Article_Builder::build( $article, $meta );
		$content = $built['content'];

		if ( '' === trim( $content ) ) {
			return self::fail( 'Собранная статья оказалась пустой' );
		}

		$internal_links = 0;
		if ( $meta['internal_links'] ) {
			$linked         = LAA_Links::inject( $content, $article, $plan_id, 0 );
			$content        = $linked['content'];
			$internal_links = $linked['count'];
		}

		$report                             = LAA_SEO_Checker::check( $content, $article, $meta );
		$report['metrics']['internal_links'] = $internal_links;

		// A failed checklist means a human should look at it — but the text is
		// still worth keeping, so it lands as a draft rather than being thrown away.
		$status = $meta['publish_mode'];
		if ( ! $report['passed'] && LAA_Settings::get( 'checker_enforce' ) ) {
			$status = 'draft';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $meta['target_post_type'] ?: 'post',
				'post_author'  => LAA_Meta_Fields::resolve_author( $meta ),
				'post_title'   => $article['title'],
				'post_name'    => $article['slug'] ?: '',
				'post_content' => $content,
				'post_excerpt' => $article['meta_description'],
				'post_status'  => in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ? $status : 'draft',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return self::fail( 'wp_insert_post: ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_laa_plan_id', $plan_id );
		update_post_meta( $post_id, '_laa_topic_id', (int) $topic['id'] );
		update_post_meta( $post_id, '_laa_generated', 1 );
		update_post_meta( $post_id, '_laa_provider', LAA_Settings::llm_provider_config( $meta['llm_provider'] )['provider'] );
		update_post_meta( $post_id, '_laa_primary_keyword', $article['primary_keyword'] );
		update_post_meta( $post_id, '_laa_seo_report', wp_json_encode( $report, JSON_UNESCAPED_UNICODE ) );

		self::apply_terms( $post_id, $article, $meta );

		LAA_SEO::apply( $post_id, $article['meta_title'], $article['meta_description'] );

		$warnings_before = count( $report['warnings'] );
		$infographic     = self::attach_infographic( $post_id, $article, $meta, $report );
		// The report was stored before the infographic existed — its notes
		// (and the cover-proportions warning) belong in the checklist too.
		if ( count( $report['warnings'] ) !== $warnings_before ) {
			update_post_meta( $post_id, '_laa_seo_report', wp_json_encode( $report, JSON_UNESCAPED_UNICODE ) );
		}

		// A cover would only be overwritten a moment later, so skip it when the
		// infographic has already claimed the thumbnail.
		if ( ! ( $infographic['ok'] && $infographic['is_thumbnail'] ) ) {
			$cover = LAA_Image_Service::attach_cover( $post_id, $article, $meta );
			if ( ! $cover['ok'] ) {
				LAA_Log::error( sprintf( 'Статья #%d «%s»: обложка не сделана — %s', $post_id, $article['title'], $cover['error'] ) );
			}
		}

		LAA_Log::add(
			sprintf(
				'Статья #%d «%s» (%s): %s%s',
				$post_id,
				$article['title'],
				get_post_status( $post_id ),
				LAA_SEO_Checker::summary( $report ),
				$report['warnings'] ? ' · замечаний: ' . count( $report['warnings'] ) : ''
			)
		);

		return array(
			'ok'      => true,
			'post_id' => (int) $post_id,
			'error'   => '',
			'report'  => $report,
		);
	}

	public static function infographic_wanted( array $meta ) {
		$mode = $meta['infographic_mode'] ?? 'inherit';
		if ( 'inherit' === $mode ) {
			return (bool) LAA_Settings::get( 'infographic_enabled' );
		}
		return 'on' === $mode;
	}

	/**
	 * Where the infographic goes: 'cover' — it becomes the post thumbnail
	 * instead of the regular cover and stays out of the text (default);
	 * 'start' / 'end' — into the text, right after the lead or at the very
	 * end, and the regular cover is made as usual. The plan can override the
	 * plugin-wide setting.
	 */
	public static function infographic_placement( array $meta ) {
		$placement = (string) ( $meta['infographic_placement'] ?? 'inherit' );
		if ( 'inherit' === $placement || '' === $placement ) {
			$placement = (string) LAA_Settings::get( 'infographic_placement', 'cover' );
		}
		return in_array( $placement, array( 'cover', 'start', 'end' ), true ) ? $placement : 'cover';
	}

	/**
	 * Builds the infographic, then either makes it the thumbnail (placement
	 * 'cover') or swaps it into the post content in place of the marker.
	 * The marker is removed in every path, including failure — it must never
	 * reach the page.
	 *
	 * @return array{ok:bool,is_thumbnail:bool,attachment_id:int}
	 */
	private static function attach_infographic( $post_id, array $article, array $meta, array &$report ) {
		$result = array(
			'ok'            => false,
			'is_thumbnail'  => false,
			'attachment_id' => 0,
		);

		if ( empty( $meta['infographic_wanted'] ) ) {
			self::strip_marker( $post_id );
			return $result;
		}

		$made = LAA_Infographic_Service::create_for_post( $post_id, $article, $meta );

		foreach ( $made['notes'] as $note ) {
			$report['warnings'][] = 'Инфографика: ' . $note;
		}

		if ( ! $made['ok'] ) {
			LAA_Log::error( sprintf( 'Статья #%d «%s»: инфографика не сделана — %s', $post_id, $article['title'], $made['error'] ) );
			$report['warnings'][] = 'Инфографика не сделана: ' . $made['error'];
			self::strip_marker( $post_id );
			return $result;
		}

		if ( 'cover' === self::infographic_placement( $meta ) ) {
			self::strip_marker( $post_id );
			set_post_thumbnail( $post_id, $made['attachment_id'] );
			$result['is_thumbnail'] = true;
			$ratio_note = self::cover_ratio_note( $meta );
			if ( '' !== $ratio_note ) {
				$report['warnings'][] = $ratio_note;
			}
		} else {
			self::insert_infographic_block( $post_id, $made['attachment_id'] );
		}

		LAA_Log::add( sprintf( 'Статья #%d: инфографика #%d, пунктов %d', $post_id, $made['attachment_id'], count( $made['points'] ) ) );

		$result['ok']            = true;
		$result['attachment_id'] = (int) $made['attachment_id'];

		return $result;
	}

	/**
	 * As a thumbnail the infographic goes through the same theme crop as a
	 * cover (aspect-ratio + object-fit:cover — the 1200×630 bug of 0.2.x), so
	 * its canvas must have the cover's proportions, which are the ones tuned to
	 * the theme. The built-in presets are 1200×800 like the cover; this only
	 * catches a preset whose size was changed. '' when the proportions match.
	 */
	public static function cover_ratio_note( array $meta ) {
		$info  = LAA_Infographic_Presets::get( ( $meta['infographic_preset'] ?? '' ) ?: LAA_Settings::get( 'infographic_default_preset', 'grid' ) );
		$cover = LAA_Image_Presets::get( ( $meta['image_preset'] ?? '' ) ?: LAA_Settings::get( 'default_preset', 'default' ) );
		$iw    = (int) $info['width'];
		$ih    = (int) $info['height'];
		$cw    = (int) $cover['width'];
		$ch    = (int) $cover['height'];
		if ( $ih < 1 || $ch < 1 || abs( $iw / $ih - $cw / $ch ) < 0.02 ) {
			return '';
		}
		return sprintf(
			'Инфографика стоит обложкой, но её пропорции %d×%d не совпадают с пропорциями обложки %d×%d — тема может обрезать края. Поменяйте размер в пресете инфографики.',
			$iw,
			$ih,
			$cw,
			$ch
		);
	}

	private static function insert_infographic_block( $post_id, $attachment_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$attrs = wp_json_encode(
			array(
				'id'              => (int) $attachment_id,
				'sizeSlug'        => 'large',
				'linkDestination' => 'none',
				'className'       => 'laa-infographic',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$block = "<!-- wp:image " . $attrs . " -->\n"
			. '<figure class="wp-block-image size-large laa-infographic">'
			. '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $attachment_id . '"/>'
			. "</figure>\n"
			. '<!-- /wp:image -->';

		$content = str_replace( LAA_Article_Builder::INFOGRAPHIC_MARKER, $block, $post->post_content );

		// No marker (older content, or an edited post) — append rather than lose it.
		if ( $content === $post->post_content ) {
			$content .= "\n\n" . $block;
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);
	}

	private static function strip_marker( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || false === strpos( $post->post_content, LAA_Article_Builder::INFOGRAPHIC_MARKER ) ) {
			return;
		}

		// Blocks are joined with a blank line, so take the separator in front of
		// the marker with it — otherwise removing the marker leaves a gap.
		// Order matters: strip the "\n\n + marker" form first, then a bare
		// marker that happened to be the very first block.
		$content = str_replace( "\n\n" . LAA_Article_Builder::INFOGRAPHIC_MARKER, '', $post->post_content );
		$content = str_replace( LAA_Article_Builder::INFOGRAPHIC_MARKER . "\n\n", '', $content );
		$content = str_replace( LAA_Article_Builder::INFOGRAPHIC_MARKER, '', $content );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);
	}

	private static function apply_terms( $post_id, array $article, array $meta ) {
		foreach ( LAA_Meta_Fields::target_terms( $meta ) as $taxonomy => $ids ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy );
		}

		if ( 'none' === $meta['tags_mode'] || empty( $article['tags'] ) ) {
			return;
		}
		if ( ! is_object_in_taxonomy( get_post_type( $post_id ), 'post_tag' ) ) {
			return;
		}

		// Extension point for a site-wide tag vocabulary: a subscriber returns
		// the term ids to attach (aliases resolved, unknowns handled its way).
		// Nobody subscribed → null → the behaviour below, unchanged.
		$resolved = apply_filters( 'laa_resolve_tags', null, $article['tags'], $post_id, $meta['tags_mode'] );
		if ( is_array( $resolved ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $resolved ) ) );
			if ( $ids ) {
				wp_set_object_terms( $post_id, $ids, 'post_tag', true );
			}
			return;
		}

		if ( 'existing_only' === $meta['tags_mode'] ) {
			// Keeps the tag vocabulary from growing a long tail of one-off
			// synonyms the model invents per article.
			$ids = array();
			foreach ( $article['tags'] as $tag ) {
				$term = get_term_by( 'name', $tag, 'post_tag' );
				if ( $term ) {
					$ids[] = (int) $term->term_id;
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $post_id, $ids, 'post_tag', true );
			}
			return;
		}

		wp_set_object_terms( $post_id, $article['tags'], 'post_tag', true );
	}

	private static function fail( $error ) {
		return array(
			'ok'      => false,
			'post_id' => 0,
			'error'   => $error,
			'report'  => array(),
		);
	}
}

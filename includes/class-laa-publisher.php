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

		$cover = LAA_Image_Service::attach_cover( $post_id, $article, $meta );
		if ( ! $cover['ok'] ) {
			LAA_Log::error( sprintf( 'Статья #%d «%s»: обложка не сделана — %s', $post_id, $article['title'], $cover['error'] ) );
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

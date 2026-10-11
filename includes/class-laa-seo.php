<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the generated title/description into whichever SEO plugin's own
 * meta fields are configured, so the snippet isn't left to auto-generation.
 * 'none' skips it — not every site of the network runs Yoast or Rank Math.
 * Extends LAGR_SEO with the meta title, which an article needs and a catalog
 * card didn't.
 */
class LAA_SEO {

	public static function apply( $post_id, $meta_title, $meta_description ) {
		$plugin = LAA_Settings::get( 'seo_plugin', 'none' );
		if ( 'none' === $plugin ) {
			return;
		}

		$title       = trim( wp_strip_all_tags( (string) $meta_title ) );
		$description = trim( wp_strip_all_tags( (string) $meta_description ) );

		switch ( $plugin ) {
			case 'yoast':
				if ( '' !== $title ) {
					update_post_meta( $post_id, '_yoast_wpseo_title', $title );
				}
				if ( '' !== $description ) {
					update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
				}
				break;
			case 'rankmath':
				if ( '' !== $title ) {
					update_post_meta( $post_id, 'rank_math_title', $title );
				}
				if ( '' !== $description ) {
					update_post_meta( $post_id, 'rank_math_description', $description );
				}
				break;
		}
	}
}

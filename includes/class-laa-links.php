<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal linking. Done here rather than in the prompt on purpose: a model
 * asked for links invents URLs. We only ever insert URLs we looked up
 * ourselves — sibling articles from the same plan, and catalog terms whose
 * name actually occurs in the text.
 */
class LAA_Links {

	const MAX_SIBLING_LINKS = 3;
	const MAX_TERM_LINKS    = 2;

	/**
	 * @param string $content Block markup.
	 * @param array  $article Normalised outline.
	 * @param int    $plan_id
	 * @param int    $exclude_post_id
	 * @return array{content:string,count:int}
	 */
	public static function inject( $content, array $article, $plan_id, $exclude_post_id = 0 ) {
		$inserted = 0;

		$keywords = array_merge(
			array( $article['primary_keyword'] ),
			$article['secondary_keywords'],
			$article['tags']
		);
		$keywords = array_values( array_filter( array_map( 'trim', $keywords ) ) );

		// 1) Catalog terms mentioned by name in the body.
		foreach ( self::find_catalog_terms( $content, $keywords ) as $term ) {
			if ( $inserted >= self::MAX_TERM_LINKS ) {
				break;
			}
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$new = self::link_first_mention( $content, $term->name, $link );
			if ( null !== $new ) {
				$content = $new;
				++$inserted;
			}
		}

		// 2) A "читайте также" list of sibling articles from the same plan.
		$siblings = self::sibling_articles( $plan_id, $exclude_post_id, self::MAX_SIBLING_LINKS );
		if ( ! empty( $siblings ) ) {
			$items = array();
			foreach ( $siblings as $sibling ) {
				$items[] = '<a href="' . esc_url( get_permalink( $sibling ) ) . '">' . esc_html( get_the_title( $sibling ) ) . '</a>';
			}
			$used_anchors = array();
			$content     .= "\n\n" . LAA_Article_Builder::heading( 'Читайте также', 2, LAA_Article_Builder::unique_anchor( 'Читайте также', $used_anchors ) );
			$content     .= "\n\n" . LAA_Article_Builder::list_block( $items, false );
			$inserted    += count( $items );
		}

		return array(
			'content' => $content,
			'count'   => $inserted,
		);
	}

	/**
	 * Terms from the catalog taxonomies whose name literally appears in the
	 * article. Matching on the term name (not on our keywords) is what keeps
	 * the link honest — the anchor text is real text from the page.
	 */
	private static function find_catalog_terms( $content, array $keywords ) {
		$taxonomies = array_values(
			array_filter(
				array( 'repo_topic', 'contractor_specialization', 'post_tag' ),
				'taxonomy_exists'
			)
		);
		if ( empty( $taxonomies ) || empty( $keywords ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'number'     => 400,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$plain = wp_strip_all_tags( LAA_SEO_Checker::strip_block_comments( $content ) );
		$found = array();

		foreach ( $terms as $term ) {
			// Two-character terms ("ИИ") match inside other words too often.
			if ( mb_strlen( $term->name ) < 3 ) {
				continue;
			}
			if ( false === mb_stripos( $plain, $term->name ) ) {
				continue;
			}
			// Prefer terms the article is actually about, not an incidental mention.
			$relevant = false;
			foreach ( $keywords as $keyword ) {
				if ( false !== mb_stripos( $keyword, $term->name ) || false !== mb_stripos( $term->name, $keyword ) ) {
					$relevant = true;
					break;
				}
			}
			if ( $relevant ) {
				$found[] = $term;
			}
		}

		return $found;
	}

	/**
	 * Wrap the first plain-text occurrence of $needle in a link. Skips any
	 * occurrence that's already inside a tag or an existing <a>, so we can't
	 * nest links or mangle an attribute.
	 */
	private static function link_first_mention( $content, $needle, $url ) {
		$pattern = '/(?<![\p{L}\p{N}])(' . preg_quote( $needle, '/' ) . ')(?![\p{L}\p{N}])/ui';

		$offset = 0;
		$length = strlen( $content );

		while ( $offset < $length && preg_match( $pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$pos = $m[1][1];

			if ( self::inside_markup( $content, $pos ) ) {
				$offset = $pos + strlen( $m[1][0] );
				continue;
			}

			$replacement = '<a href="' . esc_url( $url ) . '">' . $m[1][0] . '</a>';
			return substr( $content, 0, $pos ) . $replacement . substr( $content, $pos + strlen( $m[1][0] ) );
		}

		return null;
	}

	/** True when the offset sits inside a tag, a block comment, an <a>…</a>, a heading or a <summary>. */
	private static function inside_markup( $content, $pos ) {
		$before = substr( $content, 0, $pos );

		$last_lt = strrpos( $before, '<' );
		$last_gt = strrpos( $before, '>' );
		if ( false !== $last_lt && ( false === $last_gt || $last_lt > $last_gt ) ) {
			return true; // inside a tag or a block comment
		}

		$open_a  = strripos( $before, '<a ' );
		$close_a = strripos( $before, '</a>' );
		if ( false !== $open_a && ( false === $close_a || $open_a > $close_a ) ) {
			return true; // already inside a link
		}

		// Headings are ours; keep anchors out of them.
		$last_heading_open  = max( (int) strripos( $before, '<h2' ), (int) strripos( $before, '<h3' ), (int) strripos( $before, '<h4' ) );
		$last_heading_close = max( (int) strripos( $before, '</h2>' ), (int) strripos( $before, '</h3>' ), (int) strripos( $before, '</h4>' ) );
		if ( $last_heading_open > 0 && $last_heading_open > $last_heading_close ) {
			return true;
		}

		// FAQ questions (<summary> of the accordion): a link there would
		// navigate away instead of opening the answer.
		$open_summary  = strripos( $before, '<summary' );
		$close_summary = strripos( $before, '</summary>' );
		if ( false !== $open_summary && ( false === $close_summary || $open_summary > $close_summary ) ) {
			return true;
		}

		return false;
	}

	/** Most recent published articles from the same plan. */
	private static function sibling_articles( $plan_id, $exclude_post_id, $limit ) {
		if ( ! $plan_id ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				'post__not_in'   => $exclude_post_id ? array( (int) $exclude_post_id ) : array(),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_laa_plan_id',
						'value' => (int) $plan_id,
					),
				),
			)
		);

		return $query->posts;
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The article FAQ on the front end: FAQPage JSON-LD and the accordion style.
 *
 * Questions are read from the post content itself — the core/details blocks
 * with class laa-faq-item that LAA_Article_Builder::faq_item() writes — not
 * from a stored copy, so an editor's change to a question or answer reaches
 * the markup too, and the marked-up text is always the text on the page.
 *
 * With Yoast active the questions go INTO Yoast's own @graph (its WebPage
 * becomes WebPage + FAQPage, the way Yoast's own FAQ block does it) instead
 * of a second, disconnected <script>. Without Yoast, a standalone FAQPage.
 *
 * Articles written before 0.4.0 keep their FAQ as H3 + paragraph and get
 * neither — on purpose, only new articles change.
 */
class LAA_FAQ {

	const ITEM_CLASS = 'laa-faq-item';

	public static function init() {
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'yoast_graph' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'print_standalone' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * @return array<int, array{question:string, answer:string}>
	 */
	public static function items( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( false === strpos( $content, self::ITEM_CLASS ) ) {
			return array();
		}
		$items = array();
		self::collect( parse_blocks( $content ), $items );
		return $items;
	}

	private static function collect( array $blocks, array &$items ) {
		foreach ( $blocks as $block ) {
			$class = (string) ( $block['attrs']['className'] ?? '' );
			if ( 'core/details' === $block['blockName'] && in_array( self::ITEM_CLASS, preg_split( '/\s+/', $class ), true ) ) {
				$question = preg_match( '#<summary[^>]*>(.*?)</summary>#is', (string) $block['innerHTML'], $m ) ? self::text( $m[1] ) : '';
				$answer   = array();
				foreach ( $block['innerBlocks'] as $inner ) {
					$text = self::text( render_block( $inner ) );
					if ( '' !== $text ) {
						$answer[] = $text;
					}
				}
				if ( '' !== $question && $answer ) {
					$items[] = array(
						'question' => $question,
						'answer'   => implode( "\n", $answer ),
					);
				}
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect( $block['innerBlocks'], $items );
			}
		}
	}

	private static function text( $html ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private static function current_post_items() {
		if ( ! is_singular() ) {
			return array();
		}
		return self::items( (int) get_queried_object_id() );
	}

	/**
	 * @param array  $graph   Yoast's schema pieces.
	 * @param object $context Yoast Meta_Tags_Context (canonical, main_schema_id).
	 */
	public static function yoast_graph( $graph, $context ) {
		$items = self::current_post_items();
		if ( ! $items || ! is_array( $graph ) ) {
			return $graph;
		}

		$page_url  = ! empty( $context->canonical ) ? $context->canonical : get_permalink( get_queried_object_id() );
		$page_id   = ! empty( $context->main_schema_id ) ? $context->main_schema_id : $page_url;
		$questions = self::question_nodes( $items, $page_url );

		foreach ( $graph as &$piece ) {
			if ( ! is_array( $piece ) || ( $piece['@id'] ?? '' ) !== $page_id ) {
				continue;
			}
			$types = (array) ( $piece['@type'] ?? 'WebPage' );
			if ( ! in_array( 'FAQPage', $types, true ) ) {
				$types[] = 'FAQPage';
			}
			$piece['@type']      = $types;
			$piece['mainEntity'] = array_map(
				static function ( $q ) {
					return array( '@id' => $q['@id'] );
				},
				$questions
			);
		}
		unset( $piece );

		return array_merge( $graph, $questions );
	}

	/** Without Yoast: one FAQPage <script> in <head>. */
	public static function print_standalone() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		$items = self::current_post_items();
		if ( ! $items ) {
			return;
		}
		$page_url = get_permalink( get_queried_object_id() );
		$json     = wp_json_encode(
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'@id'        => $page_url . '#faq',
				'url'        => $page_url,
				'mainEntity' => self::question_nodes( $items, $page_url ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
		);
		echo '<script type="application/ld+json" class="laa-faq-schema">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with JSON_HEX_TAG, cannot close the script tag.
	}

	private static function question_nodes( array $items, $page_url ) {
		$lang  = get_bloginfo( 'language' );
		$nodes = array();
		foreach ( $items as $i => $item ) {
			$nodes[] = array(
				'@type'          => 'Question',
				'@id'            => $page_url . '#faq-' . ( $i + 1 ),
				'position'       => $i + 1,
				'name'           => $item['question'],
				'answerCount'    => 1,
				'acceptedAnswer' => array(
					'@type'      => 'Answer',
					'text'       => $item['answer'],
					'inLanguage' => $lang,
				),
				'inLanguage'     => $lang,
			);
		}
		return $nodes;
	}

	/** Only on pages that actually have the accordion. */
	public static function enqueue_style() {
		if ( ! is_singular() ) {
			return;
		}
		$content = (string) get_post_field( 'post_content', get_queried_object_id() );
		if ( false === strpos( $content, self::ITEM_CLASS ) ) {
			return;
		}
		wp_enqueue_style( 'laa-faq', LAA_PLUGIN_URL . 'assets/css/faq.css', array(), LAA_VERSION );
	}
}

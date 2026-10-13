<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step 3: the generated outline + section HTML -> Gutenberg block markup.
 *
 * Two reasons this isn't just string concatenation of the model's HTML:
 *  1) Block markup keeps the article editable in the block editor and picks
 *     up the theme's own styles — raw <p> soup becomes one classic block.
 *  2) Model output is untrusted. Everything goes through a strict allowlist
 *     here, so a stray <script> or an inline style can't reach the page.
 */
class LAA_Article_Builder {

	/**
	 * Marker the publisher swaps for the infographic block once the image
	 * exists. Put in during assembly because only the builder knows where the
	 * «Коротко» block and the FAQ actually ended up; always removed afterwards,
	 * whether or not an infographic was made.
	 */
	const INFOGRAPHIC_MARKER = '<!--laa:infographic-->';

	/** Inline markup allowed inside any text node. */
	public static function inline_allowed() {
		return array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'code'   => array(),
			'br'     => array(),
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			),
		);
	}

	/**
	 * @param array $article Normalised outline with per-section html.
	 * @param array $meta    Plan settings.
	 * @return array{content:string,toc:array,stats:array}
	 */
	public static function build( array $article, array $meta ) {
		$blocks   = array();
		$toc      = array();
		$sections = array();

		// Lead paragraph — the direct answer, before anything else.
		if ( ! empty( $article['lead'] ) ) {
			$blocks[] = self::paragraph( esc_html( $article['lead'] ), 'has-large-font-size' );
		}

		// "Коротко" — doubles as the summary block and as the source for the
		// takeaways a reader scans before deciding to read on.
		if ( $meta['require_takeaways'] && ! empty( $article['takeaways'] ) ) {
			$blocks[] = self::heading( 'Коротко', 2, 'korotko' );
			$toc[]    = array(
				'anchor' => 'korotko',
				'text'   => 'Коротко',
			);
			$blocks[] = self::list_block( array_map( 'esc_html', $article['takeaways'] ), false );

			if ( ! empty( $meta['infographic_wanted'] ) && 'after_takeaways' === ( $meta['infographic_position'] ?? '' ) ) {
				// Deferred: the table of contents is spliced in at this same
				// index later, and navigation should sit above the picture.
				$marker_after_takeaways = true;
			}
		}

		// The TOC is injected after the whole body is known (anchors first),
		// so remember where it goes.
		$toc_placeholder = null;
		if ( $meta['require_toc'] ) {
			$toc_placeholder = count( $blocks );
		}
		$marker_position = isset( $marker_after_takeaways ) ? count( $blocks ) : null;

		$used_anchors = array( 'korotko' => true );

		foreach ( $article['sections'] as $section ) {
			$body = self::fragment_to_blocks( $section['html'], $used_anchors );
			if ( '' === trim( $body ) ) {
				continue; // a section the model failed on — drop the heading too
			}

			$anchor = self::unique_anchor( $section['h2'], $used_anchors );
			$blocks[] = self::heading( esc_html( $section['h2'] ), 2, $anchor );
			$blocks[] = $body;

			$toc[]      = array(
				'anchor' => $anchor,
				'text'   => $section['h2'],
			);
			$sections[] = $section['h2'];
		}

		if ( ! empty( $article['faq'] ) ) {
			if ( ! empty( $meta['infographic_wanted'] ) && 'before_faq' === ( $meta['infographic_position'] ?? '' ) ) {
				$blocks[] = self::INFOGRAPHIC_MARKER;
			}
			$faq_anchor = self::unique_anchor( 'Частые вопросы', $used_anchors );
			$blocks[]   = self::heading( 'Частые вопросы', 2, $faq_anchor );
			$toc[]      = array(
				'anchor' => $faq_anchor,
				'text'   => 'Частые вопросы',
			);
			foreach ( $article['faq'] as $item ) {
				$blocks[] = self::heading( esc_html( $item['q'] ), 3, self::unique_anchor( $item['q'], $used_anchors ) );
				$blocks[] = self::paragraph( esc_html( $item['a'] ) );
			}
		}

		$insert = array();

		if ( null !== $toc_placeholder && count( $toc ) >= 3 ) {
			$items = array();
			foreach ( $toc as $entry ) {
				$items[] = '<a href="#' . esc_attr( $entry['anchor'] ) . '">' . esc_html( $entry['text'] ) . '</a>';
			}
			$insert[] = self::heading( 'Что в статье', 2, 'chto-v-state' );
			$insert[] = self::list_block( $items, false );
		}

		if ( null !== $marker_position ) {
			$insert[] = self::INFOGRAPHIC_MARKER;
		}

		if ( $insert ) {
			array_splice( $blocks, null !== $toc_placeholder ? $toc_placeholder : $marker_position, 0, $insert );
		}

		// Anything that asked for a marker but never got one (no takeaways, no
		// FAQ, or position=end) gets it at the end, so the publisher always has
		// exactly one place to put the image.
		if ( ! empty( $meta['infographic_wanted'] ) && ! in_array( self::INFOGRAPHIC_MARKER, $blocks, true ) ) {
			$blocks[] = self::INFOGRAPHIC_MARKER;
		}

		$content = implode( "\n\n", array_filter( $blocks ) );

		return array(
			'content'  => $content,
			'toc'      => $toc,
			'sections' => $sections,
		);
	}

	// ------------------------------------------------------------- HTML -> blocks

	/**
	 * Parse one section's HTML fragment and emit block markup for each
	 * top-level element. DOMDocument rather than regex: the model nests and
	 * mis-closes tags often enough that regex gives silently wrong output.
	 */
	public static function fragment_to_blocks( $html, array &$used_anchors ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}

		// A model that ignored the "HTML only" instruction and sent plain text
		// still produces a usable article this way.
		if ( ! preg_match( '/<(p|h3|h4|ul|ol|table|blockquote|pre)\b/i', $html ) ) {
			$out = array();
			foreach ( preg_split( '/\n\s*\n/', $html ) as $para ) {
				$para = trim( $para );
				if ( '' !== $para ) {
					$out[] = self::paragraph( self::clean_inline( $para ) );
				}
			}
			return implode( "\n\n", $out );
		}

		$dom = self::load_fragment( $html );
		if ( ! $dom ) {
			return '';
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '';
		}

		$blocks = array();
		$inline = '';

		// Consecutive inline nodes must be collected into ONE paragraph. The
		// model regularly forgets the <p> around a sentence that contains a
		// <strong>, and emitting a block per top-level node then shredded it
		// into three paragraphs — "Обработка" / "email агенты" / "требует…".
		foreach ( $body->childNodes as $node ) {
			if ( self::is_inline_node( $node ) ) {
				$inline .= $node->ownerDocument->saveHTML( $node );
				continue;
			}

			if ( '' !== trim( wp_strip_all_tags( $inline ) ) ) {
				$blocks[] = self::paragraph( self::clean_inline( $inline ) );
			}
			$inline = '';

			$block = self::node_to_block( $node, $used_anchors );
			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}

		if ( '' !== trim( wp_strip_all_tags( $inline ) ) ) {
			$blocks[] = self::paragraph( self::clean_inline( $inline ) );
		}

		return implode( "\n\n", $blocks );
	}

	/** Text nodes and inline markup — everything that belongs inside a paragraph. */
	private static function is_inline_node( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return true;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false; // comments and the like: not inline, and dropped anyway
		}

		return in_array(
			strtolower( $node->nodeName ),
			array( 'strong', 'b', 'em', 'i', 'a', 'code', 'span', 'small', 'sub', 'sup', 'u', 's', 'mark', 'br', 'abbr', 'cite', 'q', 'time', 'label', 'font' ),
			true
		);
	}

	private static function load_fragment( $html ) {
		$dom = new DOMDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );
		// The meta charset is what makes DOMDocument treat the bytes as UTF-8;
		// without it Cyrillic comes back mojibake.
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8"><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	private static function node_to_block( DOMNode $node, array &$used_anchors ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->textContent );
			return '' === $text ? '' : self::paragraph( esc_html( $text ) );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'p':
				$inner = self::inner_html( $node );
				return '' === trim( wp_strip_all_tags( $inner ) ) ? '' : self::paragraph( self::clean_inline( $inner ) );

			case 'h3':
			case 'h4':
				$text = trim( $node->textContent );
				if ( '' === $text ) {
					return '';
				}
				$level = 'h3' === $tag ? 3 : 4;
				return self::heading( esc_html( $text ), $level, self::unique_anchor( $text, $used_anchors ) );

			case 'h1':
			case 'h2':
				// The model was told not to emit these; demote rather than drop,
				// so the text isn't lost and the H2 hierarchy stays ours.
				$text = trim( $node->textContent );
				return '' === $text ? '' : self::heading( esc_html( $text ), 3, self::unique_anchor( $text, $used_anchors ) );

			case 'ul':
			case 'ol':
				return self::list_from_node( $node, 'ol' === $tag );

			case 'table':
				return self::table_from_node( $node );

			case 'blockquote':
				return self::quote_from_node( $node );

			case 'pre':
				$code = $node->textContent;
				return '' === trim( $code ) ? '' : self::code_block( $code );

			case 'div':
			case 'section':
			case 'article':
				// Unwrap containers the model wasn't supposed to use.
				$inner = array();
				foreach ( $node->childNodes as $child ) {
					$block = self::node_to_block( $child, $used_anchors );
					if ( '' !== $block ) {
						$inner[] = $block;
					}
				}
				return implode( "\n\n", $inner );

			case 'br':
			case 'script':
			case 'style':
			case 'img':
			case 'iframe':
				return '';

			default:
				$text = trim( $node->textContent );
				return '' === $text ? '' : self::paragraph( esc_html( $text ) );
		}
	}

	private static function list_from_node( DOMElement $node, $ordered ) {
		$items = array();
		foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
			// Only direct children — a nested <ul> would otherwise have its
			// items hoisted into the parent list.
			if ( $li->parentNode !== $node ) {
				continue;
			}
			// Nested lists are flattened — core/list nesting needs the item to
			// wrap the sublist, and the extra structure buys nothing here. The
			// separators go in BEFORE wp_kses, which would otherwise drop the
			// tags and glue "...источник" straight onto "внутренние...".
			$raw   = self::inner_html( $li );
			$raw   = preg_replace( '/<(ul|ol)\b[^>]*>/i', ' — ', $raw );
			$raw   = preg_replace( '/<\/li>\s*<li\b[^>]*>/i', '; ', $raw );
			$raw   = preg_replace( '/<\/?(ul|ol|li)\b[^>]*>/i', ' ', $raw );
			$inner = self::clean_inline( $raw );
			$inner = trim( preg_replace( '/\s+/u', ' ', $inner ) );
			$inner = rtrim( $inner, ' ;—' );
			if ( '' !== wp_strip_all_tags( $inner ) ) {
				$items[] = $inner;
			}
		}
		return empty( $items ) ? '' : self::list_block( $items, $ordered );
	}

	private static function table_from_node( DOMElement $node ) {
		$head = array();
		$body = array();

		foreach ( $node->getElementsByTagName( 'tr' ) as $tr ) {
			$cells   = array();
			$is_head = false;
			foreach ( $tr->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE !== $cell->nodeType ) {
					continue;
				}
				$cell_tag = strtolower( $cell->nodeName );
				if ( 'th' !== $cell_tag && 'td' !== $cell_tag ) {
					continue;
				}
				if ( 'th' === $cell_tag ) {
					$is_head = true;
				}
				$cells[] = array(
					'tag'  => $cell_tag,
					'html' => self::clean_inline( self::inner_html( $cell ) ),
				);
			}
			if ( empty( $cells ) ) {
				continue;
			}
			// A row of <th> is the header wherever it sits — models often skip
			// <thead> but do use <th>.
			if ( $is_head && empty( $head ) && empty( $body ) ) {
				$head[] = $cells;
			} else {
				$body[] = $cells;
			}
		}

		if ( empty( $head ) && empty( $body ) ) {
			return '';
		}

		$html = '<figure class="wp-block-table"><table>';
		if ( ! empty( $head ) ) {
			$html .= '<thead>';
			foreach ( $head as $row ) {
				$html .= '<tr>';
				foreach ( $row as $cell ) {
					$html .= '<th>' . $cell['html'] . '</th>';
				}
				$html .= '</tr>';
			}
			$html .= '</thead>';
		}
		if ( ! empty( $body ) ) {
			$html .= '<tbody>';
			foreach ( $body as $row ) {
				$html .= '<tr>';
				foreach ( $row as $cell ) {
					$html .= '<td>' . $cell['html'] . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody>';
		}
		$html .= '</table></figure>';

		return "<!-- wp:table -->\n" . $html . "\n<!-- /wp:table -->";
	}

	private static function quote_from_node( DOMElement $node ) {
		$paragraphs = array();
		$citation   = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), array( 'cite', 'footer' ), true ) ) {
				$citation = self::clean_inline( self::inner_html( $child ) );
				continue;
			}
			$text = XML_TEXT_NODE === $child->nodeType ? trim( $child->textContent ) : trim( self::inner_html( $child ) );
			if ( '' !== wp_strip_all_tags( $text ) ) {
				$paragraphs[] = self::paragraph( self::clean_inline( $text ) );
			}
		}

		if ( empty( $paragraphs ) ) {
			return '';
		}

		$inner = implode( "\n", $paragraphs );
		if ( '' !== $citation ) {
			$inner .= "\n<cite>" . $citation . '</cite>';
		}

		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $inner . "</blockquote>\n<!-- /wp:quote -->";
	}

	// ------------------------------------------------------------------ blocks

	public static function paragraph( $html, $class = '' ) {
		$attrs    = '';
		$class_at = '';
		if ( '' !== $class ) {
			$attrs    = ' {"className":"' . $class . '"}';
			$class_at = ' class="' . esc_attr( $class ) . '"';
		}
		return "<!-- wp:paragraph{$attrs} -->\n<p{$class_at}>" . $html . "</p>\n<!-- /wp:paragraph -->";
	}

	public static function heading( $html, $level, $anchor = '' ) {
		$attrs = array( 'level' => (int) $level );
		if ( '' !== $anchor ) {
			$attrs['anchor'] = $anchor;
		}
		$json   = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$id_at  = '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';
		$tag    = 'h' . (int) $level;
		return "<!-- wp:heading {$json} -->\n<{$tag} class=\"wp-block-heading\"{$id_at}>" . $html . "</{$tag}>\n<!-- /wp:heading -->";
	}

	public static function list_block( array $items, $ordered = false ) {
		if ( empty( $items ) ) {
			return '';
		}
		$tag   = $ordered ? 'ol' : 'ul';
		$attrs = $ordered ? ' {"ordered":true}' : '';

		$inner = array();
		foreach ( $items as $item ) {
			$inner[] = "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->";
		}

		return "<!-- wp:list{$attrs} -->\n<{$tag} class=\"wp-block-list\">" . implode( "\n", $inner ) . "</{$tag}>\n<!-- /wp:list -->";
	}

	public static function code_block( $code ) {
		return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . esc_html( trim( $code ) ) . "</code></pre>\n<!-- /wp:code -->";
	}

	// ------------------------------------------------------------------ helpers

	private static function inner_html( DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/** Model output is untrusted: strip to the inline allowlist, drop empty links. */
	public static function clean_inline( $html ) {
		$html = wp_kses( (string) $html, self::inline_allowed() );
		$html = preg_replace( '/<a\b[^>]*>\s*<\/a>/i', '', $html );
		$html = preg_replace( '/\s+/u', ' ', $html );
		return trim( $html );
	}

	public static function unique_anchor( $text, array &$used ) {
		$base = self::slugify( $text );
		if ( '' === $base ) {
			$base = 'razdel';
		}
		$anchor = $base;
		$n      = 2;
		while ( isset( $used[ $anchor ] ) ) {
			$anchor = $base . '-' . $n;
			++$n;
		}
		$used[ $anchor ] = true;
		return $anchor;
	}

	/**
	 * Tidies a title-ish string: strips tags, collapses whitespace, drops
	 * wrapping quotes. Deliberately does NOT shorten anything — cutting a
	 * title at a word or clause boundary still throws away the part that
	 * carried the meaning, so over-long titles are rewritten by
	 * LAA_Title_Shortener or kept in full and reported.
	 */
	public static function normalise_title( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return trim( $text, " \t\"'«»" );
	}

	/**
	 * Cyrillic-aware slug. sanitize_title() keeps Cyrillic as-is, which makes
	 * percent-encoded anchors in the URL bar — transliterate instead.
	 */
	public static function slugify( $text ) {
		$map = array(
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
			'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
			'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
			'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
			'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
		);

		$text = mb_strtolower( wp_strip_all_tags( (string) $text ) );
		$text = strtr( $text, $map );
		$text = preg_replace( '/[^a-z0-9]+/u', '-', $text );
		$text = trim( (string) $text, '-' );

		// Long anchors are unreadable and get truncated in share links anyway.
		if ( mb_strlen( $text ) > 60 ) {
			$text = mb_substr( $text, 0, 60 );
			$text = preg_replace( '/-[^-]*$/', '', $text );
		}

		return $text;
	}
}

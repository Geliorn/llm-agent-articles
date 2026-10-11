<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-generation checklist. The point isn't a score for its own sake: the
 * report decides whether an article is good enough to publish unattended, or
 * whether it should land as a draft for a human to look at. Without this a
 * bad provider day quietly puts 400-word stubs on the site.
 */
class LAA_SEO_Checker {

	/**
	 * @param string $content Block markup.
	 * @param array  $article Normalised outline.
	 * @param array  $meta    Plan settings.
	 * @return array{metrics:array,problems:array,warnings:array,passed:bool}
	 */
	public static function check( $content, array $article, array $meta ) {
		$plain = wp_strip_all_tags( self::strip_block_comments( $content ) );
		$words = self::count_words( $plain );

		$metrics = array(
			'words'            => $words,
			'h2'               => substr_count( $content, '<h2' ),
			'h3'               => substr_count( $content, '<h3' ),
			// `wp:list` with a word boundary — plain `substr_count( '<!-- wp:list ' )`
			// would also swallow every `wp:list-item` opener.
			'lists'            => preg_match_all( '/<!-- wp:list(?: \{|\s*-->)/', $content ),
			'tables'           => substr_count( $content, '<!-- wp:table -->' ),
			'quotes'           => substr_count( $content, '<!-- wp:quote -->' ),
			'paragraphs'       => substr_count( $content, '<!-- wp:paragraph' ),
			'faq'              => count( $article['faq'] ),
			'internal_links'   => 0, // filled in by LAA_Links after it runs
			'meta_description' => mb_strlen( $article['meta_description'] ),
			'title_length'     => mb_strlen( $article['title'] ),
			'meta_title'       => mb_strlen( (string) ( $article['meta_title'] ?? '' ) ),
		);

		$problems = array();
		$warnings = array();

		$min_words = max( (int) LAA_Settings::get( 'min_words_hard', 700 ), 0 );
		if ( $words < $min_words ) {
			$problems[] = sprintf( 'Объём %d слов — меньше жёсткого минимума %d', $words, $min_words );
		} elseif ( $words < (int) $meta['min_words'] ) {
			$warnings[] = sprintf( 'Объём %d слов — меньше целевых %d', $words, (int) $meta['min_words'] );
		}

		$min_sections = max( (int) LAA_Settings::get( 'min_sections_hard', 3 ), 0 );
		if ( $metrics['h2'] < $min_sections ) {
			$problems[] = sprintf( 'Разделов (H2) всего %d — меньше минимума %d', $metrics['h2'], $min_sections );
		}

		if ( '' === trim( $article['meta_description'] ) ) {
			$problems[] = 'Пустое meta description';
		} elseif ( $metrics['meta_description'] < 100 || $metrics['meta_description'] > 180 ) {
			$warnings[] = sprintf( 'Длина meta description %d символов — вне диапазона 120-160', $metrics['meta_description'] );
		}

		$title_max = (int) LAA_Settings::get( 'title_max_chars', 250 );
		if ( $metrics['title_length'] > $title_max ) {
			$warnings[] = sprintf( 'Заголовок %d символов — больше предела %d. Обрезка не применяется: сократите вручную.', $metrics['title_length'], $title_max );
		} elseif ( $metrics['title_length'] > 75 ) {
			$warnings[] = sprintf( 'Заголовок %d символов — длинновато для выдачи', $metrics['title_length'] );
		}

		$meta_max = (int) LAA_Settings::get( 'meta_title_max_chars', 60 );
		if ( $metrics['meta_title'] > $meta_max ) {
			$warnings[] = sprintf( 'meta_title %d символов — больше предела %d, в выдаче его обрежет поисковик', $metrics['meta_title'], $meta_max );
		}

		// Whatever the shortener could or couldn't do is part of the report.
		foreach ( (array) ( $article['title_notes'] ?? array() ) as $note ) {
			$warnings[] = $note;
		}

		if ( $meta['require_lists'] && $metrics['lists'] < 2 ) {
			$warnings[] = 'Списков меньше двух';
		}
		if ( $meta['require_table'] && $metrics['tables'] < 1 ) {
			$warnings[] = 'Нет ни одной таблицы';
		}
		if ( $meta['require_quote'] && $metrics['quotes'] < 1 ) {
			$warnings[] = 'Нет цитаты';
		}
		if ( $meta['require_faq'] && $metrics['faq'] < 3 ) {
			$warnings[] = 'В FAQ меньше трёх вопросов';
		}

		$keyword = trim( (string) $article['primary_keyword'] );
		if ( '' !== $keyword ) {
			if ( ! self::contains( $article['title'], $keyword ) ) {
				$warnings[] = 'Главный ключ не встречается в заголовке';
			}
			if ( ! self::contains( $article['lead'], $keyword ) ) {
				$warnings[] = 'Главный ключ не встречается в первом абзаце';
			}
			$density = self::density( $plain, $keyword, $words );
			if ( $density > 3.0 ) {
				$warnings[] = sprintf( 'Плотность главного ключа %.1f%% — похоже на переспам', $density );
			}
		}

		return array(
			'metrics'  => $metrics,
			'problems' => $problems,
			'warnings' => $warnings,
			'passed'   => empty( $problems ),
		);
	}

	/** Word count has to ignore the block comments, or every article looks 30% longer. */
	public static function strip_block_comments( $content ) {
		return preg_replace( '/<!--\s*\/?wp:[^>]*-->/', ' ', (string) $content );
	}

	public static function count_words( $plain ) {
		$plain = preg_replace( '/\s+/u', ' ', trim( (string) $plain ) );
		if ( '' === $plain ) {
			return 0;
		}
		return count( preg_split( '/[^\p{L}\p{N}\-]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	private static function contains( $haystack, $needle ) {
		return false !== mb_stripos( (string) $haystack, (string) $needle );
	}

	private static function density( $plain, $keyword, $words ) {
		if ( $words < 1 ) {
			return 0.0;
		}
		$occurrences  = mb_substr_count( mb_strtolower( $plain ), mb_strtolower( $keyword ) );
		$keyword_size = max( 1, self::count_words( $keyword ) );
		return ( $occurrences * $keyword_size ) / $words * 100;
	}

	/** Human-readable one-liner for the admin queue column. */
	public static function summary( array $report ) {
		$m = $report['metrics'];
		return sprintf(
			'%d слов, H2 %d, списков %d, таблиц %d%s',
			$m['words'],
			$m['h2'],
			$m['lists'],
			$m['tables'],
			$report['problems'] ? ' · проблем: ' . count( $report['problems'] ) : ''
		);
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortens an over-long title by asking the model to rewrite it, never by
 * cutting it. Truncation is deliberately not a fallback here: a title cut at
 * a word or clause boundary still loses the part that carried the meaning,
 * which is exactly what we're avoiding. If the rewrite doesn't land inside
 * the limit, the full title is kept and the caller reports it.
 */
class LAA_Title_Shortener {

	const SYSTEM_PROMPT = <<<'PROMPT'
Ты редактор. Твоя задача — сократить заголовок, не потеряв смысл.

Верни СТРОГО JSON-объект (без markdown-обёртки, без пояснений вокруг):
{"title": "сокращённый заголовок"}

Правила:
— Заголовок должен укладываться в указанный предел по символам.
— Это ПЕРЕФОРМУЛИРОВКА, а не обрезка. Нельзя оборвать фразу на полуслове или
  на середине мысли, нельзя закончить многоточием.
— Смысл и предмет заголовка сохраняются полностью. Если в заголовке есть главный
  ключ — он должен остаться.
— Выбрасывай служебные слова и обороты («полное руководство», «всё о», «пошаговая»,
  «в 2026 году»), а не содержательную часть.
— Не добавляй ничего, чего в исходном заголовке не было: ни цифр, ни названий,
  ни обещаний.
— Без эмодзи, без кавычек вокруг всего заголовка, без точки на конце.
— Язык — тот же, что в исходном заголовке.
PROMPT;

	/**
	 * @param LAA_LLM_Client $client
	 * @param string         $title
	 * @param int            $max      Character limit.
	 * @param array          $context  Optional: keyword, audience — steer the rewrite.
	 * @return array{title:string,ok:bool,note:string}
	 */
	public static function shorten( LAA_LLM_Client $client, $title, $max, array $context = array() ) {
		$title = trim( (string) $title );
		$max   = (int) $max;

		if ( $max < 1 || mb_strlen( $title ) <= $max ) {
			return self::result( $title, true, '' );
		}

		$attempts = max( 1, (int) LAA_Settings::get( 'title_rewrite_attempts', 2 ) );
		$original = $title;
		$best     = $title;
		$last     = '';

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$parsed = $client->json(
				self::SYSTEM_PROMPT,
				self::build_prompt( $original, $max, $context, $i, $last ),
				300,
				0.4
			);

			if ( ! is_array( $parsed ) || empty( $parsed['title'] ) ) {
				$last = $client->last_error() ?: 'модель не вернула заголовок';
				continue;
			}

			$candidate = self::normalise( $parsed['title'] );
			$rejection = self::reject_reason( $candidate, $original, $max );

			if ( '' === $rejection ) {
				return self::result(
					$candidate,
					true,
					sprintf( 'Заголовок сокращён моделью с %d до %d символов (попыток: %d)', mb_strlen( $original ), mb_strlen( $candidate ), $i )
				);
			}

			$last = $rejection;

			// Keep the shortest sane-looking attempt: if every try overshoots,
			// a 260-char rewrite still beats the 400-char original.
			if ( mb_strlen( $candidate ) > 0 && mb_strlen( $candidate ) < mb_strlen( $best ) && ! self::looks_truncated( $candidate, $original ) ) {
				$best = $candidate;
			}
		}

		$note = sprintf(
			'Заголовок %d символов — больше предела %d, сократить не удалось (%s). Оставлен полным, обрезка не применялась.',
			mb_strlen( $best ),
			$max,
			$last ?: 'причина неизвестна'
		);

		return self::result( $best, false, $note );
	}

	private static function build_prompt( $title, $max, array $context, $attempt, $previous_problem ) {
		$parts   = array();
		$parts[] = 'Заголовок: ' . $title;
		$parts[] = sprintf( 'Сейчас в нём %d символов, нужно не больше %d.', mb_strlen( $title ), $max );

		if ( ! empty( $context['keyword'] ) ) {
			$parts[] = 'Главный ключ, который обязан остаться: ' . $context['keyword'];
		}
		if ( ! empty( $context['audience'] ) ) {
			$parts[] = 'Аудитория: ' . $context['audience'];
		}

		if ( $attempt > 1 && '' !== $previous_problem ) {
			$parts[] = 'Предыдущая попытка не подошла: ' . $previous_problem . '. Сократи сильнее.';
		}

		return implode( "\n", $parts );
	}

	private static function normalise( $title ) {
		$title = wp_strip_all_tags( (string) $title );
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		// Models like to wrap the whole thing in quotes AND end with a period,
		// in that order — «…почты». — so the period has to come off before the
		// closing quote can be reached.
		$title = trim( $title, " \t\"'«»" );
		$title = rtrim( $title, " .\"'«»" );
		return trim( $title );
	}

	/** @return string empty when the candidate is acceptable. */
	private static function reject_reason( $candidate, $original, $max ) {
		if ( '' === $candidate ) {
			return 'пустой заголовок';
		}
		if ( mb_strlen( $candidate ) > $max ) {
			return sprintf( 'получилось %d символов вместо %d', mb_strlen( $candidate ), $max );
		}
		if ( self::looks_truncated( $candidate, $original ) ) {
			return 'заголовок просто обрезан, а не переписан';
		}
		if ( mb_strlen( $candidate ) < 15 ) {
			return 'заголовок стал бессмысленно коротким';
		}
		return '';
	}

	/**
	 * The model sometimes "shortens" by handing back a prefix of the original.
	 * That's the truncation we were asked to avoid, so it counts as a failure
	 * — unless the cut happens to fall on a word boundary and ends on a
	 * complete clause, which is indistinguishable from a real edit.
	 */
	private static function looks_truncated( $candidate, $original ) {
		if ( 0 !== mb_strpos( $original, $candidate ) ) {
			return false; // not a prefix — a genuine rewrite
		}

		$next = mb_substr( $original, mb_strlen( $candidate ), 1 );
		return '' !== $next && ! preg_match( '/[\s,:;—–\-]/u', $next );
	}

	private static function result( $title, $ok, $note ) {
		return array(
			'title' => $title,
			'ok'    => (bool) $ok,
			'note'  => $note,
		);
	}
}

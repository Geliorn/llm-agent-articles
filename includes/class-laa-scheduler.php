<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three ways to trigger the same tick, exactly as in llm-agent-github-repos:
 *  1) WP-Cron on a custom 10-minute schedule (default, zero setup);
 *  2) an HTTP endpoint guarded by a secret (?articles_cron=...), for an
 *     external cron-ping service or a real crontab with curl/wget;
 *  3) WP-CLI (`wp laa tick`), see class-laa-cli.php — plus cron.php for
 *     hosts where the site can't curl itself.
 * All of them end in run_tick(), so the due-plan logic lives in one place.
 */
class LAA_Scheduler {

	const CRON_HOOK              = 'laa_tick_event';
	const TICK_RUNNING_TRANSIENT = 'laa_tick_running';
	const ENDPOINT_THROTTLE      = 'laa_endpoint_throttle';
	const HTTP_QUERY_PARAM       = 'articles_cron';

	/** Writing an article is minutes of HTTP waits, so the lock is generous. */
	const TICK_LOCK_SECONDS  = 25 * MINUTE_IN_SECONDS;
	const PLAN_LOCK_SECONDS  = 30 * MINUTE_IN_SECONDS;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_http_trigger' ), 5 );
		// Self-heal: ensure the recurring tick is scheduled whenever the plugin
		// is active. activate() alone is not enough — an in-place update (files
		// replaced without re-activation), a missed activation hook or a cleared
		// cron entry would otherwise leave the tick unscheduled and silent.
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/** Schedule the recurring tick if it isn't already (cheap autoloaded check). */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'laa_10_minutes', self::CRON_HOOK );
		}
	}

	public static function add_schedule( $schedules ) {
		$schedules['laa_10_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Каждые 10 минут (AI Article Writer)',
		);
		return $schedules;
	}

	public static function activate() {
		self::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Handles `?articles_cron=SECRET`. Checks the raw superglobal rather than
	 * a registered query var — simpler, and it can't interact with rewrites.
	 */
	public static function maybe_handle_http_trigger() {
		if ( ! isset( $_GET[ self::HTTP_QUERY_PARAM ] ) ) {
			return;
		}

		$provided = (string) $_GET[ self::HTTP_QUERY_PARAM ]; // phpcs:ignore WordPress.Security.NonceVerification
		$secret   = (string) LAA_Settings::get( 'cron_secret' );

		if ( '' === $secret || ! hash_equals( $secret, $provided ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}

		if ( get_transient( self::ENDPOINT_THROTTLE ) ) {
			exit( 'throttled' );
		}
		set_transient( self::ENDPOINT_THROTTLE, 1, MINUTE_IN_SECONDS );

		self::run_tick();
		exit( 'OK' );
	}

	public static function get_trigger_url() {
		return add_query_arg( self::HTTP_QUERY_PARAM, LAA_Settings::get( 'cron_secret' ), home_url( '/' ) );
	}

	public static function get_cron_php_path() {
		return LAA_PLUGIN_DIR . 'cron.php';
	}

	/** The three ready-to-copy commands shown on the settings screen. */
	public static function get_trigger_commands() {
		$url  = self::get_trigger_url();
		$path = self::get_cron_php_path();
		return array(
			'curl' => 'curl "' . $url . '"',
			'wget' => 'wget -O /dev/null "' . $url . '"',
			'php'  => 'php ' . $path,
		);
	}

	// ------------------------------------------------------------------ tick

	public static function run_tick() {
		if ( get_transient( self::TICK_RUNNING_TRANSIENT ) ) {
			return;
		}
		set_transient( self::TICK_RUNNING_TRANSIENT, 1, self::TICK_LOCK_SECONDS );

		try {
			LAA_Topics::release_stale();

			$plan_ids = get_posts(
				array(
					'post_type'      => LAA_CPT::PLAN,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_laa_enabled',
					'meta_value'     => '1',
				)
			);

			foreach ( $plan_ids as $plan_id ) {
				self::run_plan( $plan_id, false );
			}
		} finally {
			delete_transient( self::TICK_RUNNING_TRANSIENT );
		}
	}

	/**
	 * @param int  $plan_id
	 * @param bool $force Ignore the interval and the publishing window
	 *                    (the "Запустить сейчас" button).
	 * @return array{ok:bool,written:int,summary:string}
	 */
	public static function run_plan( $plan_id, $force = false ) {
		$meta  = LAA_Meta_Fields::get_plan_settings( $plan_id );
		$title = get_the_title( $plan_id );

		if ( ! $force ) {
			if ( ! self::is_due( $meta ) ) {
				return self::plan_result( true, 0, '' );
			}
			if ( ! self::in_window( $meta ) ) {
				return self::plan_result( true, 0, '' );
			}
		}

		$running_since = (int) $meta['running_since'];
		if ( $running_since && ( time() - $running_since ) < self::PLAN_LOCK_SECONDS ) {
			LAA_Log::add( "План «{$title}»: пропущен, предыдущий запуск ещё идёт" );
			return self::plan_result( false, 0, 'Предыдущий запуск ещё идёт' );
		}

		update_post_meta( $plan_id, '_laa_running_since', time() );

		$written = 0;
		$errors  = array();

		try {
			self::maybe_autofill_topics( $plan_id, $meta );

			$topics = LAA_Topics::next_approved( $plan_id, max( 1, (int) $meta['per_run'] ) );

			if ( empty( $topics ) ) {
				$summary = 'Нет одобренных тем в очереди';
				LAA_Log::add( "План «{$title}»: {$summary}" );
				update_post_meta( $plan_id, '_laa_last_run_summary', $summary );
				return self::plan_result( true, 0, $summary );
			}

			foreach ( $topics as $topic ) {
				if ( ! LAA_Topics::claim( (int) $topic['id'] ) ) {
					continue; // another tick got there first
				}

				$result = LAA_Publisher::write_topic( $topic );

				if ( $result['ok'] ) {
					LAA_Topics::set_status(
						(int) $topic['id'],
						LAA_Topics::DONE,
						array(
							'post_id'      => $result['post_id'],
							'last_error'   => '',
							'published_at' => current_time( 'mysql' ),
						)
					);
					++$written;
				} else {
					LAA_Topics::set_status( (int) $topic['id'], LAA_Topics::FAILED, array( 'last_error' => $result['error'] ) );
					$errors[] = $result['error'];
					LAA_Log::error( sprintf( 'План «%s», тема «%s»: %s', $title, $topic['title'], $result['error'] ) );
				}
			}
		} finally {
			update_post_meta( $plan_id, '_laa_running_since', 0 );
			update_post_meta( $plan_id, '_laa_last_run_at', time() );
		}

		$summary = sprintf( 'Написано статей: %d%s', $written, $errors ? '; ошибок: ' . count( $errors ) : '' );
		update_post_meta( $plan_id, '_laa_last_run_summary', $summary );
		LAA_Log::add( "План «{$title}»: {$summary}" );

		return self::plan_result( empty( $errors ), $written, $summary );
	}

	private static function maybe_autofill_topics( $plan_id, array $meta ) {
		if ( ! $meta['topics_autofill'] ) {
			return;
		}
		if ( LAA_Topics::count_approved( $plan_id ) > (int) $meta['topics_autofill_threshold'] ) {
			return;
		}
		// Without auto-approval the new rows land as `pending` and still wait
		// for a human — the autofill only guarantees there's something to approve.
		LAA_Topic_Generator::generate( $plan_id );
	}

	public static function is_due( array $meta ) {
		$interval = LAA_Meta_Fields::interval_minutes( $meta );
		$last_run = (int) $meta['last_run_at'];
		if ( ! $last_run ) {
			return true;
		}
		return ( time() - $last_run ) >= $interval * MINUTE_IN_SECONDS;
	}

	/** Weekday + time-of-day window, both in site time. */
	public static function in_window( array $meta ) {
		$now_weekday = (int) current_time( 'N' ); // 1 = Mon … 7 = Sun
		$weekdays    = array_filter( array_map( 'intval', explode( ',', (string) $meta['weekdays'] ) ) );
		if ( $weekdays && ! in_array( $now_weekday, $weekdays, true ) ) {
			return false;
		}

		$from = trim( (string) $meta['window_from'] );
		$to   = trim( (string) $meta['window_to'] );
		if ( '' === $from || '' === $to ) {
			return true;
		}

		$now = (int) current_time( 'H' ) * 60 + (int) current_time( 'i' );
		$a   = self::minutes_of_day( $from );
		$b   = self::minutes_of_day( $to );

		if ( null === $a || null === $b ) {
			return true;
		}
		// A window like 22:00–06:00 wraps past midnight.
		return $a <= $b ? ( $now >= $a && $now <= $b ) : ( $now >= $a || $now <= $b );
	}

	private static function minutes_of_day( $value ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	private static function plan_result( $ok, $written, $summary ) {
		return array(
			'ok'      => $ok,
			'written' => $written,
			'summary' => $summary,
		);
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the one custom table this plugin needs — the topic queue. Everything
 * else (plans, settings, presets) lives in post meta / options, as in the
 * other plugins of the network; the queue is the exception because it's an
 * ordered work list with statuses that a human bulk-edits on one screen.
 */
class LAA_Install {

	const DB_VERSION_OPTION = 'laa_db_version';
	const DB_VERSION        = 3;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'laa_topics';
	}

	public static function activate() {
		self::create_table();
		if ( false === get_option( LAA_Settings::OPTION_KEY ) ) {
			add_option( LAA_Settings::OPTION_KEY, LAA_Settings::defaults() );
		}
		if ( false === get_option( LAA_Image_Presets::OPTION_KEY ) ) {
			add_option( LAA_Image_Presets::OPTION_KEY, LAA_Image_Presets::seed_presets() );
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Runs on every load but does real work only when the stored version lags. */
	public static function maybe_upgrade() {
		$stored = (int) get_option( self::DB_VERSION_OPTION );
		if ( $stored === self::DB_VERSION ) {
			return;
		}

		self::create_table();

		if ( $stored > 0 && $stored < 3 ) {
			self::migrate_cover_ratio();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * 0.1.0 shipped covers at 1200×630 while the theme displays the featured
	 * image at 3:2 — the browser cropped ~127px off each side with
	 * object-fit:cover and clipped the first letter of every caption line.
	 * Retargets presets that still carry that exact default; a size the admin
	 * chose themselves is left alone.
	 */
	private static function migrate_cover_ratio() {
		$presets = get_option( LAA_Image_Presets::OPTION_KEY, array() );
		if ( ! is_array( $presets ) || empty( $presets ) ) {
			return;
		}

		$changed = array();
		foreach ( $presets as $id => $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			if ( 1200 === (int) ( $preset['width'] ?? 0 ) && 630 === (int) ( $preset['height'] ?? 0 ) ) {
				$presets[ $id ]['height'] = 800;
				$changed[]                = $id;
			}
		}

		if ( empty( $changed ) ) {
			return;
		}

		update_option( LAA_Image_Presets::OPTION_KEY, $presets );
		LAA_Log::add(
			'Обновление: размер обложки в пресетах (' . implode( ', ', $changed ) . ') изменён с 1200×630 на 1200×800 — '
			. 'тема выводит миниатюру в пропорции 3:2 и обрезала подпись по бокам. '
			. 'Уже опубликованные обложки перерисовываются командой wp laa recover <ID>.'
		);
	}

	private static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// title_hash, not title, carries the UNIQUE key: MySQL can't index a
		// TEXT column without a prefix length, and a prefix would make
		// near-identical long topics collide.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title TEXT NOT NULL,
			title_hash CHAR(32) NOT NULL DEFAULT '',
			keywords TEXT NULL,
			angle TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			sort_order INT NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			attempts SMALLINT NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'llm',
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			published_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY plan_title (plan_id, title_hash),
			KEY status_order (status, sort_order),
			KEY plan_status (plan_id, status)
		) {$collate};";

		dbDelta( $sql );
	}

	/** Called from uninstall.php only. */
	public static function drop_everything() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		delete_option( self::DB_VERSION_OPTION );
		delete_option( LAA_Settings::OPTION_KEY );
		delete_option( LAA_Image_Presets::OPTION_KEY );
		delete_option( LAA_Log::OPTION_KEY );
		// Literal keys: uninstall.php doesn't load the infographic classes.
		delete_option( 'laa_infographic_presets' );
		delete_option( 'laa_infographic_theme_preset_added' );
		delete_option( 'laa_palette_cursor' );
	}
}

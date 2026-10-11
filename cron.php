<?php
/**
 * Standalone cron entry point — loads wp-load.php directly, bypassing WP-CLI.
 * The third fallback trigger, for hosts where the site can't curl itself or
 * where WP-CLI's own PHP binary resolution is unreliable.
 *
 * Usage: php /path/to/wp-content/plugins/llm-agent-articles/cron.php
 */

require_once __DIR__ . '/../../../wp-load.php';

if ( 'cli' !== php_sapi_name() ) {
	// Hit directly over HTTP rather than via the home-URL query param —
	// still requires the secret.
	$provided = isset( $_GET['articles_cron'] ) ? (string) $_GET['articles_cron'] : '';
	$secret   = (string) LAA_Settings::get( 'cron_secret' );

	if ( '' === $secret || ! hash_equals( $secret, $provided ) ) {
		status_header( 403 );
		exit( 'forbidden' );
	}
	header( 'Content-Type: text/plain' );
}

LAA_Scheduler::run_tick();
echo "OK\n";

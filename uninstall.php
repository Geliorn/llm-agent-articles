<?php
/**
 * Removes the queue table and every option the plugin created. Generated
 * posts and media are deliberately left alone — they're site content, not
 * plugin data.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-laa-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-laa-log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-laa-image-presets.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-laa-install.php';

LAA_Install::drop_everything();

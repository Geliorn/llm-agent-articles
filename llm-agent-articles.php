<?php
/**
 * Plugin Name: AI Article Writer
 * Description: Контент-конвейер: по заданной теме предлагает список тем статей, человек его согласовывает, дальше плагин по расписанию пишет статью по SEO-правилам (структура заголовков, абзацы, списки, таблицы, цитаты, FAQ), делает обложку (генерация или библиотека + наложение) и публикует. Самодостаточен — свои типы записей, своя очередь, без ACF/CPT UI.
 * Version: 0.4.0
 * Author: llm-agent.ru
 * Text Domain: laa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LAA_VERSION', '0.4.0' );
define( 'LAA_PLUGIN_FILE', __FILE__ );
define( 'LAA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once LAA_PLUGIN_DIR . 'includes/class-laa-install.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-settings.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-log.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-cpt.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-meta-fields.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-topics.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-llm-client.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-topic-generator.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-article-generator.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-article-builder.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-title-shortener.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-seo-checker.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-seo.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-faq.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-links.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-image-presets.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-image-composer.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-image-generator.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-image-service.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-infographic-presets.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-infographic-composer.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-theses-generator.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-infographic-service.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-publisher.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-scheduler.php';
require_once LAA_PLUGIN_DIR . 'includes/class-laa-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LAA_PLUGIN_DIR . 'includes/class-laa-cli.php';
}

/**
 * Boot — every class hooks itself in its own init(), same convention as the
 * other plugins of the network (see llm-agent-github-repos).
 */
function laa_boot() {
	LAA_Install::maybe_upgrade();
	LAA_CPT::init();
	LAA_Meta_Fields::init();
	LAA_Settings::init();
	LAA_Log::init();
	LAA_Scheduler::init();
	LAA_Admin::init();
	LAA_FAQ::init();
}
add_action( 'plugins_loaded', 'laa_boot' );

function laa_activate() {
	LAA_Install::activate();
	LAA_CPT::register_post_types();
	LAA_Scheduler::activate();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'laa_activate' );

function laa_deactivate() {
	LAA_Scheduler::deactivate();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'laa_deactivate' );

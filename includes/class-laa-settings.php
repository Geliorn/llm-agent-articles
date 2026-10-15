<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All plugin-wide settings in one option array. Per-plan settings live as
 * post meta on laa_plan (see LAA_Meta_Fields); overlay presets live in their
 * own option (see LAA_Image_Presets) so they can be exported between sites.
 */
class LAA_Settings {

	const OPTION_KEY = 'laa_settings';

	public static function defaults() {
		return array(
			// ---------------------------------------------------------- LLM
			'llm_provider'          => 'deepseek',
			'yandex_api_key'        => '',
			'yandex_folder_id'      => '',
			'yandex_model'          => 'deepseek-v32/latest',
			'deepseek_api_key'      => '',
			'deepseek_model'        => 'deepseek-chat',
			'qwen_api_key'          => '',
			'qwen_model'            => 'qwen-plus',
			'qwen_base_url'         => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
			'timeweb_agent_id'      => '',
			'timeweb_token'         => '',
			'timeweb_proxy_source'  => '',
			'openrouter_api_key'    => '',
			'openrouter_model'      => 'deepseek/deepseek-chat',
			'llm_timeout'           => 120,
			'llm_temperature_plan'  => 0.7,
			'llm_temperature_body'  => 0.6,

			// ------------------------------------------------- images: mode
			'image_mode'            => 'library', // generate | library | none
			'image_provider'        => 'yandex_art', // yandex_art | openai
			'image_fallback_library' => 1, // on a generation failure, fall back to the library path

			'yandex_art_model'      => 'yandex-art/latest',
			'openai_images_endpoint' => 'https://api.openai.com/v1/images/generations',
			'openai_images_key'     => '',
			'openai_images_model'   => 'gpt-image-1',
			'openai_images_size'    => '1536x1024',

			'image_prompt_template' => 'Горизонтальная обложка для статьи «{title}». Тема: {theme}. Минималистичная редакционная иллюстрация, плоская геометрия, спокойная палитра, без текста и без логотипов, без людей крупным планом.',
			'image_negative_prompt' => 'текст, надписи, буквы, водяные знаки, логотипы, подписи, артефакты',

			// ---------------------------------------------- images: library
			'library_source'        => 'ids', // ids | term | folder
			'library_ids'           => '',    // comma-separated attachment IDs
			'library_term'          => '',    // taxonomy:term_id of attachments
			'library_folder'        => '',    // path inside uploads, e.g. "covers"
			'library_strategy'      => 'round_robin', // random | round_robin | by_keyword
			'library_cursor'        => 0,     // round-robin pointer
			'default_preset'        => 'default',
			'caption_font_path'     => '',    // explicit TTF/OTF path; empty = autodetect

			// ----------------------------------------------- publishing/SEO
			'seo_plugin'            => 'yoast', // yoast | rankmath | none
			// Hard ceiling on any title the model produces (article H1, topic in
			// the queue, H2/H3, FAQ question). Not an SEO target — the prompts
			// ask for ~70 chars — but a guard: nothing in the code used to stop
			// a model from returning a 400-character heading.
			// ----------------------------------------------------- infographic
			'infographic_enabled'       => 0,
			'infographic_default_preset' => 'grid',
			// cover | start | end — a plan can override it (LAA_Publisher::infographic_placement()).
			'infographic_placement'     => 'cover',

			'title_max_chars'       => 250,
			// The SERP shows ~60 characters, so meta_title gets its own, much
			// tighter limit — the H1 on the page stays full and meaningful.
			'meta_title_max_chars'  => 60,
			// How many times to ask the model to rewrite an over-long title
			// before giving up and keeping it full.
			'title_rewrite_attempts' => 2,
			'checker_enforce'       => 1,       // below thresholds -> draft instead of publish
			'min_words_hard'        => 700,
			'min_sections_hard'     => 3,
			'cron_secret'           => '',
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_generate_secret' ) );
	}

	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/** Infographic placements for the settings screen and the plan box. */
	public static function infographic_placements() {
		return array(
			'cover' => 'Обложкой (вместо обычной обложки)',
			'start' => 'В начале статьи, после лида',
			'end'   => 'В конце статьи, после FAQ',
		);
	}

	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return $all[ $key ] ?? $default;
	}

	public static function update( array $values ) {
		$all = self::get_all();
		foreach ( $values as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( self::OPTION_KEY, $all );
	}

	public static function maybe_generate_secret() {
		if ( '' === (string) self::get( 'cron_secret' ) ) {
			self::regenerate_secret();
		}
	}

	public static function regenerate_secret() {
		$secret = bin2hex( random_bytes( 16 ) );
		self::update( array( 'cron_secret' => $secret ) );
		return $secret;
	}

	public static function llm_providers() {
		return array(
			'yandex'     => 'Yandex Foundation Models',
			'deepseek'   => 'DeepSeek напрямую',
			'qwen'       => 'Qwen (Alibaba DashScope)',
			'timeweb'    => 'Timeweb (TWC AI API)',
			'openrouter' => 'OpenRouter',
		);
	}

	/**
	 * OpenAI-compatible provider presets — endpoint + auth + model resolution.
	 * Lifted from LAGR_Settings::llm_provider_config(): all four providers
	 * speak the same chat/completions shape, only these three differ.
	 */
	public static function llm_provider_config( $provider = null ) {
		$s        = self::get_all();
		$provider = $provider && 'inherit' !== $provider ? $provider : $s['llm_provider'];

		switch ( $provider ) {
			case 'yandex':
				return array(
					'provider' => 'yandex',
					'endpoint' => 'https://ai.api.cloud.yandex.net/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Api-Key ' . $s['yandex_api_key'],
						'x-folder-id'   => $s['yandex_folder_id'],
					),
					'model'    => 'gpt://' . $s['yandex_folder_id'] . '/' . ltrim( $s['yandex_model'], '/' ),
				);
			case 'timeweb':
				return array(
					'provider' => 'timeweb',
					'endpoint' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/' . rawurlencode( $s['timeweb_agent_id'] ) . '/v1/chat/completions',
					'headers'  => array(
						'Authorization'  => 'Bearer ' . $s['timeweb_token'],
						'x-proxy-source' => $s['timeweb_proxy_source'],
					),
					'model'    => 'gpt-4', // ignored by Timeweb — the agent's own model is used.
				);
			case 'openrouter':
				return array(
					'provider' => 'openrouter',
					'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['openrouter_api_key'],
					),
					'model'    => $s['openrouter_model'],
				);
			case 'qwen':
				return array(
					'provider' => 'qwen',
					'endpoint' => rtrim( $s['qwen_base_url'], '/' ) . '/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['qwen_api_key'],
					),
					'model'    => $s['qwen_model'],
				);
			case 'deepseek':
			default:
				return array(
					'provider' => 'deepseek',
					'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['deepseek_api_key'],
					),
					'model'    => $s['deepseek_model'],
				);
		}
	}

	/** True when the configured LLM provider actually has its credentials filled in. */
	public static function llm_ready( $provider = null ) {
		$s        = self::get_all();
		$provider = $provider && 'inherit' !== $provider ? $provider : $s['llm_provider'];

		switch ( $provider ) {
			case 'yandex':
				return '' !== $s['yandex_api_key'] && '' !== $s['yandex_folder_id'];
			case 'timeweb':
				return '' !== $s['timeweb_agent_id'] && '' !== $s['timeweb_token'];
			case 'openrouter':
				return '' !== $s['openrouter_api_key'];
			case 'qwen':
				return '' !== $s['qwen_api_key'];
			default:
				return '' !== $s['deepseek_api_key'];
		}
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu: the topic queue (landing page), the content plans (CPT screens
 * attach themselves here), the settings, the cover presets with a live
 * preview, and the log.
 */
class LAA_Admin {

	const MAIN_SLUG     = 'laa-main';
	const SETTINGS_SLUG = 'laa-settings';
	const IMAGES_SLUG   = 'laa-images';
	const INFOGRAPHIC_SLUG = 'laa-infographic';
	const LOG_SLUG      = 'laa-log';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'admin_post_laa_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_laa_regenerate_secret', array( __CLASS__, 'handle_regenerate_secret' ) );
		add_action( 'admin_post_laa_generate_topics', array( __CLASS__, 'handle_generate_topics' ) );
		add_action( 'admin_post_laa_run_plan', array( __CLASS__, 'handle_run_plan' ) );
		add_action( 'admin_post_laa_write_topic', array( __CLASS__, 'handle_write_topic' ) );
		add_action( 'admin_post_laa_topic_status', array( __CLASS__, 'handle_topic_status' ) );
		add_action( 'admin_post_laa_delete_topic', array( __CLASS__, 'handle_delete_topic' ) );
		add_action( 'admin_post_laa_add_topics', array( __CLASS__, 'handle_add_topics' ) );
		add_action( 'admin_post_laa_update_topic', array( __CLASS__, 'handle_update_topic' ) );
		add_action( 'admin_post_laa_save_preset', array( __CLASS__, 'handle_save_preset' ) );
		add_action( 'admin_post_laa_delete_preset', array( __CLASS__, 'handle_delete_preset' ) );
		add_action( 'admin_post_laa_upload_font', array( __CLASS__, 'handle_upload_font' ) );
		add_action( 'admin_post_laa_clear_log', array( __CLASS__, 'handle_clear_log' ) );

		add_action( 'admin_post_laa_save_infographic_preset', array( __CLASS__, 'handle_save_infographic_preset' ) );
		add_action( 'admin_post_laa_delete_infographic_preset', array( __CLASS__, 'handle_delete_infographic_preset' ) );

		add_action( 'wp_ajax_laa_preview_image', array( __CLASS__, 'ajax_preview_image' ) );
		add_action( 'wp_ajax_laa_preview_infographic', array( __CLASS__, 'ajax_preview_infographic' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'AI Article Writer',
			'AI-статьи',
			'edit_posts',
			self::MAIN_SLUG,
			array( __CLASS__, 'render_queue_page' ),
			'dashicons-edit-page',
			26
		);
		// Relabels the auto-added first submenu (same slug as the parent).
		add_submenu_page( self::MAIN_SLUG, 'Очередь тем', 'Очередь тем', 'edit_posts', self::MAIN_SLUG, array( __CLASS__, 'render_queue_page' ) );
		add_submenu_page( self::MAIN_SLUG, 'Настройки', 'Настройки', 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'render_settings_page' ) );
		add_submenu_page( self::MAIN_SLUG, 'Обложки', 'Обложки', 'manage_options', self::IMAGES_SLUG, array( __CLASS__, 'render_images_page' ) );
		add_submenu_page( self::MAIN_SLUG, 'Инфографика', 'Инфографика', 'manage_options', self::INFOGRAPHIC_SLUG, array( __CLASS__, 'render_infographic_page' ) );
		add_submenu_page( self::MAIN_SLUG, 'Журнал', 'Журнал', 'manage_options', self::LOG_SLUG, array( __CLASS__, 'render_log_page' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'laa-' ) && LAA_CPT::PLAN !== get_post_type() ) {
			return;
		}
		wp_enqueue_style( 'laa-admin', LAA_PLUGIN_URL . 'assets/css/admin.css', array(), LAA_VERSION );

		if ( false !== strpos( (string) $hook, self::INFOGRAPHIC_SLUG ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'laa-images', LAA_PLUGIN_URL . 'assets/js/laa-images.js', array( 'jquery' ), LAA_VERSION, true );
			wp_localize_script(
				'laa-images',
				'laaImages',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'laa_preview_infographic' ),
					'action'  => 'laa_preview_infographic',
				)
			);
		} elseif ( false !== strpos( (string) $hook, self::IMAGES_SLUG ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'laa-images', LAA_PLUGIN_URL . 'assets/js/laa-images.js', array( 'jquery' ), LAA_VERSION, true );
			wp_localize_script(
				'laa-images',
				'laaImages',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'laa_preview_image' ),
					'action'  => 'laa_preview_image',
				)
			);
		}
	}

	// ------------------------------------------------------------- helpers

	public static function action_url( $action, array $args = array() ) {
		$url = add_query_arg(
			array_merge( array( 'action' => $action ), $args ),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, $action );
	}

	public static function queue_url( array $args = array() ) {
		return add_query_arg(
			array_filter(
				array_merge( array( 'page' => self::MAIN_SLUG ), $args ),
				function ( $value ) {
					return '' !== $value && null !== $value;
				}
			),
			admin_url( 'admin.php' )
		);
	}

	private static function redirect_back( $notice = '', $type = 'success' ) {
		$fallback = self::queue_url();
		$target   = wp_get_referer() ?: $fallback;

		if ( '' !== $notice ) {
			$target = add_query_arg(
				array(
					'laa_notice' => $notice,
					'laa_type'   => $type,
				),
				remove_query_arg( array( 'laa_notice', 'laa_type' ), $target )
			);
		}

		wp_safe_redirect( $target );
		exit;
	}

	private static function print_notice() {
		if ( ! isset( $_GET['laa_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$type  = isset( $_GET['laa_type'] ) && 'error' === $_GET['laa_type'] ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification
		// add_query_arg() already url-encodes the value and PHP decodes $_GET,
		// so no manual encode/decode pair here — doing both double-encodes.
		$text  = sanitize_text_field( wp_unslash( $_GET['laa_notice'] ) );                         // phpcs:ignore WordPress.Security.NonceVerification
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	private static function require_cap( $cap = 'manage_options' ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( 'Недостаточно прав.' );
		}
	}

	// --------------------------------------------------------- queue screen

	public static function render_queue_page() {
		self::require_cap( 'edit_posts' );
		require_once LAA_PLUGIN_DIR . 'includes/class-laa-topics-list-table.php';

		// phpcs:disable WordPress.Security.NonceVerification
		$plan_id = isset( $_GET['plan_id'] ) ? sanitize_text_field( wp_unslash( $_GET['plan_id'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		// phpcs:enable

		$plans = get_posts(
			array(
				'post_type'      => LAA_CPT::PLAN,
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$table = new LAA_Topics_List_Table( $plan_id, $status, $search );
		self::maybe_handle_bulk( $table );
		$table->prepare_items();

		echo '<div class="wrap laa-wrap"><h1 class="wp-heading-inline">Очередь тем</h1> ';
		printf(
			'<a href="%s" class="page-title-action">Новый контент-план</a>',
			esc_url( admin_url( 'post-new.php?post_type=' . LAA_CPT::PLAN ) )
		);
		echo '<hr class="wp-header-end">';

		self::print_notice();
		self::render_readiness_notices();

		if ( $edit_id ) {
			self::render_edit_form( $edit_id, $plan_id, $status );
		}

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::MAIN_SLUG ) );
		if ( '' !== $status ) {
			printf( '<input type="hidden" name="status" value="%s">', esc_attr( $status ) );
		}

		echo '<div class="alignleft actions"><select name="plan_id">';
		echo '<option value="">Все планы</option>';
		foreach ( $plans as $plan ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $plan->ID,
				selected( (string) $plan->ID, (string) $plan_id, false ),
				esc_html( $plan->post_title )
			);
		}
		echo '</select> ';
		submit_button( 'Фильтр', 'secondary', 'filter', false );
		echo '</div>';

		$table->search_box( 'Поиск по темам', 'laa-topic-search' );
		echo '</form>';

		$table->views();

		// Posts back to this same screen (query string included), the way core's
		// list screens do. It must NOT carry a hidden `action` field: the bulk
		// dropdown is also named `action`, and the later one would win.
		echo '<form method="post">';
		$table->display();
		echo '</form>';

		self::render_add_topics_form( $plans, $plan_id );

		echo '</div>';
	}

	/** Up-front warnings beat a run that fails silently at 3am. */
	private static function render_readiness_notices() {
		if ( ! LAA_Settings::llm_ready() ) {
			printf(
				'<div class="notice notice-warning"><p>Не заданы ключи доступа к модели — ни темы, ни статьи сгенерировать не получится. <a href="%s">Настройки</a></p></div>',
				esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) )
			);
		}

		$mode = LAA_Settings::get( 'image_mode' );
		if ( 'library' === $mode && empty( LAA_Image_Service::pool() ) ) {
			printf(
				'<div class="notice notice-warning"><p>Режим обложек — «из библиотеки», но пул картинок пуст. <a href="%s">Указать источник</a></p></div>',
				esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) )
			);
		}
	}

	private static function render_edit_form( $topic_id, $plan_id, $status ) {
		$topic = LAA_Topics::get( $topic_id );
		if ( ! $topic ) {
			return;
		}
		?>
		<div class="laa-panel">
			<h2>Правка темы</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'laa_update_topic' ); ?>
				<input type="hidden" name="action" value="laa_update_topic">
				<input type="hidden" name="topic_id" value="<?php echo (int) $topic['id']; ?>">
				<input type="hidden" name="plan_id_filter" value="<?php echo esc_attr( $plan_id ); ?>">
				<input type="hidden" name="status_filter" value="<?php echo esc_attr( $status ); ?>">
				<table class="form-table">
					<tr><th><label for="laa_edit_title">Тема</label></th>
						<td><input id="laa_edit_title" type="text" name="title" class="large-text" value="<?php echo esc_attr( $topic['title'] ); ?>"></td></tr>
					<tr><th><label for="laa_edit_keywords">Ключевые фразы</label></th>
						<td><input id="laa_edit_keywords" type="text" name="keywords" class="large-text" value="<?php echo esc_attr( $topic['keywords'] ); ?>"></td></tr>
					<tr><th><label for="laa_edit_angle">Ракурс</label></th>
						<td><textarea id="laa_edit_angle" name="angle" rows="3" class="large-text"><?php echo esc_textarea( $topic['angle'] ); ?></textarea></td></tr>
					<tr><th><label for="laa_edit_status">Статус</label></th>
						<td><select id="laa_edit_status" name="status">
							<?php foreach ( LAA_Topics::statuses() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $topic['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th><label for="laa_edit_order">Порядок</label></th>
						<td><input id="laa_edit_order" type="number" name="sort_order" value="<?php echo (int) $topic['sort_order']; ?>" style="width:100px">
							<span class="description">Меньше — раньше в очереди.</span></td></tr>
				</table>
				<?php submit_button( 'Сохранить тему' ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_add_topics_form( array $plans, $plan_id ) {
		?>
		<div class="laa-panel">
			<h2>Добавить свои темы</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'laa_add_topics' ); ?>
				<input type="hidden" name="action" value="laa_add_topics">
				<p>
					<label>План:
						<select name="plan_id">
							<option value="0">Без плана</option>
							<?php foreach ( $plans as $plan ) : ?>
								<option value="<?php echo (int) $plan->ID; ?>" <?php selected( (string) $plan->ID, (string) $plan_id ); ?>><?php echo esc_html( $plan->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					&nbsp;
					<label><input type="checkbox" name="approved" value="1" checked> сразу одобренными</label>
				</p>
				<p>
					<textarea name="topics" rows="5" class="large-text code" placeholder="Одна тема на строку.&#10;Можно так: Тема статьи | ключ один, ключ два | что раскрыть"></textarea>
				</p>
				<p class="description">Одна тема на строку. Необязательные поля через вертикальную черту: <code>тема | ключевые фразы | ракурс</code>.</p>
				<?php submit_button( 'Добавить темы', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	// ------------------------------------------------------ settings screen

	public static function render_settings_page() {
		self::require_cap();
		$s        = LAA_Settings::get_all();
		$commands = LAA_Scheduler::get_trigger_commands();
		$presets  = LAA_Image_Presets::get_all();
		?>
		<div class="wrap laa-wrap">
			<h1>Настройки — AI Article Writer</h1>
			<?php self::print_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'laa_save_settings' ); ?>
				<input type="hidden" name="action" value="laa_save_settings">

				<h2>Модель для текста</h2>
				<table class="form-table">
					<tr><th>Провайдер по умолчанию</th><td>
						<select name="llm_provider">
							<?php foreach ( LAA_Settings::llm_providers() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['llm_provider'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description">Отдельный план может использовать другого провайдера.</span>
					</td></tr>
					<tr><th>Таймаут запроса</th><td><input type="number" name="llm_timeout" min="30" max="600" value="<?php echo esc_attr( $s['llm_timeout'] ); ?>" style="width:90px"> сек</td></tr>
					<tr><th>Температура</th><td>
						план статьи <input type="text" name="llm_temperature_plan" value="<?php echo esc_attr( $s['llm_temperature_plan'] ); ?>" style="width:60px">
						· текст разделов <input type="text" name="llm_temperature_body" value="<?php echo esc_attr( $s['llm_temperature_body'] ); ?>" style="width:60px">
					</td></tr>
				</table>

				<h3>Yandex Foundation Models</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="yandex_api_key" value="<?php echo esc_attr( $s['yandex_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Folder ID</th><td><input type="text" name="yandex_folder_id" value="<?php echo esc_attr( $s['yandex_folder_id'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="yandex_model" value="<?php echo esc_attr( $s['yandex_model'] ); ?>" style="width:400px"></td></tr>
				</table>

				<h3>DeepSeek напрямую</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="deepseek_api_key" value="<?php echo esc_attr( $s['deepseek_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="deepseek_model" value="<?php echo esc_attr( $s['deepseek_model'] ); ?>" style="width:400px"></td></tr>
				</table>

				<h3>Qwen (Alibaba DashScope)</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="qwen_api_key" value="<?php echo esc_attr( $s['qwen_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="qwen_model" value="<?php echo esc_attr( $s['qwen_model'] ); ?>" style="width:400px"> <span class="description">напр. qwen-plus, qwen-max, qwen-turbo</span></td></tr>
					<tr><th>Base URL</th><td><input type="text" name="qwen_base_url" value="<?php echo esc_attr( $s['qwen_base_url'] ); ?>" style="width:400px"> <span class="description">compatible-mode host DashScope (междунар. или КНР)</span></td></tr>
				</table>

				<h3>Timeweb (TWC AI API)</h3>
				<table class="form-table">
					<tr><th>Agent Access ID</th><td><input type="text" name="timeweb_agent_id" value="<?php echo esc_attr( $s['timeweb_agent_id'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Token</th><td><input type="password" name="timeweb_token" value="<?php echo esc_attr( $s['timeweb_token'] ); ?>" style="width:400px"></td></tr>
					<tr><th>x-proxy-source</th><td><input type="text" name="timeweb_proxy_source" value="<?php echo esc_attr( $s['timeweb_proxy_source'] ); ?>" style="width:400px"></td></tr>
				</table>

				<h3>OpenRouter</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="openrouter_api_key" value="<?php echo esc_attr( $s['openrouter_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="openrouter_model" value="<?php echo esc_attr( $s['openrouter_model'] ); ?>" style="width:400px"></td></tr>
				</table>

				<h2>Обложки</h2>
				<table class="form-table">
					<tr><th>Режим по умолчанию</th><td>
						<select name="image_mode">
							<option value="generate" <?php selected( $s['image_mode'], 'generate' ); ?>>Генерировать нейросетью</option>
							<option value="library" <?php selected( $s['image_mode'], 'library' ); ?>>Из библиотеки + наложение</option>
							<option value="none" <?php selected( $s['image_mode'], 'none' ); ?>>Без обложки</option>
						</select>
					</td></tr>
					<tr><th>Пресет наложения по умолчанию</th><td>
						<select name="default_preset">
							<?php foreach ( $presets as $id => $preset ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['default_preset'], $id ); ?>><?php echo esc_html( $preset['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::IMAGES_SLUG ) ); ?>">Настроить →</a>
					</td></tr>
					<tr><th>Если генерация не удалась</th><td>
						<label><input type="checkbox" name="image_fallback_library" value="1" <?php checked( $s['image_fallback_library'] ); ?>> взять картинку из библиотеки</label>
					</td></tr>
				</table>

				<h3>Генерация картинок</h3>
				<table class="form-table">
					<tr><th>Провайдер</th><td>
						<select name="image_provider">
							<?php foreach ( LAA_Image_Generator::providers() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['image_provider'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description">Yandex ART использует ключ и folder ID из блока Yandex выше.</span>
					</td></tr>
					<tr><th>Модель Yandex ART</th><td><input type="text" name="yandex_art_model" value="<?php echo esc_attr( $s['yandex_art_model'] ); ?>" style="width:400px"></td></tr>
					<tr><th>OpenAI-совместимый endpoint</th><td><input type="text" name="openai_images_endpoint" value="<?php echo esc_attr( $s['openai_images_endpoint'] ); ?>" style="width:400px"></td></tr>
					<tr><th>API Key</th><td><input type="password" name="openai_images_key" value="<?php echo esc_attr( $s['openai_images_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="openai_images_model" value="<?php echo esc_attr( $s['openai_images_model'] ); ?>" style="width:220px">
						размер <input type="text" name="openai_images_size" value="<?php echo esc_attr( $s['openai_images_size'] ); ?>" style="width:120px"></td></tr>
					<tr><th>Промпт</th><td>
						<textarea name="image_prompt_template" rows="3" class="large-text"><?php echo esc_textarea( $s['image_prompt_template'] ); ?></textarea>
						<p class="description">Подстановки: <code>{title}</code>, <code>{theme}</code>, <code>{keywords}</code>.</p>
					</td></tr>
					<tr><th>Негативный промпт</th><td><input type="text" name="image_negative_prompt" value="<?php echo esc_attr( $s['image_negative_prompt'] ); ?>" style="width:100%"></td></tr>
				</table>

				<h3>Библиотека картинок</h3>
				<table class="form-table">
					<tr><th>Источник</th><td>
						<select name="library_source">
							<option value="ids" <?php selected( $s['library_source'], 'ids' ); ?>>Список вложений по ID</option>
							<option value="term" <?php selected( $s['library_source'], 'term' ); ?>>Вложения определённого термина</option>
							<option value="folder" <?php selected( $s['library_source'], 'folder' ); ?>>Папка внутри uploads</option>
						</select>
					</td></tr>
					<tr><th>ID вложений</th><td><input type="text" name="library_ids" value="<?php echo esc_attr( $s['library_ids'] ); ?>" style="width:100%" placeholder="12, 15, 28">
						<p class="description">ID из медиабиблиотеки через запятую.</p></td></tr>
					<tr><th>Термин</th><td><input type="text" name="library_term" value="<?php echo esc_attr( $s['library_term'] ); ?>" style="width:300px" placeholder="media_category:12">
						<p class="description">Формат <code>taxonomy:term_id</code>.</p></td></tr>
					<tr><th>Папка в uploads</th><td><input type="text" name="library_folder" value="<?php echo esc_attr( $s['library_folder'] ); ?>" style="width:300px" placeholder="covers">
						<p class="description">Относительно <code><?php echo esc_html( wp_upload_dir()['basedir'] ); ?></code>. Файлы jpg/png/webp.</p></td></tr>
					<tr><th>Как выбирать</th><td>
						<select name="library_strategy">
							<option value="round_robin" <?php selected( $s['library_strategy'], 'round_robin' ); ?>>По кругу (не повторяется подряд)</option>
							<option value="random" <?php selected( $s['library_strategy'], 'random' ); ?>>Случайно</option>
							<option value="by_keyword" <?php selected( $s['library_strategy'], 'by_keyword' ); ?>>По совпадению слов заголовка с названием файла/alt</option>
						</select>
						<span class="description">Сейчас в пуле: <strong><?php echo count( LAA_Image_Service::pool() ); ?></strong></span>
					</td></tr>
				</table>

				<h2>Инфографика</h2>
				<table class="form-table">
					<tr><th>Делать по умолчанию</th><td>
						<label><input type="checkbox" name="infographic_enabled" value="1" <?php checked( $s['infographic_enabled'] ); ?>> собирать инфографику к каждой статье</label>
						<p class="description">Отдельный контент-план может это переопределить. Тезисы для инфографики — отдельный короткий запрос к модели.</p>
					</td></tr>
					<tr><th>Пресет по умолчанию</th><td>
						<select name="infographic_default_preset">
							<?php foreach ( LAA_Infographic_Presets::get_all() as $id => $item ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['infographic_default_preset'], $id ); ?>><?php echo esc_html( $item['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INFOGRAPHIC_SLUG ) ); ?>">Настроить →</a>
					</td></tr>
					<tr><th>Куда ставить</th><td>
						<select name="infographic_placement">
							<?php foreach ( LAA_Settings::infographic_placements() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['infographic_placement'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Обложкой — инфографика становится миниатюрой записи, обычная обложка не генерируется, в текст картинка не вставляется. В начале или в конце — картинка в тексте, обложка делается как обычно. Контент-план может переопределить.</p>
					</td></tr>
				</table>

				<h2>Публикация и проверка</h2>
				<table class="form-table">
					<tr><th>SEO-плагин</th><td>
						<select name="seo_plugin">
							<?php foreach ( array( 'yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math', 'none' => 'Не использовать' ) as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['seo_plugin'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Сгенерированные title и description пишутся в поля выбранного плагина.</p>
					</td></tr>
					<tr><th>Чек-лист</th><td>
						<label><input type="checkbox" name="checker_enforce" value="1" <?php checked( $s['checker_enforce'] ); ?>> не публиковать статью, не прошедшую проверку — отправлять в черновики</label>
					</td></tr>
					<tr><th>Длина заголовка статьи (H1)</th><td>
						<input type="number" name="title_max_chars" min="20" max="500" value="<?php echo esc_attr( $s['title_max_chars'] ); ?>" style="width:90px"> символов
						<p class="description">
							Заголовок, который не уложился, <strong>не обрезается</strong> — плагин просит модель
							переписать его короче, сохранив смысл (до
							<input type="number" name="title_rewrite_attempts" min="1" max="5" value="<?php echo esc_attr( $s['title_rewrite_attempts'] ); ?>" style="width:55px"> попыток,
							каждая — отдельный короткий запрос). Если не уложилось и после этого, заголовок
							остаётся полным, а в чек-лист попадает замечание. Обрыва на полуслове не будет
							ни в одном случае.
						</p>
					</td></tr>
					<tr><th>Длина meta_title для выдачи</th><td>
						<input type="number" name="meta_title_max_chars" min="20" max="200" value="<?php echo esc_attr( $s['meta_title_max_chars'] ); ?>" style="width:90px"> символов
						<p class="description">Отдельная короткая формулировка для поисковой выдачи — она уходит в SEO-плагин, а на странице заголовок остаётся полным. Выдача показывает примерно 60 символов.</p>
					</td></tr>
					<tr><th>Жёсткие минимумы</th><td>
						объём <input type="number" name="min_words_hard" min="0" max="4000" value="<?php echo esc_attr( $s['min_words_hard'] ); ?>" style="width:90px"> слов ·
						разделов <input type="number" name="min_sections_hard" min="0" max="15" value="<?php echo esc_attr( $s['min_sections_hard'] ); ?>" style="width:70px">
					</td></tr>
				</table>

				<?php submit_button( 'Сохранить настройки' ); ?>
			</form>

			<h2 style="margin-top:40px">Расписание (cron)</h2>
			<p>По умолчанию работает WP-Cron раз в 10 минут — он зависит от посещаемости сайта. Для надёжности подключите один из вариантов ниже; все три защищены секретным токеном.</p>
			<table class="form-table">
				<tr><th>Секретный токен</th><td>
					<code><?php echo esc_html( $s['cron_secret'] ); ?></code>
					<a class="button" style="margin-left:10px" href="<?php echo esc_url( self::action_url( 'laa_regenerate_secret' ) ); ?>" onclick="return confirm('Старый URL перестанет работать. Продолжить?')">Сгенерировать заново</a>
				</td></tr>
			</table>
			<p><strong>Вариант 1 — curl:</strong></p>
			<textarea readonly class="laa-command" onclick="this.select()"><?php echo esc_textarea( $commands['curl'] ); ?></textarea>
			<p><strong>Вариант 2 — wget:</strong></p>
			<textarea readonly class="laa-command" onclick="this.select()"><?php echo esc_textarea( $commands['wget'] ); ?></textarea>
			<p><strong>Вариант 3 — прямой PHP CLI:</strong></p>
			<textarea readonly class="laa-command" onclick="this.select()"><?php echo esc_textarea( $commands['php'] ); ?></textarea>
			<p class="description">Раз в 10 минут в crontab, панели хостинга или стороннем cron-сервисе. Тик сам решает, каким планам пора публиковаться.</p>
		</div>
		<?php
	}

	// -------------------------------------------------------- images screen

	public static function render_images_page() {
		self::require_cap();

		$presets = LAA_Image_Presets::get_all();
		// phpcs:ignore WordPress.Security.NonceVerification
		$current_id = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : (string) array_key_first( $presets );
		$preset     = $presets[ $current_id ] ?? LAA_Image_Presets::schema();
		$font       = LAA_Image_Composer::resolve_font( $preset );
		?>
		<div class="wrap laa-wrap">
			<h1>Обложки — пресеты наложения</h1>
			<?php self::print_notice(); ?>

			<?php if ( ! LAA_Image_Composer::available() ) : ?>
				<div class="notice notice-error"><p>Расширение GD недоступно — собрать обложку из библиотеки не получится.</p></div>
			<?php elseif ( '' === $font ) : ?>
				<div class="notice notice-warning"><p>Не найден TTF/OTF-шрифт для подписи. Загрузите шрифт ниже — иначе картинка соберётся без текста.</p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php foreach ( $presets as $id => $item ) : ?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::IMAGES_SLUG . '&preset=' . $id ) ); ?>" <?php echo $id === $current_id ? 'class="current"' : ''; ?>>
							<?php echo esc_html( $item['name'] ); ?>
						</a> |
					</li>
				<?php endforeach; ?>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::IMAGES_SLUG . '&preset=new' ) ); ?>">+ новый пресет</a></li>
			</ul>
			<div style="clear:both"></div>

			<div class="laa-images-layout">
				<div class="laa-images-form">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="laa-preset-form">
						<?php wp_nonce_field( 'laa_save_preset' ); ?>
						<input type="hidden" name="action" value="laa_save_preset">
						<input type="hidden" name="preset_id" value="<?php echo esc_attr( 'new' === $current_id ? '' : $current_id ); ?>">
						<?php self::render_preset_fields( $preset ); ?>
						<p>
							<?php submit_button( 'Сохранить пресет', 'primary', 'submit', false ); ?>
							<button type="button" class="button" id="laa-preview-button">Предпросмотр</button>
							<?php if ( 'new' !== $current_id && count( $presets ) > 1 ) : ?>
								<a class="button laa-delete" href="<?php echo esc_url( self::action_url( 'laa_delete_preset', array( 'preset_id' => $current_id ) ) ); ?>" onclick="return confirm('Удалить пресет?')">Удалить</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="laa-images-preview">
					<h2>Предпросмотр</h2>
					<p>
						<label for="laa-preview-title">Текст подписи</label><br>
						<input type="text" id="laa-preview-title" class="large-text" value="Чем RAG отличается от fine-tuning и когда выбирать каждый подход">
					</p>
					<div id="laa-preview-box"><p class="description">Нажмите «Предпросмотр», чтобы собрать картинку с текущими настройками.</p></div>
					<p class="description">Берётся случайная картинка из пула (<?php echo count( LAA_Image_Service::pool() ); ?> шт.). Пул настраивается в <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">настройках</a>.</p>
				</div>
			</div>

			<div class="laa-panel">
				<h2>Шрифт для подписи</h2>
				<p>Сейчас используется: <code><?php echo esc_html( $font ?: 'не найден' ); ?></code></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'laa_upload_font' ); ?>
					<input type="hidden" name="action" value="laa_upload_font">
					<input type="file" name="font" accept=".ttf,.otf">
					<?php submit_button( 'Загрузить шрифт', 'secondary', 'submit', false ); ?>
					<p class="description">TTF или OTF с поддержкой кириллицы. Шрифты темы в формате woff2 для GD не подходят. Файл кладётся в <code><?php echo esc_html( LAA_Image_Composer::fonts_dir() ); ?></code>.</p>
				</form>
				<?php $uploaded = LAA_Image_Composer::uploaded_fonts(); ?>
				<?php if ( $uploaded ) : ?>
					<p>Загруженные: <?php echo esc_html( implode( ', ', wp_list_pluck( $uploaded, 'name' ) ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_preset_fields( array $p ) {
		$positions  = LAA_Image_Presets::positions();
		$gradients  = LAA_Image_Presets::gradient_directions();
		$fits       = LAA_Image_Presets::fit_modes();
		$mask_modes = LAA_Image_Presets::mask_modes();
		?>
		<h2>Полотно</h2>
		<table class="form-table">
			<tr><th>Название</th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $p['name'] ); ?>"></td></tr>
			<tr><th>Размер</th><td>
				<input type="number" name="width" value="<?php echo esc_attr( $p['width'] ); ?>" style="width:90px"> ×
				<input type="number" name="height" value="<?php echo esc_attr( $p['height'] ); ?>" style="width:90px"> px
				<strong><?php echo esc_html( self::ratio_label( (int) $p['width'], (int) $p['height'] ) ); ?></strong>
				<p class="description">
					Пропорции должны совпадать с тем, в каком соотношении тема выводит миниатюру
					(<code>aspectRatio</code> у блока «Изображение записи»). Если картинка шире
					контейнера, браузер обрежет её по бокам с <code>object-fit:cover</code> — и подпись
					у левого края пропадёт. Тема <?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?>
					на странице записи сейчас использует <?php echo esc_html( self::theme_featured_ratio() ); ?>.
				</p>
			</td></tr>
			<tr><th>Вписывание</th><td>
				<select name="fit"><?php self::options( $fits, $p['fit'] ); ?></select>
				<select name="focal"><?php self::options( $positions, $p['focal'] ); ?></select>
				<input type="text" name="background" value="<?php echo esc_attr( $p['background'] ); ?>" style="width:90px">
			</td></tr>
			<tr><th>Формат</th><td>
				<select name="format"><?php self::options( array( 'jpg' => 'JPEG', 'webp' => 'WebP', 'png' => 'PNG' ), $p['format'] ); ?></select>
				качество <input type="number" name="quality" min="1" max="100" value="<?php echo esc_attr( $p['quality'] ); ?>" style="width:70px">
			</td></tr>
		</table>

		<h2>Фильтр</h2>
		<table class="form-table">
			<tr><th>Обесцветить</th><td><label><input type="checkbox" name="grayscale" value="1" <?php checked( $p['grayscale'] ); ?>> ч/б</label></td></tr>
			<tr><th>Яркость / контраст</th><td>
				<input type="number" name="brightness" min="-255" max="255" value="<?php echo esc_attr( $p['brightness'] ); ?>" style="width:80px">
				<input type="number" name="contrast" min="-100" max="100" value="<?php echo esc_attr( $p['contrast'] ); ?>" style="width:80px">
				<span class="description">контраст: отрицательное значение усиливает</span>
			</td></tr>
			<tr><th>Размытие</th><td><input type="number" name="blur" min="0" max="10" value="<?php echo esc_attr( $p['blur'] ); ?>" style="width:70px"> проходов</td></tr>
			<tr><th>Тонирование</th><td>
				<input type="text" name="colorize_hex" value="<?php echo esc_attr( $p['colorize_hex'] ); ?>" style="width:90px" placeholder="#1f6feb">
				сила <input type="number" name="colorize_strength" min="0" max="100" value="<?php echo esc_attr( $p['colorize_strength'] ); ?>" style="width:70px">%
			</td></tr>
		</table>

		<h2>Слои поверх</h2>
		<table class="form-table">
			<tr><th>Заливка цветом</th><td>
				<input type="text" name="tint_hex" value="<?php echo esc_attr( $p['tint_hex'] ); ?>" style="width:90px">
				непрозрачность <input type="number" name="tint_alpha" min="0" max="100" value="<?php echo esc_attr( $p['tint_alpha'] ); ?>" style="width:70px">%
			</td></tr>
			<tr><th>Градиент</th><td>
				<label><input type="checkbox" name="gradient_enabled" value="1" <?php checked( $p['gradient_enabled'] ); ?>> включить</label>
				<select name="gradient_direction"><?php self::options( $gradients, $p['gradient_direction'] ); ?></select><br>
				от <input type="text" name="gradient_from_hex" value="<?php echo esc_attr( $p['gradient_from_hex'] ); ?>" style="width:90px">
				<input type="number" name="gradient_from_alpha" min="0" max="100" value="<?php echo esc_attr( $p['gradient_from_alpha'] ); ?>" style="width:70px">%
				&nbsp;к <input type="text" name="gradient_to_hex" value="<?php echo esc_attr( $p['gradient_to_hex'] ); ?>" style="width:90px">
				<input type="number" name="gradient_to_alpha" min="0" max="100" value="<?php echo esc_attr( $p['gradient_to_alpha'] ); ?>" style="width:70px">%
			</td></tr>
			<tr><th>PNG-маска</th><td>
				<?php self::media_field( 'mask_id', (int) $p['mask_id'], 'Выбрать маску' ); ?>
				<select name="mask_mode"><?php self::options( $mask_modes, $p['mask_mode'] ); ?></select>
				непрозрачность <input type="number" name="mask_alpha" min="0" max="100" value="<?php echo esc_attr( $p['mask_alpha'] ); ?>" style="width:70px">%
			</td></tr>
			<tr><th>Логотип</th><td>
				<?php self::media_field( 'logo_id', (int) $p['logo_id'], 'Выбрать логотип' ); ?>
				<select name="logo_position"><?php self::options( $positions, $p['logo_position'] ); ?></select>
				ширина <input type="number" name="logo_width" min="2" max="100" value="<?php echo esc_attr( $p['logo_width'] ); ?>" style="width:60px">%
				прозрачность <input type="number" name="logo_alpha" min="0" max="100" value="<?php echo esc_attr( $p['logo_alpha'] ); ?>" style="width:60px">%
				отступы <input type="number" name="logo_offset_x" value="<?php echo esc_attr( $p['logo_offset_x'] ); ?>" style="width:60px">
				<input type="number" name="logo_offset_y" value="<?php echo esc_attr( $p['logo_offset_y'] ); ?>" style="width:60px">
			</td></tr>
			<tr><th>Рамка</th><td>
				толщина <input type="number" name="border_width" min="0" max="80" value="<?php echo esc_attr( $p['border_width'] ); ?>" style="width:60px">
				цвет <input type="text" name="border_color" value="<?php echo esc_attr( $p['border_color'] ); ?>" style="width:90px">
				отступ от края <input type="number" name="border_inset" min="0" max="200" value="<?php echo esc_attr( $p['border_inset'] ); ?>" style="width:60px">
				скругление углов <input type="number" name="corner_radius" min="0" max="200" value="<?php echo esc_attr( $p['corner_radius'] ); ?>" style="width:60px">
				<p class="description">Скругление углов видно только в PNG/WebP — в JPEG углы заливаются цветом фона. Рамка рисуется прямой даже при скруглении.</p>
			</td></tr>
		</table>

		<h2>Подпись</h2>
		<table class="form-table">
			<tr><th>Текст</th><td>
				<label><input type="checkbox" name="caption_enabled" value="1" <?php checked( $p['caption_enabled'] ); ?>> наносить подпись</label>
				<select name="caption_source"><?php self::options( array( 'title' => 'Заголовок статьи', 'custom' => 'Свой текст', 'none' => 'Без текста' ), $p['caption_source'] ); ?></select>
				<input type="text" name="caption_text" value="<?php echo esc_attr( $p['caption_text'] ); ?>" style="width:280px" placeholder="свой текст">
			</td></tr>
			<tr><th>Шрифт</th><td>
				<select name="caption_font">
					<option value="">Определять автоматически</option>
					<?php foreach ( LAA_Image_Composer::uploaded_fonts() as $font ) : ?>
						<option value="<?php echo esc_attr( $font['name'] ); ?>" <?php selected( basename( $p['caption_font'] ), $font['name'] ); ?>><?php echo esc_html( $font['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
			<tr><th>Размер</th><td>
				<input type="number" name="caption_size" min="10" max="300" value="<?php echo esc_attr( $p['caption_size'] ); ?>" style="width:70px"> px
				<label><input type="checkbox" name="caption_autofit" value="1" <?php checked( $p['caption_autofit'] ); ?>> уменьшать, если не влезает, до
					<input type="number" name="caption_min_size" min="8" max="300" value="<?php echo esc_attr( $p['caption_min_size'] ); ?>" style="width:60px"> px</label>
			</td></tr>
			<tr><th>Строки</th><td>
				интерлиньяж <input type="text" name="caption_line_height" value="<?php echo esc_attr( $p['caption_line_height'] ); ?>" style="width:60px">
				максимум <input type="number" name="caption_max_lines" min="1" max="10" value="<?php echo esc_attr( $p['caption_max_lines'] ); ?>" style="width:60px"> строк
				ширина блока <input type="number" name="caption_max_width" min="10" max="100" value="<?php echo esc_attr( $p['caption_max_width'] ); ?>" style="width:60px">% полотна
			</td></tr>
			<tr><th>Положение</th><td>
				<select name="caption_position"><?php self::options( $positions, $p['caption_position'] ); ?></select>
				<select name="caption_align"><?php self::options( array( 'left' => 'по левому краю', 'center' => 'по центру', 'right' => 'по правому краю' ), $p['caption_align'] ); ?></select>
				отступы <input type="number" name="caption_offset_x" value="<?php echo esc_attr( $p['caption_offset_x'] ); ?>" style="width:60px">
				<input type="number" name="caption_offset_y" value="<?php echo esc_attr( $p['caption_offset_y'] ); ?>" style="width:60px">
			</td></tr>
			<tr><th>Начертание</th><td>
				цвет <input type="text" name="caption_color" value="<?php echo esc_attr( $p['caption_color'] ); ?>" style="width:90px">
				<label><input type="checkbox" name="caption_uppercase" value="1" <?php checked( $p['caption_uppercase'] ); ?>> ПРОПИСНЫЕ</label>
				разрядка <input type="number" name="caption_letter_spacing" min="-10" max="40" value="<?php echo esc_attr( $p['caption_letter_spacing'] ); ?>" style="width:60px">
			</td></tr>
			<tr><th>Тень</th><td>
				<label><input type="checkbox" name="shadow_enabled" value="1" <?php checked( $p['shadow_enabled'] ); ?>> включить</label>
				цвет <input type="text" name="shadow_color" value="<?php echo esc_attr( $p['shadow_color'] ); ?>" style="width:90px">
				непрозрачность <input type="number" name="shadow_alpha" min="0" max="100" value="<?php echo esc_attr( $p['shadow_alpha'] ); ?>" style="width:60px">%
				смещение <input type="number" name="shadow_offset_x" value="<?php echo esc_attr( $p['shadow_offset_x'] ); ?>" style="width:60px">
				<input type="number" name="shadow_offset_y" value="<?php echo esc_attr( $p['shadow_offset_y'] ); ?>" style="width:60px">
			</td></tr>
			<tr><th>Плашка под текстом</th><td>
				<label><input type="checkbox" name="plate_enabled" value="1" <?php checked( $p['plate_enabled'] ); ?>> включить</label>
				цвет <input type="text" name="plate_color" value="<?php echo esc_attr( $p['plate_color'] ); ?>" style="width:90px">
				непрозрачность <input type="number" name="plate_alpha" min="0" max="100" value="<?php echo esc_attr( $p['plate_alpha'] ); ?>" style="width:60px">%
				отступ <input type="number" name="plate_padding" min="0" max="200" value="<?php echo esc_attr( $p['plate_padding'] ); ?>" style="width:60px">
				скругление <input type="number" name="plate_radius" min="0" max="120" value="<?php echo esc_attr( $p['plate_radius'] ); ?>" style="width:60px">
			</td></tr>
		</table>
		<?php
	}

	/** "1200×800 — это 3:2" in one short label. */
	private static function ratio_label( $width, $height ) {
		if ( $width < 1 || $height < 1 ) {
			return '';
		}

		$divisor = function ( $a, $b ) use ( &$divisor ) {
			return $b ? $divisor( $b, $a % $b ) : $a;
		};

		$gcd = max( 1, $divisor( $width, $height ) );
		$w   = (int) ( $width / $gcd );
		$h   = (int) ( $height / $gcd );

		// 600:403 tells nobody anything — fall back to a decimal.
		$pretty = ( $w <= 32 && $h <= 32 ) ? $w . ':' . $h : number_format( $width / $height, 2 ) . ':1';

		return '— ' . $pretty;
	}

	/**
	 * The aspect ratio the active theme's single-post template puts on the
	 * featured image, read straight out of the template markup. Best-effort:
	 * it's a hint in the UI, not something the renderer depends on.
	 */
	private static function theme_featured_ratio() {
		foreach ( array( 'single.html', 'singular.html', 'index.html' ) as $template ) {
			foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
				$path = $dir . '/templates/' . $template;
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$markup = (string) file_get_contents( $path ); // phpcs:ignore
				if ( preg_match( '/wp:post-featured-image[^>]*"aspectRatio"\s*:\s*"([^"]+)"/', $markup, $m ) ) {
					return $m[1] . ' (' . $template . ')';
				}
				if ( false !== strpos( $markup, 'wp:post-featured-image' ) ) {
					return 'пропорции не заданы — картинка выводится как есть (' . $template . ')';
				}
			}
		}
		return 'определить не удалось — посмотрите в редакторе шаблона';
	}

	private static function options( array $choices, $selected ) {
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $selected, (string) $value, false ),
				esc_html( $label )
			);
		}
	}

	private static function media_field( $name, $value, $button_label ) {
		$thumb = $value ? wp_get_attachment_image( $value, array( 60, 60 ) ) : '';
		printf(
			'<span class="laa-media" data-field="%1$s"><input type="hidden" name="%1$s" value="%2$d"><span class="laa-media-thumb">%3$s</span> <button type="button" class="button laa-media-pick">%4$s</button> <button type="button" class="button-link laa-media-clear">убрать</button></span>',
			esc_attr( $name ),
			(int) $value,
			$thumb, // wp_get_attachment_image() returns escaped markup
			esc_html( $button_label )
		);
	}

	// -------------------------------------------------- infographic screen

	public static function render_infographic_page() {
		self::require_cap();

		$presets = LAA_Infographic_Presets::get_all();
		// phpcs:ignore WordPress.Security.NonceVerification
		$current_id = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : (string) array_key_first( $presets );
		$preset     = $presets[ $current_id ] ?? LAA_Infographic_Presets::schema();
		$font       = LAA_Image_Composer::resolve_font( array( 'caption_font' => $preset['font'] ) );
		?>
		<div class="wrap laa-wrap">
			<h1>Инфографика</h1>
			<?php self::print_notice(); ?>

			<?php if ( ! LAA_Image_Composer::text_available() ) : ?>
				<div class="notice notice-error"><p>GD без FreeType — текст на картинку нанести нельзя, инфографика работать не будет.</p></div>
			<?php elseif ( '' === $font ) : ?>
				<div class="notice notice-warning"><p>Не найден TTF-шрифт. Загрузите его на экране <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::IMAGES_SLUG ) ); ?>">«Обложки»</a> — без него инфографика не соберётся.</p></div>
			<?php endif; ?>

			<?php if ( ! LAA_Settings::get( 'infographic_enabled' ) ) : ?>
				<div class="notice notice-info"><p>Инфографика выключена глобально — включить можно в <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">настройках</a>, либо отдельно у каждого контент-плана.</p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php foreach ( $presets as $id => $item ) : ?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INFOGRAPHIC_SLUG . '&preset=' . $id ) ); ?>" <?php echo $id === $current_id ? 'class="current"' : ''; ?>>
							<?php echo esc_html( $item['name'] ); ?>
						</a> |
					</li>
				<?php endforeach; ?>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INFOGRAPHIC_SLUG . '&preset=new' ) ); ?>">+ новый пресет</a></li>
			</ul>
			<div style="clear:both"></div>

			<div class="laa-images-layout">
				<div class="laa-images-form">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="laa-preset-form">
						<?php wp_nonce_field( 'laa_save_infographic_preset' ); ?>
						<input type="hidden" name="action" value="laa_save_infographic_preset">
						<input type="hidden" name="preset_id" value="<?php echo esc_attr( 'new' === $current_id ? '' : $current_id ); ?>">
						<?php self::render_infographic_fields( $preset ); ?>
						<p>
							<?php submit_button( 'Сохранить пресет', 'primary', 'submit', false ); ?>
							<button type="button" class="button" id="laa-preview-button">Предпросмотр</button>
							<?php if ( 'new' !== $current_id && count( $presets ) > 1 ) : ?>
								<a class="button laa-delete" href="<?php echo esc_url( self::action_url( 'laa_delete_infographic_preset', array( 'preset_id' => $current_id ) ) ); ?>" onclick="return confirm('Удалить пресет?')">Удалить</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="laa-images-preview">
					<h2>Предпросмотр</h2>
					<p>
						<label for="laa-preview-title">Заголовок</label><br>
						<input type="text" id="laa-preview-title" class="large-text" value="Как настроить AI-агент для обработки входящей почты">
					</p>
					<p>
						<label for="laa-preview-points">Тезисы — по одному на строку, можно «метка | текст»</label><br>
						<textarea id="laa-preview-points" rows="8" class="large-text code">Чтение письма | Агент извлекает чистый текст, отбрасывает подписи и цитаты переписки.
Определение цели | Модель относит обращение к категории: вопрос, жалоба, заявка, счёт.
Поиск фактов | Из текста вытаскиваются даты, суммы, номера договоров и имена.
Проверка | Факты сверяются с базой; чего нет в источнике — в ответ не попадает.
Черновик ответа | Шаблон задаёт тональность, модель заполняет его данными.
Передача человеку | Спорные случаи уходят оператору с пометкой, почему агент не взялся.</textarea>
					</p>
					<div id="laa-preview-box"><p class="description">Нажмите «Предпросмотр», чтобы собрать инфографику с текущими настройками.</p></div>
					<p class="description">Бюджет на тезис при этом пресете: <strong><?php echo (int) LAA_Infographic_Service::char_budget( $preset, 'list' === $preset['layout'] ? 8 : 6 ); ?></strong> символов — именно это число получает модель.</p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_infographic_fields( array $p ) {
		$positions = LAA_Image_Presets::positions();
		?>
		<h2>Полотно</h2>
		<table class="form-table">
			<tr><th>Название</th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $p['name'] ); ?>"></td></tr>
			<tr><th>Размер</th><td>
				<input type="number" name="width" value="<?php echo esc_attr( $p['width'] ); ?>" style="width:90px"> ×
				<input type="number" name="height" value="<?php echo esc_attr( $p['height'] ); ?>" style="width:90px"> px
				<p class="description">Если инфографика ставится миниатюрой, пропорции должны совпадать с темой — иначе её обрежет, как было с обложкой. Тема сейчас: <?php echo esc_html( self::theme_featured_ratio() ); ?>.</p>
			</td></tr>
			<tr><th>Поля</th><td><input type="number" name="padding" min="0" max="300" value="<?php echo esc_attr( $p['padding'] ); ?>" style="width:80px"> px</td></tr>
			<tr><th>Формат</th><td>
				<select name="format"><?php self::options( array( 'jpg' => 'JPEG', 'webp' => 'WebP', 'png' => 'PNG' ), $p['format'] ); ?></select>
				качество <input type="number" name="quality" min="1" max="100" value="<?php echo esc_attr( $p['quality'] ); ?>" style="width:70px">
			</td></tr>
		</table>

		<h2>Фон</h2>
		<table class="form-table">
			<tr><th>Чем заполнять</th><td>
				<select name="background_mode"><?php self::options( LAA_Infographic_Presets::background_modes(), $p['background_mode'] ); ?></select>
				цвет <input type="text" name="background" value="<?php echo esc_attr( $p['background'] ); ?>" style="width:90px">
			</td></tr>
			<tr><th>Картинка-подложка</th><td>
				<select name="fit"><?php self::options( LAA_Image_Presets::fit_modes(), $p['fit'] ); ?></select>
				<select name="focal"><?php self::options( $positions, $p['focal'] ); ?></select>
				<label><input type="checkbox" name="grayscale" value="1" <?php checked( $p['grayscale'] ); ?>> ч/б</label>
				яркость <input type="number" name="brightness" min="-255" max="255" value="<?php echo esc_attr( $p['brightness'] ); ?>" style="width:70px">
				контраст <input type="number" name="contrast" min="-100" max="100" value="<?php echo esc_attr( $p['contrast'] ); ?>" style="width:70px">
				размытие <input type="number" name="blur" min="0" max="10" value="<?php echo esc_attr( $p['blur'] ); ?>" style="width:60px">
				<p class="description">Берётся из того же пула, что и обложки. Фон должен быть тихим: поверх него читается текст.</p>
			</td></tr>
			<tr><th>Тонирование</th><td>
				<input type="text" name="colorize_hex" value="<?php echo esc_attr( $p['colorize_hex'] ); ?>" style="width:90px" placeholder="#1f6feb">
				сила <input type="number" name="colorize_strength" min="0" max="100" value="<?php echo esc_attr( $p['colorize_strength'] ); ?>" style="width:60px">%
			</td></tr>
			<tr><th>Заливка цветом</th><td>
				<input type="text" name="tint_hex" value="<?php echo esc_attr( $p['tint_hex'] ); ?>" style="width:90px">
				непрозрачность <input type="number" name="tint_alpha" min="0" max="100" value="<?php echo esc_attr( $p['tint_alpha'] ); ?>" style="width:60px">%
			</td></tr>
			<tr><th>Градиент</th><td>
				<label><input type="checkbox" name="gradient_enabled" value="1" <?php checked( $p['gradient_enabled'] ); ?>> включить</label>
				<select name="gradient_direction"><?php self::options( LAA_Image_Presets::gradient_directions(), $p['gradient_direction'] ); ?></select>
				от <input type="text" name="gradient_from_hex" value="<?php echo esc_attr( $p['gradient_from_hex'] ); ?>" style="width:90px">
				<input type="number" name="gradient_from_alpha" min="0" max="100" value="<?php echo esc_attr( $p['gradient_from_alpha'] ); ?>" style="width:60px">%
				к <input type="text" name="gradient_to_hex" value="<?php echo esc_attr( $p['gradient_to_hex'] ); ?>" style="width:90px">
				<input type="number" name="gradient_to_alpha" min="0" max="100" value="<?php echo esc_attr( $p['gradient_to_alpha'] ); ?>" style="width:60px">%
			</td></tr>
			<tr><th>PNG-маска</th><td>
				<?php self::media_field( 'mask_id', (int) $p['mask_id'], 'Выбрать маску' ); ?>
				<select name="mask_mode"><?php self::options( LAA_Image_Presets::mask_modes(), $p['mask_mode'] ); ?></select>
				<input type="number" name="mask_alpha" min="0" max="100" value="<?php echo esc_attr( $p['mask_alpha'] ); ?>" style="width:60px">%
			</td></tr>
		</table>

		<h2>Заголовок</h2>
		<table class="form-table">
			<tr><th>Показывать</th><td>
				<label><input type="checkbox" name="title_enabled" value="1" <?php checked( $p['title_enabled'] ); ?>> заголовок статьи сверху</label>
			</td></tr>
			<tr><th>Кегль</th><td>
				<input type="number" name="title_size" min="12" max="160" value="<?php echo esc_attr( $p['title_size'] ); ?>" style="width:70px">
				минимум <input type="number" name="title_min_size" min="10" max="160" value="<?php echo esc_attr( $p['title_min_size'] ); ?>" style="width:70px">
				интерлиньяж <input type="text" name="title_line_height" value="<?php echo esc_attr( $p['title_line_height'] ); ?>" style="width:60px">
				строк <input type="number" name="title_max_lines" min="1" max="5" value="<?php echo esc_attr( $p['title_max_lines'] ); ?>" style="width:55px">
			</td></tr>
			<tr><th>Вид</th><td>
				цвет <input type="text" name="title_color" value="<?php echo esc_attr( $p['title_color'] ); ?>" style="width:90px">
				<select name="title_align"><?php self::options( array( 'left' => 'по левому краю', 'center' => 'по центру', 'right' => 'по правому краю' ), $p['title_align'] ); ?></select>
				отступ снизу <input type="number" name="title_gap" min="0" max="200" value="<?php echo esc_attr( $p['title_gap'] ); ?>" style="width:60px">
			</td></tr>
		</table>

		<h2>Раскладка</h2>
		<table class="form-table">
			<tr><th>Тип</th><td>
				<select name="layout"><?php self::options( LAA_Infographic_Presets::layouts(), $p['layout'] ); ?></select>
				колонок <input type="number" name="columns" min="1" max="4" value="<?php echo esc_attr( $p['columns'] ); ?>" style="width:55px">
				<p class="description">Для списка число колонок игнорируется — он всегда в одну.</p>
			</td></tr>
			<tr><th>Зазоры</th><td>
				по горизонтали <input type="number" name="gap_x" min="0" max="120" value="<?php echo esc_attr( $p['gap_x'] ); ?>" style="width:60px">
				по вертикали <input type="number" name="gap_y" min="0" max="120" value="<?php echo esc_attr( $p['gap_y'] ); ?>" style="width:60px">
			</td></tr>
			<tr><th>Плашка пункта</th><td>
				<label><input type="checkbox" name="plate_enabled" value="1" <?php checked( $p['plate_enabled'] ); ?>> включить</label>
				цвет <input type="text" name="plate_color" value="<?php echo esc_attr( $p['plate_color'] ); ?>" style="width:90px">
				<input type="number" name="plate_alpha" min="0" max="100" value="<?php echo esc_attr( $p['plate_alpha'] ); ?>" style="width:55px">%
				скругление <input type="number" name="plate_radius" min="0" max="80" value="<?php echo esc_attr( $p['plate_radius'] ); ?>" style="width:55px">
				внутр. отступ <input type="number" name="plate_padding" min="0" max="100" value="<?php echo esc_attr( $p['plate_padding'] ); ?>" style="width:55px">
			</td></tr>
			<tr><th>Обводка плашки</th><td>
				толщина <input type="number" name="plate_border_width" min="0" max="12" value="<?php echo esc_attr( $p['plate_border_width'] ); ?>" style="width:55px">
				цвет <input type="text" name="plate_border_color" value="<?php echo esc_attr( $p['plate_border_color'] ); ?>" style="width:90px">
			</td></tr>
		</table>

		<h2>Маркер и текст</h2>
		<table class="form-table">
			<tr><th>Маркер</th><td>
				<select name="badge_style"><?php self::options( LAA_Infographic_Presets::badge_styles(), $p['badge_style'] ); ?></select>
				размер <input type="number" name="badge_size" min="0" max="120" value="<?php echo esc_attr( $p['badge_size'] ); ?>" style="width:55px">
				отступ <input type="number" name="badge_gap" min="0" max="80" value="<?php echo esc_attr( $p['badge_gap'] ); ?>" style="width:55px">
				фон <input type="text" name="badge_bg" value="<?php echo esc_attr( $p['badge_bg'] ); ?>" style="width:90px">
				цифра <input type="text" name="badge_color" value="<?php echo esc_attr( $p['badge_color'] ); ?>" style="width:90px">
				<p class="description">Маркер масштабируется вместе с текстом: при плотной вёрстке он не должен съедать треть ячейки.</p>
			</td></tr>
			<tr><th>Метка пункта</th><td>
				<label><input type="checkbox" name="label_enabled" value="1" <?php checked( $p['label_enabled'] ); ?>> короткая строка над тезисом</label>
				кегль <input type="number" name="label_size" min="8" max="100" value="<?php echo esc_attr( $p['label_size'] ); ?>" style="width:55px">
				цвет <input type="text" name="label_color" value="<?php echo esc_attr( $p['label_color'] ); ?>" style="width:90px">
				отступ <input type="number" name="label_gap" min="0" max="60" value="<?php echo esc_attr( $p['label_gap'] ); ?>" style="width:55px">
				<label><input type="checkbox" name="label_uppercase" value="1" <?php checked( $p['label_uppercase'] ); ?>> ПРОПИСНЫЕ</label>
			</td></tr>
			<tr><th>Текст тезиса</th><td>
				кегль <input type="number" name="text_size" min="8" max="100" value="<?php echo esc_attr( $p['text_size'] ); ?>" style="width:55px">
				минимум <input type="number" name="text_min_size" min="6" max="100" value="<?php echo esc_attr( $p['text_min_size'] ); ?>" style="width:55px">
				интерлиньяж <input type="text" name="text_line_height" value="<?php echo esc_attr( $p['text_line_height'] ); ?>" style="width:55px">
				цвет <input type="text" name="text_color" value="<?php echo esc_attr( $p['text_color'] ); ?>" style="width:90px">
				<p class="description">Кегль один на все пункты: подбирается по самому длинному тезису и опускается до минимума. Что не влезло даже так — переписывается моделью, а не обрезается.</p>
			</td></tr>
			<tr><th>Шрифт</th><td>
				<select name="font">
					<option value="">Определять автоматически</option>
					<?php foreach ( LAA_Image_Composer::uploaded_fonts() as $item ) : ?>
						<option value="<?php echo esc_attr( $item['name'] ); ?>" <?php selected( basename( $p['font'] ), $item['name'] ); ?>><?php echo esc_html( $item['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
		</table>

		<h2>Подвал и логотип</h2>
		<table class="form-table">
			<tr><th>Подвал</th><td>
				<label><input type="checkbox" name="footer_enabled" value="1" <?php checked( $p['footer_enabled'] ); ?>> показывать</label>
				<input type="text" name="footer_text" value="<?php echo esc_attr( $p['footer_text'] ); ?>" style="width:220px" placeholder="<?php echo esc_attr( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>">
				кегль <input type="number" name="footer_size" min="8" max="60" value="<?php echo esc_attr( $p['footer_size'] ); ?>" style="width:55px">
				цвет <input type="text" name="footer_color" value="<?php echo esc_attr( $p['footer_color'] ); ?>" style="width:90px">
				<select name="footer_align"><?php self::options( array( 'left' => 'слева', 'center' => 'по центру', 'right' => 'справа' ), $p['footer_align'] ); ?></select>
				отступ <input type="number" name="footer_gap" min="0" max="120" value="<?php echo esc_attr( $p['footer_gap'] ); ?>" style="width:55px">
				<p class="description">Пусто — подставится домен сайта.</p>
			</td></tr>
			<tr><th>Логотип</th><td>
				<?php self::media_field( 'logo_id', (int) $p['logo_id'], 'Выбрать логотип' ); ?>
				<select name="logo_position"><?php self::options( $positions, $p['logo_position'] ); ?></select>
				ширина <input type="number" name="logo_width" min="2" max="60" value="<?php echo esc_attr( $p['logo_width'] ); ?>" style="width:55px">%
				<input type="number" name="logo_alpha" min="0" max="100" value="<?php echo esc_attr( $p['logo_alpha'] ); ?>" style="width:55px">%
				отступы <input type="number" name="logo_offset_x" value="<?php echo esc_attr( $p['logo_offset_x'] ); ?>" style="width:55px">
				<input type="number" name="logo_offset_y" value="<?php echo esc_attr( $p['logo_offset_y'] ); ?>" style="width:55px">
			</td></tr>
		</table>
		<?php
	}

	private static function infographic_values_from_request( array $request ) {
		$checkboxes = array( 'grayscale', 'gradient_enabled', 'title_enabled', 'plate_enabled', 'label_enabled', 'label_uppercase', 'footer_enabled' );

		$values = array();
		foreach ( LAA_Infographic_Presets::schema() as $key => $default ) {
			if ( in_array( $key, $checkboxes, true ) ) {
				$values[ $key ] = isset( $request[ $key ] ) ? 1 : 0;
				continue;
			}
			if ( isset( $request[ $key ] ) ) {
				$values[ $key ] = is_array( $request[ $key ] ) ? '' : wp_unslash( $request[ $key ] );
			}
		}
		return $values;
	}

	public static function handle_save_infographic_preset() {
		check_admin_referer( 'laa_save_infographic_preset' );
		self::require_cap();

		$preset_id = isset( $_POST['preset_id'] ) ? sanitize_key( wp_unslash( $_POST['preset_id'] ) ) : '';
		$values    = self::infographic_values_from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $preset_id ) {
			$preset_id = sanitize_title( $values['name'] ) ?: 'infographic';
			$existing  = LAA_Infographic_Presets::get_all();
			if ( isset( $existing[ $preset_id ] ) ) {
				$preset_id .= '-' . substr( md5( (string) microtime( true ) ), 0, 5 );
			}
		}

		$saved_id = LAA_Infographic_Presets::save( $preset_id, $values );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::INFOGRAPHIC_SLUG,
					'preset'     => $saved_id,
					'laa_notice' => 'Пресет сохранён.',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_delete_infographic_preset() {
		check_admin_referer( 'laa_delete_infographic_preset' );
		self::require_cap();

		$preset_id = isset( $_GET['preset_id'] ) ? sanitize_key( wp_unslash( $_GET['preset_id'] ) ) : '';
		LAA_Infographic_Presets::delete( $preset_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::INFOGRAPHIC_SLUG,
					'laa_notice' => 'Пресет удалён.',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Renders the form's current values with the sample theses from the textarea. */
	public static function ajax_preview_infographic() {
		check_ajax_referer( 'laa_preview_infographic', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ) );
		}

		$preset = LAA_Infographic_Presets::sanitize( self::infographic_values_from_request( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$title  = isset( $_POST['preview_title'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_title'] ) ) : '';
		$raw    = isset( $_POST['preview_points'] ) ? (string) wp_unslash( $_POST['preview_points'] ) : '';

		$points = array();
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts    = array_map( 'trim', explode( '|', $line, 2 ) );
			$points[] = 2 === count( $parts )
				? array( 'label' => sanitize_text_field( $parts[0] ), 'text' => sanitize_text_field( $parts[1] ) )
				: array( 'label' => '', 'text' => sanitize_text_field( $parts[0] ) );
		}

		if ( empty( $points ) ) {
			wp_send_json_error( array( 'message' => 'Введите хотя бы один тезис.' ) );
		}

		$background = null;
		if ( 'library' === $preset['background_mode'] ) {
			$source     = LAA_Image_Service::pick_source( $title );
			$background = $source['path'] ?: null;
		}

		$tmp    = LAA_Image_Service::temp_path( LAA_Image_Composer::extension_for( $preset['format'] ) );
		$result = LAA_Infographic_Composer::compose( $preset, $points, $title, $background, $tmp );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore

		$notice = sprintf( 'Кегль текста: %d px.', $result['text_size'] );
		if ( ! empty( $result['overflow'] ) ) {
			$parts = array();
			foreach ( $result['overflow'] as $item ) {
				$parts[] = sprintf( '№%d (влезает ~%d символов)', $item['index'] + 1, $item['fits_chars'] );
			}
			$notice .= ' Не помещаются: ' . implode( ', ', $parts ) . '. При генерации такие тезисы переписываются моделью.';
		}

		wp_send_json_success(
			array(
				'dataUri' => 'data:' . $result['mime'] . ';base64,' . base64_encode( $bytes ),
				'notice'  => $notice,
				'source'  => $background ? basename( $background ) : 'сплошной фон',
			)
		);
	}

	// ----------------------------------------------------------- log screen

	public static function render_log_page() {
		self::require_cap();
		echo '<div class="wrap laa-wrap"><h1 class="wp-heading-inline">Журнал</h1> ';
		printf(
			'<a href="%s" class="page-title-action" onclick="return confirm(\'Очистить журнал?\')">Очистить</a>',
			esc_url( self::action_url( 'laa_clear_log' ) )
		);
		echo '<hr class="wp-header-end">';
		self::print_notice();
		echo '<table class="widefat striped"><thead><tr><th style="width:170px">Время</th><th style="width:70px">Уровень</th><th>Сообщение</th></tr></thead><tbody>';
		foreach ( LAA_Log::get_recent( 300 ) as $entry ) {
			printf(
				'<tr class="laa-log-%1$s"><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_attr( $entry['level'] ?? 'info' ),
				esc_html( wp_date( 'd.m.Y H:i:s', $entry['time'] ) ),
				esc_html( $entry['level'] ?? 'info' ),
				esc_html( $entry['message'] )
			);
		}
		echo '</tbody></table></div>';
	}

	// -------------------------------------------------------------- actions

	public static function handle_save_settings() {
		check_admin_referer( 'laa_save_settings' );
		self::require_cap();

		$checkboxes = array( 'image_fallback_library', 'checker_enforce', 'infographic_enabled' );
		$texts      = array(
			'llm_provider', 'llm_timeout', 'llm_temperature_plan', 'llm_temperature_body',
			'yandex_api_key', 'yandex_folder_id', 'yandex_model',
			'deepseek_api_key', 'deepseek_model',
			'qwen_api_key', 'qwen_model', 'qwen_base_url',
			'timeweb_agent_id', 'timeweb_token', 'timeweb_proxy_source',
			'openrouter_api_key', 'openrouter_model',
			'image_mode', 'image_provider', 'default_preset',
			'yandex_art_model', 'openai_images_endpoint', 'openai_images_key',
			'openai_images_model', 'openai_images_size', 'image_negative_prompt',
			'library_source', 'library_ids', 'library_term', 'library_folder', 'library_strategy',
			'seo_plugin', 'min_words_hard', 'min_sections_hard', 'caption_font_path',
			'title_max_chars', 'meta_title_max_chars', 'title_rewrite_attempts',
			'infographic_default_preset', 'infographic_placement',
		);

		$values = array();
		foreach ( $texts as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$values[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		foreach ( $checkboxes as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
		}
		if ( isset( $_POST['image_prompt_template'] ) ) {
			$values['image_prompt_template'] = sanitize_textarea_field( wp_unslash( $_POST['image_prompt_template'] ) );
		}

		LAA_Settings::update( $values );
		self::redirect_back( 'Настройки сохранены.' );
	}

	public static function handle_regenerate_secret() {
		check_admin_referer( 'laa_regenerate_secret' );
		self::require_cap();
		LAA_Settings::regenerate_secret();
		self::redirect_back( 'Секретный токен обновлён.' );
	}

	public static function handle_generate_topics() {
		check_admin_referer( 'laa_generate_topics' );
		self::require_cap( 'edit_posts' );

		$plan_id = isset( $_GET['plan_id'] ) ? (int) $_GET['plan_id'] : 0;
		$result  = LAA_Topic_Generator::generate( $plan_id );

		if ( ! $result['ok'] ) {
			self::redirect_back( 'Не удалось получить темы: ' . $result['error'], 'error' );
		}
		self::redirect_back( sprintf( 'Добавлено тем: %d (дубликатов пропущено: %d).', $result['added'], $result['duplicates'] ) );
	}

	public static function handle_run_plan() {
		check_admin_referer( 'laa_run_plan' );
		self::require_cap( 'edit_posts' );

		$plan_id = isset( $_GET['plan_id'] ) ? (int) $_GET['plan_id'] : 0;
		$result  = LAA_Scheduler::run_plan( $plan_id, true );

		self::redirect_back(
			$result['summary'] ?: 'Запуск завершён.',
			$result['ok'] ? 'success' : 'error'
		);
	}

	public static function handle_write_topic() {
		check_admin_referer( 'laa_write_topic' );
		self::require_cap( 'edit_posts' );

		$topic_id = isset( $_GET['topic_id'] ) ? (int) $_GET['topic_id'] : 0;
		$topic    = LAA_Topics::get( $topic_id );
		if ( ! $topic ) {
			self::redirect_back( 'Тема не найдена.', 'error' );
		}

		// Claim only works from `approved`; the button is also offered on
		// `failed`, so put it back in the queue first.
		if ( LAA_Topics::APPROVED !== $topic['status'] ) {
			LAA_Topics::set_status( $topic_id, LAA_Topics::APPROVED );
		}
		if ( ! LAA_Topics::claim( $topic_id ) ) {
			self::redirect_back( 'Тема уже обрабатывается.', 'error' );
		}

		$result = LAA_Publisher::write_topic( $topic );

		if ( $result['ok'] ) {
			LAA_Topics::set_status(
				$topic_id,
				LAA_Topics::DONE,
				array(
					'post_id'      => $result['post_id'],
					'last_error'   => '',
					'published_at' => current_time( 'mysql' ),
				)
			);
			self::redirect_back( 'Статья готова: ' . get_the_title( $result['post_id'] ) );
		}

		LAA_Topics::set_status( $topic_id, LAA_Topics::FAILED, array( 'last_error' => $result['error'] ) );
		self::redirect_back( 'Не удалось написать статью: ' . $result['error'], 'error' );
	}

	public static function handle_topic_status() {
		check_admin_referer( 'laa_topic_status' );
		self::require_cap( 'edit_posts' );

		$topic_id = isset( $_GET['topic_id'] ) ? (int) $_GET['topic_id'] : 0;
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( ! array_key_exists( $status, LAA_Topics::statuses() ) ) {
			self::redirect_back( 'Неизвестный статус.', 'error' );
		}

		LAA_Topics::set_status( $topic_id, $status );
		self::redirect_back( 'Статус темы изменён.' );
	}

	public static function handle_delete_topic() {
		check_admin_referer( 'laa_delete_topic' );
		self::require_cap( 'edit_posts' );

		$topic_id = isset( $_GET['topic_id'] ) ? (int) $_GET['topic_id'] : 0;
		LAA_Topics::delete( $topic_id );
		self::redirect_back( 'Тема удалена.' );
	}

	public static function handle_add_topics() {
		check_admin_referer( 'laa_add_topics' );
		self::require_cap( 'edit_posts' );

		$plan_id  = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;
		$approved = isset( $_POST['approved'] );
		$raw      = isset( $_POST['topics'] ) ? (string) wp_unslash( $_POST['topics'] ) : '';

		$rows = array();
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts  = array_map( 'trim', explode( '|', $line ) );
			$rows[] = array(
				'plan_id'  => $plan_id,
				'title'    => LAA_Article_Builder::normalise_title( sanitize_text_field( $parts[0] ) ),
				'keywords' => sanitize_text_field( $parts[1] ?? '' ),
				'angle'    => sanitize_textarea_field( $parts[2] ?? '' ),
				'status'   => $approved ? LAA_Topics::APPROVED : LAA_Topics::PENDING,
				'source'   => 'manual',
			);
		}

		if ( empty( $rows ) ) {
			self::redirect_back( 'Не из чего добавлять — поле пустое.', 'error' );
		}

		$result = LAA_Topics::add_many( $rows );
		self::redirect_back( sprintf( 'Добавлено тем: %d (дубликатов пропущено: %d).', $result['added'], $result['duplicates'] ) );
	}

	public static function handle_update_topic() {
		check_admin_referer( 'laa_update_topic' );
		self::require_cap( 'edit_posts' );

		$topic_id = isset( $_POST['topic_id'] ) ? (int) $_POST['topic_id'] : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : LAA_Topics::PENDING;

		LAA_Topics::update(
			$topic_id,
			array(
				'title'      => isset( $_POST['title'] ) ? LAA_Article_Builder::normalise_title( sanitize_text_field( wp_unslash( $_POST['title'] ) ) ) : '',
				'keywords'   => isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '',
				'angle'      => isset( $_POST['angle'] ) ? sanitize_textarea_field( wp_unslash( $_POST['angle'] ) ) : '',
				'status'     => array_key_exists( $status, LAA_Topics::statuses() ) ? $status : LAA_Topics::PENDING,
				'sort_order' => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			)
		);

		$url = self::queue_url(
			array(
				'plan_id' => isset( $_POST['plan_id_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_id_filter'] ) ) : '',
				'status'  => isset( $_POST['status_filter'] ) ? sanitize_key( wp_unslash( $_POST['status_filter'] ) ) : '',
			)
		);

		wp_safe_redirect( add_query_arg( array( 'laa_notice' => 'Тема сохранена.' ), $url ) );
		exit;
	}

	/**
	 * Bulk actions are handled on the screen itself, before any output —
	 * WP_List_Table's dropdown is named `action`, so routing them through
	 * admin-post.php would need a second field of the same name.
	 */
	private static function maybe_handle_bulk( LAA_Topics_List_Table $table ) {
		$action = $table->current_action();
		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'bulk-topics' );
		self::require_cap( 'edit_posts' );

		$ids = isset( $_POST['topic_ids'] ) ? array_map( 'intval', (array) $_POST['topic_ids'] ) : array();
		$ids = array_values( array_filter( $ids ) );

		if ( empty( $ids ) ) {
			self::redirect_back( 'Не выбрано ни одной темы.', 'error' );
		}

		switch ( $action ) {
			case 'approve':
				foreach ( $ids as $id ) {
					LAA_Topics::set_status( $id, LAA_Topics::APPROVED );
				}
				$notice = 'Одобрено тем: ' . count( $ids );
				break;
			case 'reject':
				foreach ( $ids as $id ) {
					LAA_Topics::set_status( $id, LAA_Topics::REJECTED );
				}
				$notice = 'Отклонено тем: ' . count( $ids );
				break;
			case 'requeue':
				foreach ( $ids as $id ) {
					LAA_Topics::set_status( $id, LAA_Topics::APPROVED, array( 'last_error' => '' ) );
				}
				$notice = 'Возвращено в очередь: ' . count( $ids );
				break;
			case 'top':
				LAA_Topics::move_to_top( $ids );
				$notice = 'Перемещено в начало очереди: ' . count( $ids );
				break;
			case 'delete':
				foreach ( $ids as $id ) {
					LAA_Topics::delete( $id );
				}
				$notice = 'Удалено тем: ' . count( $ids );
				break;
			default:
				return; // not one of ours
		}

		// Redirect so a refresh doesn't replay the action, keeping the filters.
		$url = self::queue_url(
			array(
				'plan_id'    => isset( $_REQUEST['plan_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plan_id'] ) ) : '',
				'status'     => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
				'laa_notice' => $notice,
			)
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_save_preset() {
		check_admin_referer( 'laa_save_preset' );
		self::require_cap();

		$preset_id = isset( $_POST['preset_id'] ) ? sanitize_key( wp_unslash( $_POST['preset_id'] ) ) : '';
		$values    = self::preset_values_from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $preset_id ) {
			$preset_id = sanitize_title( $values['name'] ) ?: 'preset';
			// Don't silently overwrite a preset that already owns this slug.
			$existing = LAA_Image_Presets::get_all();
			if ( isset( $existing[ $preset_id ] ) ) {
				$preset_id .= '-' . substr( md5( (string) microtime( true ) ), 0, 5 );
			}
		}

		$saved_id = LAA_Image_Presets::save( $preset_id, $values );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::IMAGES_SLUG,
					'preset'     => $saved_id,
					'laa_notice' => 'Пресет сохранён.',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Checkboxes that are absent from POST when unchecked need an explicit 0. */
	private static function preset_values_from_request( array $request ) {
		$values = array();
		foreach ( LAA_Image_Presets::schema() as $key => $default ) {
			if ( is_int( $default ) && in_array(
				$key,
				array( 'grayscale', 'gradient_enabled', 'caption_enabled', 'caption_autofit', 'caption_uppercase', 'shadow_enabled', 'plate_enabled' ),
				true
			) ) {
				$values[ $key ] = isset( $request[ $key ] ) ? 1 : 0;
				continue;
			}
			if ( isset( $request[ $key ] ) ) {
				$values[ $key ] = is_array( $request[ $key ] ) ? '' : wp_unslash( $request[ $key ] );
			}
		}
		return $values;
	}

	public static function handle_delete_preset() {
		check_admin_referer( 'laa_delete_preset' );
		self::require_cap();

		$preset_id = isset( $_GET['preset_id'] ) ? sanitize_key( wp_unslash( $_GET['preset_id'] ) ) : '';
		LAA_Image_Presets::delete( $preset_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::IMAGES_SLUG,
					'laa_notice' => 'Пресет удалён.',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_upload_font() {
		check_admin_referer( 'laa_upload_font' );
		self::require_cap();

		if ( empty( $_FILES['font']['name'] ) ) {
			self::redirect_back( 'Файл не выбран.', 'error' );
		}

		$name      = sanitize_file_name( (string) $_FILES['font']['name'] ); // phpcs:ignore
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'ttf', 'otf' ), true ) ) {
			self::redirect_back( 'Нужен файл .ttf или .otf.', 'error' );
		}

		$dir = LAA_Image_Composer::fonts_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			self::redirect_back( 'Не удалось создать каталог для шрифтов.', 'error' );
		}

		$target = trailingslashit( $dir ) . $name;
		if ( ! move_uploaded_file( $_FILES['font']['tmp_name'], $target ) ) { // phpcs:ignore
			self::redirect_back( 'Не удалось сохранить файл шрифта.', 'error' );
		}

		LAA_Settings::update( array( 'caption_font_path' => $target ) );
		self::redirect_back( 'Шрифт загружен: ' . $name );
	}

	public static function handle_clear_log() {
		check_admin_referer( 'laa_clear_log' );
		self::require_cap();
		LAA_Log::clear();
		self::redirect_back( 'Журнал очищен.' );
	}

	// ----------------------------------------------------------------- ajax

	/** Renders the form's current (unsaved) values onto a real pool image. */
	public static function ajax_preview_image() {
		check_ajax_referer( 'laa_preview_image', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ) );
		}
		if ( ! LAA_Image_Composer::available() ) {
			wp_send_json_error( array( 'message' => 'Расширение GD недоступно.' ) );
		}

		$preset = LAA_Image_Presets::sanitize( self::preset_values_from_request( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$source = LAA_Image_Service::pick_source();

		if ( empty( $source['path'] ) ) {
			wp_send_json_error( array( 'message' => 'Пул картинок пуст — укажите источник в настройках.' ) );
		}

		$caption = isset( $_POST['preview_title'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_title'] ) ) : '';
		if ( 'custom' === $preset['caption_source'] ) {
			$caption = $preset['caption_text'];
		} elseif ( 'none' === $preset['caption_source'] ) {
			$caption = '';
		}

		$extension = LAA_Image_Composer::extension_for( $preset['format'] );
		$tmp       = LAA_Image_Service::temp_path( $extension );
		$rendered  = LAA_Image_Composer::render( $preset, $source['path'], $caption, $tmp );

		if ( ! $rendered['ok'] ) {
			wp_send_json_error( array( 'message' => $rendered['error'] ) );
		}

		// Inlined as a data URI so the preview needs no public temp file that
		// would then have to be cleaned up.
		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore

		wp_send_json_success(
			array(
				'dataUri' => 'data:' . $rendered['mime'] . ';base64,' . base64_encode( $bytes ),
				'notice'  => $rendered['error'],
				'source'  => basename( $source['path'] ),
			)
		);
	}
}

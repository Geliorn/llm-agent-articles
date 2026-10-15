<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plan settings: the defaults table is the single source of truth — the
 * metabox, the save handler and get_plan_settings() all read it, so adding a
 * knob is one line here plus one row of markup.
 *
 * Plain PHP metaboxes, no ACF: the plugin has to stay portable to any install
 * of the network (same rule as llm-agent-github-repos).
 */
class LAA_Meta_Fields {

	const PREFIX = '_laa_';

	/** key => array(type, default) */
	public static function plan_fields() {
		return array(
			// theme & voice
			'theme'                     => array( 'textarea', '' ),
			'audience'                  => array( 'text', '' ),
			'tone'                      => array( 'text', 'спокойный, без рекламы, по делу' ),
			'extra_prompt'              => array( 'textarea', '' ),
			'banned_words'              => array( 'textarea', 'уникальный, мощный, революционный, в современном мире, не секрет, что' ),

			// topic generation
			'topics_batch'              => array( 'int', 20 ),
			'topics_autofill'           => array( 'bool', 0 ),
			'topics_autofill_threshold' => array( 'int', 3 ),
			'topics_autoapprove'        => array( 'bool', 0 ),

			// schedule
			'enabled'                   => array( 'bool', 0 ),
			'interval_value'            => array( 'int', 1 ),
			'interval_unit'             => array( 'text', 'days' ),
			'per_run'                   => array( 'int', 1 ),
			'window_from'               => array( 'text', '' ),
			'window_to'                 => array( 'text', '' ),
			'weekdays'                  => array( 'text', '1,2,3,4,5,6,7' ),

			// article
			'target_post_type'          => array( 'text', 'post' ),
			'publish_mode'              => array( 'text', 'publish' ),
			'post_author'               => array( 'int', 0 ),
			'target_terms'              => array( 'text', '' ), // JSON {taxonomy: [ids]}
			'tags_mode'                 => array( 'text', 'llm' ),
			'min_words'                 => array( 'int', 1200 ),
			'max_words'                 => array( 'int', 2000 ),
			'sections_min'              => array( 'int', 5 ),
			'sections_max'              => array( 'int', 8 ),
			'require_table'             => array( 'bool', 1 ),
			'require_lists'             => array( 'bool', 1 ),
			'require_quote'             => array( 'bool', 1 ),
			'require_faq'               => array( 'bool', 1 ),
			'require_toc'               => array( 'bool', 1 ),
			'require_takeaways'         => array( 'bool', 1 ),
			'internal_links'            => array( 'bool', 1 ),
			'llm_provider'              => array( 'text', 'inherit' ),

			// cover
			'image_mode'                => array( 'text', 'inherit' ),
			'image_preset'              => array( 'text', '' ),

			// infographic
			'infographic_mode'          => array( 'text', 'inherit' ),
			'infographic_preset'        => array( 'text', '' ),
			'infographic_points'        => array( 'int', 6 ),
			// inherit | cover | start | end — see LAA_Publisher::infographic_placement().
			'infographic_placement'     => array( 'text', 'inherit' ),

			// runtime
			'last_run_at'               => array( 'int', 0 ),
			'running_since'             => array( 'int', 0 ),
			'last_run_summary'          => array( 'text', '' ),
			'last_topics_gen_at'        => array( 'int', 0 ),
		);
	}

	/** Meta written onto a generated article, so the queue can link back. */
	public static function article_meta_keys() {
		return array(
			'_laa_plan_id'       => 'integer',
			'_laa_topic_id'      => 'integer',
			'_laa_generated'     => 'boolean',
			'_laa_provider'      => 'string',
			'_laa_seo_report'    => 'string',
			'_laa_primary_keyword' => 'string',
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . LAA_CPT::PLAN, array( __CLASS__, 'save_plan' ), 10, 3 );
	}

	/** Typed, defaulted view of one plan — everything downstream reads this. */
	public static function get_plan_settings( $plan_id ) {
		$out = array();

		foreach ( self::plan_fields() as $key => $spec ) {
			list( $type, $default ) = $spec;

			$raw = get_post_meta( $plan_id, self::PREFIX . $key, true );
			if ( '' === $raw || null === $raw ) {
				$out[ $key ] = $default;
				continue;
			}

			switch ( $type ) {
				case 'int':
					$out[ $key ] = (int) $raw;
					break;
				case 'bool':
					$out[ $key ] = (bool) (int) $raw;
					break;
				default:
					$out[ $key ] = (string) $raw;
			}
		}

		// Sanity: a max below the min silently produces stub articles.
		if ( $out['max_words'] < $out['min_words'] ) {
			$out['max_words'] = $out['min_words'];
		}
		if ( $out['sections_max'] < $out['sections_min'] ) {
			$out['sections_max'] = $out['sections_min'];
		}
		$out['plan_id'] = (int) $plan_id;

		return $out;
	}

	/** Minutes between runs, from the value+unit pair. */
	public static function interval_minutes( array $meta ) {
		$value = max( 1, (int) $meta['interval_value'] );
		return 'hours' === $meta['interval_unit'] ? $value * 60 : $value * 60 * 24;
	}

	/**
	 * Which user the article is published as.
	 *
	 * Matters more than it looks: wp_insert_post() without post_author falls
	 * back to get_current_user_id(), which is 0 in cron — so on the scheduled
	 * path every article would come out authorless. Order: the plan's own
	 * setting, then whoever created the plan, then the first administrator.
	 */
	public static function resolve_author( array $meta ) {
		$chosen = (int) $meta['post_author'];
		if ( $chosen && get_userdata( $chosen ) ) {
			return $chosen;
		}

		$plan_author = (int) get_post_field( 'post_author', (int) $meta['plan_id'] );
		if ( $plan_author && get_userdata( $plan_author ) ) {
			return $plan_author;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);

		return $admins ? (int) $admins[0] : 0;
	}

	/** @return array<string,array<int,int>> decoded target_terms */
	public static function target_terms( array $meta ) {
		$decoded = json_decode( (string) $meta['target_terms'], true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $taxonomy => $ids ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
			if ( $ids ) {
				$out[ $taxonomy ] = $ids;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------ metaboxes

	public static function add_meta_boxes() {
		add_meta_box( 'laa-plan-theme', 'Тема и стиль', array( __CLASS__, 'render_theme_box' ), LAA_CPT::PLAN, 'normal', 'high' );
		add_meta_box( 'laa-plan-schedule', 'Расписание публикации', array( __CLASS__, 'render_schedule_box' ), LAA_CPT::PLAN, 'normal', 'high' );
		add_meta_box( 'laa-plan-article', 'Требования к статье', array( __CLASS__, 'render_article_box' ), LAA_CPT::PLAN, 'normal', 'default' );
		add_meta_box( 'laa-plan-image', 'Обложка', array( __CLASS__, 'render_image_box' ), LAA_CPT::PLAN, 'side', 'default' );
		add_meta_box( 'laa-plan-status', 'Состояние', array( __CLASS__, 'render_status_box' ), LAA_CPT::PLAN, 'side', 'high' );
	}

	public static function render_theme_box( $post ) {
		$m = self::get_plan_settings( $post->ID );
		wp_nonce_field( 'laa_save_plan', 'laa_plan_nonce' );
		?>
		<table class="form-table laa-form">
			<tr>
				<th><label for="laa_theme">Направление</label></th>
				<td>
					<textarea id="laa_theme" name="laa_theme" rows="3" class="large-text"><?php echo esc_textarea( $m['theme'] ); ?></textarea>
					<p class="description">О чём этот план. Отсюда модель берёт темы статей: «Как работают языковые модели: объяснения для не-инженеров».</p>
				</td>
			</tr>
			<tr>
				<th><label for="laa_audience">Аудитория</label></th>
				<td><input type="text" id="laa_audience" name="laa_audience" class="large-text" value="<?php echo esc_attr( $m['audience'] ); ?>" placeholder="продакт-менеджеры и аналитики без опыта в ML"></td>
			</tr>
			<tr>
				<th><label for="laa_tone">Тон</label></th>
				<td><input type="text" id="laa_tone" name="laa_tone" class="large-text" value="<?php echo esc_attr( $m['tone'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="laa_extra_prompt">Доп. указания</label></th>
				<td>
					<textarea id="laa_extra_prompt" name="laa_extra_prompt" rows="3" class="large-text"><?php echo esc_textarea( $m['extra_prompt'] ); ?></textarea>
					<p class="description">Уходит в промпт как есть — и при подборе тем, и при написании статьи.</p>
				</td>
			</tr>
			<tr>
				<th><label for="laa_banned_words">Запрещённые слова</label></th>
				<td><textarea id="laa_banned_words" name="laa_banned_words" rows="2" class="large-text"><?php echo esc_textarea( $m['banned_words'] ); ?></textarea></td>
			</tr>
			<tr>
				<th>Подбор тем</th>
				<td>
					<label>за раз <input type="number" name="laa_topics_batch" min="1" max="60" value="<?php echo esc_attr( $m['topics_batch'] ); ?>" style="width:70px"> тем</label><br>
					<label><input type="checkbox" name="laa_topics_autofill" value="1" <?php checked( $m['topics_autofill'] ); ?>> добирать автоматически, когда одобренных осталось меньше <input type="number" name="laa_topics_autofill_threshold" min="1" max="50" value="<?php echo esc_attr( $m['topics_autofill_threshold'] ); ?>" style="width:60px"></label><br>
					<label><input type="checkbox" name="laa_topics_autoapprove" value="1" <?php checked( $m['topics_autoapprove'] ); ?>> одобрять темы автоматически, без согласования</label>
					<p class="description">Автоодобрение выключено по умолчанию: смысл очереди в том, что список тем проходит через человека.</p>
				</td>
			</tr>
			<tr>
				<th><label for="laa_llm_provider">Модель</label></th>
				<td>
					<select id="laa_llm_provider" name="laa_llm_provider">
						<option value="inherit" <?php selected( $m['llm_provider'], 'inherit' ); ?>>Как в настройках плагина</option>
						<?php foreach ( LAA_Settings::llm_providers() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $m['llm_provider'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_schedule_box( $post ) {
		$m        = self::get_plan_settings( $post->ID );
		$weekdays = array_map( 'intval', array_filter( explode( ',', $m['weekdays'] ) ) );
		$names    = array( 1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс' );
		?>
		<table class="form-table laa-form">
			<tr>
				<th>Включён</th>
				<td><label><input type="checkbox" name="laa_enabled" value="1" <?php checked( $m['enabled'] ); ?>> публиковать по расписанию</label></td>
			</tr>
			<tr>
				<th>Как часто</th>
				<td>
					раз в <input type="number" name="laa_interval_value" min="1" max="999" value="<?php echo esc_attr( $m['interval_value'] ); ?>" style="width:70px">
					<select name="laa_interval_unit">
						<option value="hours" <?php selected( $m['interval_unit'], 'hours' ); ?>>часов</option>
						<option value="days" <?php selected( $m['interval_unit'], 'days' ); ?>>дней</option>
					</select>
					&nbsp;·&nbsp; за один раз <input type="number" name="laa_per_run" min="1" max="10" value="<?php echo esc_attr( $m['per_run'] ); ?>" style="width:60px"> статей
				</td>
			</tr>
			<tr>
				<th>Окно публикации</th>
				<td>
					с <input type="time" name="laa_window_from" value="<?php echo esc_attr( $m['window_from'] ); ?>">
					до <input type="time" name="laa_window_to" value="<?php echo esc_attr( $m['window_to'] ); ?>">
					<p class="description">Пусто — публиковать в любое время. Время сайта (<?php echo esc_html( wp_timezone_string() ); ?>).</p>
				</td>
			</tr>
			<tr>
				<th>Дни недели</th>
				<td>
					<?php foreach ( $names as $number => $label ) : ?>
						<label style="margin-right:10px"><input type="checkbox" name="laa_weekdays[]" value="<?php echo esc_attr( $number ); ?>" <?php checked( in_array( $number, $weekdays, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_article_box( $post ) {
		$m = self::get_plan_settings( $post->ID );
		?>
		<table class="form-table laa-form">
			<tr>
				<th>Куда публиковать</th>
				<td>
					<select name="laa_target_post_type">
						<?php foreach ( LAA_CPT::target_post_type_choices() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $m['target_post_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="laa_publish_mode">
						<option value="publish" <?php selected( $m['publish_mode'], 'publish' ); ?>>сразу публиковать</option>
						<option value="draft" <?php selected( $m['publish_mode'], 'draft' ); ?>>в черновики</option>
						<option value="pending" <?php selected( $m['publish_mode'], 'pending' ); ?>>на утверждение</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="laa_post_author">Автор публикаций</label></th>
				<td>
					<?php
					wp_dropdown_users(
						array(
							'id'                => 'laa_post_author',
							'name'              => 'laa_post_author',
							'selected'          => (int) $m['post_author'],
							'include_selected'  => true,
							'capability'        => array( 'edit_posts' ),
							'show_option_none'  => '— автор плана (' . esc_html( get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post->ID ) ) ) . ')',
							'option_none_value' => 0,
						)
					);
					?>
					<p class="description">От чьего имени выходят статьи этого плана. Важно для публикации по расписанию: в кроне нет текущего пользователя, и без этой настройки запись осталась бы без автора.</p>
				</td>
			</tr>
			<tr>
				<th>Рубрики и термины</th>
				<td>
					<?php self::render_term_pickers( $m ); ?>
					<p class="description">Применяется к каждой статье этого плана.</p>
				</td>
			</tr>
			<tr>
				<th>Теги</th>
				<td>
					<select name="laa_tags_mode">
						<option value="llm" <?php selected( $m['tags_mode'], 'llm' ); ?>>брать теги от модели (создавать новые при необходимости)</option>
						<option value="existing_only" <?php selected( $m['tags_mode'], 'existing_only' ); ?>>только совпавшие с существующими тегами</option>
						<option value="none" <?php selected( $m['tags_mode'], 'none' ); ?>>не проставлять теги</option>
					</select>
				</td>
			</tr>
			<tr>
				<th>Объём</th>
				<td>
					от <input type="number" name="laa_min_words" min="300" max="6000" step="50" value="<?php echo esc_attr( $m['min_words'] ); ?>" style="width:90px">
					до <input type="number" name="laa_max_words" min="300" max="8000" step="50" value="<?php echo esc_attr( $m['max_words'] ); ?>" style="width:90px"> слов
				</td>
			</tr>
			<tr>
				<th>Разделов (H2)</th>
				<td>
					от <input type="number" name="laa_sections_min" min="2" max="15" value="<?php echo esc_attr( $m['sections_min'] ); ?>" style="width:70px">
					до <input type="number" name="laa_sections_max" min="2" max="15" value="<?php echo esc_attr( $m['sections_max'] ); ?>" style="width:70px">
				</td>
			</tr>
			<tr>
				<th>Структура</th>
				<td>
					<label><input type="checkbox" name="laa_require_toc" value="1" <?php checked( $m['require_toc'] ); ?>> оглавление</label><br>
					<label><input type="checkbox" name="laa_require_takeaways" value="1" <?php checked( $m['require_takeaways'] ); ?>> блок «Коротко»</label><br>
					<label><input type="checkbox" name="laa_require_lists" value="1" <?php checked( $m['require_lists'] ); ?>> списки</label><br>
					<label><input type="checkbox" name="laa_require_table" value="1" <?php checked( $m['require_table'] ); ?>> таблица</label><br>
					<label><input type="checkbox" name="laa_require_quote" value="1" <?php checked( $m['require_quote'] ); ?>> цитата</label><br>
					<label><input type="checkbox" name="laa_require_faq" value="1" <?php checked( $m['require_faq'] ); ?>> блок «Частые вопросы»</label><br>
					<label><input type="checkbox" name="laa_internal_links" value="1" <?php checked( $m['internal_links'] ); ?>> перелинковка (каталог + соседние статьи плана)</label>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function render_term_pickers( array $m ) {
		$selected   = self::target_terms( $m );
		$taxonomies = get_object_taxonomies( $m['target_post_type'] ?: 'post', 'objects' );

		$printed = false;
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public || 'post_tag' === $taxonomy->name ) {
				continue; // tags come from the model, see tags_mode
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
					'number'     => 200,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$printed = true;
			$chosen  = $selected[ $taxonomy->name ] ?? array();
			echo '<p style="margin:0 0 6px"><strong>' . esc_html( $taxonomy->labels->name ) . '</strong><br>';
			echo '<select name="laa_terms[' . esc_attr( $taxonomy->name ) . '][]" multiple size="4" style="min-width:280px">';
			foreach ( $terms as $term ) {
				printf(
					'<option value="%1$d" %2$s>%3$s</option>',
					(int) $term->term_id,
					selected( in_array( (int) $term->term_id, $chosen, true ), true, false ),
					esc_html( $term->name )
				);
			}
			echo '</select></p>';
		}

		if ( ! $printed ) {
			echo '<p class="description">У выбранного типа записи нет подходящих таксономий.</p>';
		}
	}

	public static function render_image_box( $post ) {
		$m       = self::get_plan_settings( $post->ID );
		$presets = LAA_Image_Presets::get_all();
		?>
		<p>
			<label for="laa_image_mode"><strong>Режим</strong></label><br>
			<select id="laa_image_mode" name="laa_image_mode" style="width:100%">
				<option value="inherit" <?php selected( $m['image_mode'], 'inherit' ); ?>>Как в настройках плагина</option>
				<option value="generate" <?php selected( $m['image_mode'], 'generate' ); ?>>Генерировать нейросетью</option>
				<option value="library" <?php selected( $m['image_mode'], 'library' ); ?>>Из библиотеки + наложение</option>
				<option value="none" <?php selected( $m['image_mode'], 'none' ); ?>>Без обложки</option>
			</select>
		</p>
		<p>
			<label for="laa_image_preset"><strong>Пресет наложения</strong></label><br>
			<select id="laa_image_preset" name="laa_image_preset" style="width:100%">
				<option value="">По умолчанию</option>
				<?php foreach ( $presets as $id => $preset ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $m['image_preset'], $id ); ?>><?php echo esc_html( $preset['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LAA_Admin::IMAGES_SLUG ) ); ?>">Настроить пресеты →</a></p>

		<hr>

		<p>
			<label for="laa_infographic_mode"><strong>Инфографика</strong></label><br>
			<select id="laa_infographic_mode" name="laa_infographic_mode" style="width:100%">
				<option value="inherit" <?php selected( $m['infographic_mode'], 'inherit' ); ?>>Как в настройках плагина</option>
				<option value="on" <?php selected( $m['infographic_mode'], 'on' ); ?>>Делать</option>
				<option value="off" <?php selected( $m['infographic_mode'], 'off' ); ?>>Не делать</option>
			</select>
		</p>
		<p>
			<label for="laa_infographic_preset">Пресет</label><br>
			<select id="laa_infographic_preset" name="laa_infographic_preset" style="width:100%">
				<option value="">По умолчанию</option>
				<?php foreach ( LAA_Infographic_Presets::get_all() as $id => $item ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $m['infographic_preset'], $id ); ?>><?php echo esc_html( $item['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="laa_infographic_points">Пунктов</label>
			<input type="number" id="laa_infographic_points" name="laa_infographic_points" min="3" max="12" value="<?php echo esc_attr( $m['infographic_points'] ); ?>" style="width:70px">
		</p>
		<p>
			<label for="laa_infographic_placement">Куда ставить</label><br>
			<select id="laa_infographic_placement" name="laa_infographic_placement" style="width:100%">
				<option value="inherit" <?php selected( $m['infographic_placement'], 'inherit' ); ?>>Как в настройках плагина</option>
				<?php foreach ( LAA_Settings::infographic_placements() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $m['infographic_placement'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">Обложкой — инфографика становится миниатюрой записи вместо обычной обложки, в текст не вставляется. В начале или в конце — картинка в тексте, обложка делается как обычно.</p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LAA_Admin::INFOGRAPHIC_SLUG ) ); ?>">Настроить инфографику →</a></p>
		<?php
	}

	public static function render_status_box( $post ) {
		$m         = self::get_plan_settings( $post->ID );
		$counts    = LAA_Topics::counts_by_status( $post->ID );
		$queue_url = admin_url( 'admin.php?page=' . LAA_Admin::MAIN_SLUG . '&plan_id=' . $post->ID );
		?>
		<p>
			Тем в очереди:<br>
			<?php foreach ( LAA_Topics::statuses() as $status => $label ) : ?>
				<?php echo esc_html( $label ); ?>: <strong><?php echo (int) $counts[ $status ]; ?></strong><br>
			<?php endforeach; ?>
		</p>
		<p><a href="<?php echo esc_url( $queue_url ); ?>" class="button">Очередь тем</a></p>
		<p>
			Последний запуск:
			<?php echo $m['last_run_at'] ? esc_html( human_time_diff( $m['last_run_at'] ) . ' назад' ) : '—'; ?><br>
			<span class="description"><?php echo esc_html( $m['last_run_summary'] ); ?></span>
		</p>
		<?php if ( $post->ID && 'auto-draft' !== $post->post_status ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( LAA_Admin::action_url( 'laa_generate_topics', array( 'plan_id' => $post->ID ) ) ); ?>">Предложить темы</a>
				<a class="button" href="<?php echo esc_url( LAA_Admin::action_url( 'laa_run_plan', array( 'plan_id' => $post->ID ) ) ); ?>">Запустить сейчас</a>
			</p>
		<?php endif; ?>
		<?php
	}

	// ----------------------------------------------------------------- save

	public static function save_plan( $post_id, $post, $update ) {
		if ( ! isset( $_POST['laa_plan_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['laa_plan_nonce'] ) ), 'laa_save_plan' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$runtime = array( 'last_run_at', 'running_since', 'last_run_summary', 'last_topics_gen_at' );

		foreach ( self::plan_fields() as $key => $spec ) {
			if ( in_array( $key, $runtime, true ) ) {
				continue; // written by the scheduler, never by the form
			}
			list( $type ) = $spec;
			$field        = 'laa_' . $key;

			switch ( $type ) {
				case 'bool':
					update_post_meta( $post_id, self::PREFIX . $key, isset( $_POST[ $field ] ) ? 1 : 0 );
					break;
				case 'int':
					update_post_meta( $post_id, self::PREFIX . $key, isset( $_POST[ $field ] ) ? (int) $_POST[ $field ] : 0 );
					break;
				case 'textarea':
					update_post_meta( $post_id, self::PREFIX . $key, isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '' );
					break;
				default:
					if ( 'weekdays' === $key || 'target_terms' === $key ) {
						break; // handled below
					}
					update_post_meta( $post_id, self::PREFIX . $key, isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '' );
			}
		}

		$weekdays = isset( $_POST['laa_weekdays'] ) ? array_map( 'intval', (array) $_POST['laa_weekdays'] ) : array();
		$weekdays = array_values( array_filter( $weekdays, function ( $d ) { return $d >= 1 && $d <= 7; } ) );
		update_post_meta( $post_id, self::PREFIX . 'weekdays', implode( ',', $weekdays ? $weekdays : range( 1, 7 ) ) );

		$terms = array();
		if ( isset( $_POST['laa_terms'] ) && is_array( $_POST['laa_terms'] ) ) {
			foreach ( wp_unslash( $_POST['laa_terms'] ) as $taxonomy => $ids ) { // phpcs:ignore
				$taxonomy = sanitize_key( $taxonomy );
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
				if ( $ids ) {
					$terms[ $taxonomy ] = $ids;
				}
			}
		}
		update_post_meta( $post_id, self::PREFIX . 'target_terms', wp_json_encode( $terms ) );
	}
}

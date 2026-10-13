<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional WP-CLI surface — handy on hosts with SSH, and the only way to
 * render a preset preview to a file without opening the admin.
 */
class LAA_CLI {

	public static function register() {
		WP_CLI::add_command( 'laa', __CLASS__ );
	}

	/**
	 * Run the tick once (same logic as WP-Cron and the HTTP endpoint).
	 *
	 * ## EXAMPLES
	 *     wp laa tick
	 */
	public function tick( $args, $assoc_args ) {
		LAA_Scheduler::run_tick();
		WP_CLI::success( 'Тик выполнен — подробности в журнале.' );
	}

	/**
	 * Run one plan now, ignoring its interval and publishing window.
	 *
	 * ## OPTIONS
	 * --plan=<id>
	 * : Post ID of the laa_plan.
	 *
	 * ## EXAMPLES
	 *     wp laa run --plan=123
	 */
	public function run( $args, $assoc_args ) {
		$plan_id = isset( $assoc_args['plan'] ) ? (int) $assoc_args['plan'] : 0;
		if ( ! $plan_id || LAA_CPT::PLAN !== get_post_type( $plan_id ) ) {
			WP_CLI::error( 'Передайте --plan=<post_id> существующего контент-плана.' );
		}

		$result = LAA_Scheduler::run_plan( $plan_id, true );
		if ( $result['ok'] ) {
			WP_CLI::success( $result['summary'] ?: 'Готово.' );
		} else {
			WP_CLI::warning( $result['summary'] ?: 'Запуск завершился с ошибками — см. журнал.' );
		}
	}

	/**
	 * Ask the model for new topics for a plan.
	 *
	 * ## OPTIONS
	 * --plan=<id>
	 * : Post ID of the laa_plan.
	 *
	 * [--count=<n>]
	 * : How many topics to ask for (defaults to the plan's own batch size).
	 *
	 * ## EXAMPLES
	 *     wp laa topics --plan=123 --count=30
	 */
	public function topics( $args, $assoc_args ) {
		$plan_id = isset( $assoc_args['plan'] ) ? (int) $assoc_args['plan'] : 0;
		$count   = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : null;

		if ( ! $plan_id || LAA_CPT::PLAN !== get_post_type( $plan_id ) ) {
			WP_CLI::error( 'Передайте --plan=<post_id> существующего контент-плана.' );
		}

		$result = LAA_Topic_Generator::generate( $plan_id, $count );
		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['error'] );
		}
		WP_CLI::success( sprintf( 'Добавлено тем: %d, дубликатов пропущено: %d.', $result['added'], $result['duplicates'] ) );
	}

	/**
	 * Write and publish one topic right now.
	 *
	 * ## OPTIONS
	 * --topic=<id>
	 * : Row id from the topic queue.
	 *
	 * ## EXAMPLES
	 *     wp laa write --topic=42
	 */
	public function write( $args, $assoc_args ) {
		$topic_id = isset( $assoc_args['topic'] ) ? (int) $assoc_args['topic'] : 0;
		$topic    = $topic_id ? LAA_Topics::get( $topic_id ) : null;

		if ( ! $topic ) {
			WP_CLI::error( 'Тема не найдена. Список: wp laa list-topics' );
		}

		$result = LAA_Publisher::write_topic( $topic );
		if ( ! $result['ok'] ) {
			LAA_Topics::set_status( $topic_id, LAA_Topics::FAILED, array( 'last_error' => $result['error'] ) );
			WP_CLI::error( $result['error'] );
		}

		LAA_Topics::set_status(
			$topic_id,
			LAA_Topics::DONE,
			array(
				'post_id'      => $result['post_id'],
				'last_error'   => '',
				'published_at' => current_time( 'mysql' ),
			)
		);

		WP_CLI::success( sprintf( 'Статья #%d: %s', $result['post_id'], get_the_title( $result['post_id'] ) ) );
		WP_CLI::line( LAA_SEO_Checker::summary( $result['report'] ) );
		foreach ( $result['report']['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
	}

	/**
	 * List the topic queue.
	 *
	 * ## OPTIONS
	 * [--plan=<id>]
	 * : Limit to one plan.
	 *
	 * [--status=<status>]
	 * : pending, approved, rejected, writing, done or failed.
	 *
	 * [--limit=<n>]
	 * : How many rows (default 50).
	 *
	 * ## EXAMPLES
	 *     wp laa list-topics --status=approved
	 */
	public function list_topics( $args, $assoc_args ) {
		$rows = LAA_Topics::query(
			array(
				'plan_id'  => isset( $assoc_args['plan'] ) ? (int) $assoc_args['plan'] : null,
				'status'   => isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : null,
				'per_page' => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50,
				'page'     => 1,
			)
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'      => $row['id'],
				'plan'    => $row['plan_id'] ? get_the_title( (int) $row['plan_id'] ) : '—',
				'status'  => $row['status'],
				'order'   => $row['sort_order'],
				'title'   => mb_substr( $row['title'], 0, 70 ),
				'post_id' => $row['post_id'] ?: '—',
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::line( 'Очередь пуста.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'plan', 'status', 'order', 'title', 'post_id' ) );
	}

	/**
	 * Re-make the cover of an article that already exists, with the current
	 * preset. Needed whenever a preset changes (or was wrong): the article's
	 * text is fine, only the picture has to be rebuilt.
	 *
	 * ## OPTIONS
	 * <post>...
	 * : One or more post IDs.
	 *
	 * [--preset=<id>]
	 * : Override the preset the plan would use.
	 *
	 * ## EXAMPLES
	 *     wp laa recover 692
	 *     wp laa recover 692 701 --preset=default
	 */
	public function recover( $args, $assoc_args ) {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Передайте ID записей: wp laa recover 692' );
		}

		foreach ( $args as $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			if ( ! $post ) {
				WP_CLI::warning( "Запись #{$post_id} не найдена." );
				continue;
			}

			$plan_id = (int) get_post_meta( $post_id, '_laa_plan_id', true );
			$meta    = $plan_id
				? LAA_Meta_Fields::get_plan_settings( $plan_id )
				: array(
					'image_mode'   => 'inherit',
					'image_preset' => '',
					'theme'        => '',
					'plan_id'      => 0,
				);

			if ( isset( $assoc_args['preset'] ) ) {
				$meta['image_preset'] = sanitize_key( $assoc_args['preset'] );
			}
			// 'none' would make this a no-op and silently report success.
			if ( 'none' === $meta['image_mode'] ) {
				$meta['image_mode'] = 'library';
			}

			$old_thumbnail = (int) get_post_thumbnail_id( $post_id );

			$result = LAA_Image_Service::attach_cover( $post_id, array( 'title' => $post->post_title ), $meta );

			if ( ! $result['ok'] ) {
				WP_CLI::warning( "Запись #{$post_id}: " . $result['error'] );
				continue;
			}

			WP_CLI::success( sprintf( 'Запись #%d: новая обложка #%d (%s).', $post_id, $result['attachment_id'], $result['mode'] ) );
			if ( $old_thumbnail && $old_thumbnail !== $result['attachment_id'] ) {
				// Left in the library on purpose — deleting someone's media is
				// not something a re-render should decide.
				WP_CLI::line( "  старая обложка #{$old_thumbnail} осталась в медиабиблиотеке" );
			}
		}
	}

	/**
	 * Build an infographic for an article that already exists and insert it.
	 *
	 * ## OPTIONS
	 * <post>...
	 * : One or more post IDs.
	 *
	 * [--preset=<id>]
	 * : Override the preset the plan would use.
	 *
	 * [--points=<n>]
	 * : How many theses to ask for.
	 *
	 * ## EXAMPLES
	 *     wp laa infographic 692 --points=6
	 */
	public function infographic( $args, $assoc_args ) {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Передайте ID записей: wp laa infographic 692' );
		}

		foreach ( $args as $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			if ( ! $post ) {
				WP_CLI::warning( "Запись #{$post_id} не найдена." );
				continue;
			}

			$plan_id = (int) get_post_meta( $post_id, '_laa_plan_id', true );
			$meta    = $plan_id ? LAA_Meta_Fields::get_plan_settings( $plan_id ) : LAA_Meta_Fields::get_plan_settings( 0 );

			if ( isset( $assoc_args['preset'] ) ) {
				$meta['infographic_preset'] = sanitize_key( $assoc_args['preset'] );
			}
			if ( isset( $assoc_args['points'] ) ) {
				$meta['infographic_points'] = (int) $assoc_args['points'];
			}

			// The article is already written, so rebuild the plan shape the
			// theses generator expects out of what's stored on the post.
			$article = array(
				'title'     => $post->post_title,
				'lead'      => (string) $post->post_excerpt,
				'takeaways' => array(),
				'sections'  => self::sections_of( $post->post_content ),
			);

			$made = LAA_Infographic_Service::create_for_post( $post_id, $article, $meta );

			foreach ( $made['notes'] as $note ) {
				WP_CLI::line( '  ' . $note );
			}

			if ( ! $made['ok'] ) {
				WP_CLI::warning( "Запись #{$post_id}: " . $made['error'] );
				continue;
			}

			WP_CLI::success( sprintf( 'Запись #%d: инфографика #%d, пунктов %d', $post_id, $made['attachment_id'], count( $made['points'] ) ) );
			WP_CLI::line( '  вставьте её в текст вручную или перегенерируйте статью — эта команда только собирает картинку' );
		}
	}

	/** H2 headings of an existing post, used as input for the theses. */
	private static function sections_of( $content ) {
		preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', (string) $content, $matches );

		$out = array();
		foreach ( (array) $matches[1] as $heading ) {
			$text = trim( wp_strip_all_tags( $heading ) );
			if ( '' !== $text && ! in_array( $text, array( 'Коротко', 'Что в статье', 'Частые вопросы', 'Читайте также' ), true ) ) {
				$out[] = array( 'h2' => $text );
			}
		}
		return $out;
	}

	/**
	 * Render a preset onto a pool image, to a file — a preview without the admin.
	 *
	 * ## OPTIONS
	 * [--preset=<id>]
	 * : Preset id (defaults to the configured default).
	 *
	 * [--title=<text>]
	 * : Caption text.
	 *
	 * [--out=<path>]
	 * : Where to write the file (defaults to the uploads temp folder).
	 *
	 * ## EXAMPLES
	 *     wp laa preview-image --preset=default --title="Что такое MCP"
	 */
	public function preview_image( $args, $assoc_args ) {
		$preset = LAA_Image_Presets::get( isset( $assoc_args['preset'] ) ? sanitize_key( $assoc_args['preset'] ) : LAA_Settings::get( 'default_preset', 'default' ) );
		$source = LAA_Image_Service::pick_source();

		if ( empty( $source['path'] ) ) {
			WP_CLI::error( 'Пул картинок пуст — укажите источник в настройках плагина.' );
		}

		$title = $assoc_args['title'] ?? 'Пример заголовка статьи для проверки подписи';
		$out   = $assoc_args['out'] ?? LAA_Image_Service::temp_path( LAA_Image_Composer::extension_for( $preset['format'] ) );

		$result = LAA_Image_Composer::render( $preset, $source['path'], $title, $out );
		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['error'] );
		}
		if ( '' !== $result['error'] ) {
			WP_CLI::warning( $result['error'] );
		}

		WP_CLI::success( 'Готово: ' . $result['path'] );
	}
}

LAA_CLI::register();

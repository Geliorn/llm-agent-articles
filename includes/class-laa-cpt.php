<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One post type: laa_plan — a "content plan", i.e. a theme plus its
 * generation and schedule settings. Not public (nothing to show on the
 * front end), but with a UI, same as github_repo_rule.
 *
 * The articles themselves go into whatever post type the plan points at
 * (post by default), so there is deliberately no CPT for them: the blog
 * templates, feeds, categories and search already work for posts.
 */
class LAA_CPT {

	const PLAN = 'laa_plan';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
	}

	public static function register_post_types() {
		register_post_type(
			self::PLAN,
			array(
				'label'        => 'Контент-планы',
				'labels'       => array(
					'name'          => 'Контент-планы',
					'singular_name' => 'Контент-план',
					'add_new'       => 'Добавить план',
					'add_new_item'  => 'Новый контент-план',
					'edit_item'     => 'Контент-план',
					'search_items'  => 'Поиск планов',
					'all_items'     => 'Все планы',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'menu_icon'    => 'dashicons-calendar-alt',
				'show_in_menu' => LAA_Admin::MAIN_SLUG,
			)
		);
	}

	/**
	 * Post types a plan may publish into. Deliberately NOT "every public type
	 * with an editor": that list also contains the catalog types of the other
	 * plugins of the network (github_repo, contractor), and one mis-click
	 * would drop a blog article into the catalog. Articles belong in `post`;
	 * `page` is here only for a future hierarchical knowledge-base section
	 * (see research/content-plan-200.md). Anything else has to opt in through
	 * the filter.
	 */
	public static function target_post_type_choices() {
		$out = array();

		foreach ( array( 'post', 'page' ) as $name ) {
			$type = get_post_type_object( $name );
			if ( $type ) {
				$out[ $name ] = $type->labels->singular_name . ' (' . $name . ')';
			}
		}

		/**
		 * Adds a post type to the "publish into" dropdown.
		 *
		 * @param array $out post type name => label
		 */
		return (array) apply_filters( 'laa_target_post_type_choices', $out );
	}
}

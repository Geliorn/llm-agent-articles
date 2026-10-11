<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The approval screen. Modelled on AUI_List_Table, but over the queue table
 * rather than an option — the whole point of this screen is bulk approving,
 * reordering and adding your own topics in one place.
 */
class LAA_Topics_List_Table extends WP_List_Table {

	/** @var int */
	private $plan_id;

	/** @var string */
	private $status;

	/** @var string */
	private $search;

	public function __construct( $plan_id, $status, $search ) {
		parent::__construct(
			array(
				'singular' => 'topic',
				'plural'   => 'topics',
				'ajax'     => false,
			)
		);
		$this->plan_id = $plan_id;
		$this->status  = $status;
		$this->search  = $search;
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'title'    => 'Тема',
			'keywords' => 'Ключевые фразы',
			'plan'     => 'План',
			'status'   => 'Статус',
			'result'   => 'Результат',
			'updated'  => 'Обновлена',
		);
	}

	public function get_sortable_columns() {
		return array(
			'title'   => array( 'title', false ),
			'status'  => array( 'status', false ),
			'updated' => array( 'created_at', true ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'approve' => 'Одобрить',
			'reject'  => 'Отклонить',
			'requeue' => 'Вернуть в очередь',
			'top'     => 'В начало очереди',
			'delete'  => 'Удалить',
		);
	}

	public function prepare_items() {
		$per_page = 50;
		$page     = $this->get_pagenum();

		$args = array(
			'plan_id'  => '' === $this->plan_id ? null : (int) $this->plan_id,
			'status'   => '' === $this->status ? null : $this->status,
			'search'   => $this->search,
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'sort_order', // phpcs:ignore WordPress.Security.NonceVerification
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc',           // phpcs:ignore WordPress.Security.NonceVerification
			'per_page' => $per_page,
			'page'     => $page,
		);

		$this->items = LAA_Topics::query( $args );
		$total       = LAA_Topics::count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	public function get_views() {
		$counts  = LAA_Topics::counts_by_status( '' === $this->plan_id ? null : (int) $this->plan_id );
		$base    = LAA_Admin::queue_url( array( 'plan_id' => $this->plan_id ) );
		$views   = array();
		$total   = array_sum( $counts );

		$views['all'] = sprintf(
			'<a href="%s"%s>Все <span class="count">(%d)</span></a>',
			esc_url( $base ),
			'' === $this->status ? ' class="current"' : '',
			$total
		);

		foreach ( LAA_Topics::statuses() as $status => $label ) {
			if ( 0 === $counts[ $status ] ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( LAA_Admin::queue_url( array( 'plan_id' => $this->plan_id, 'status' => $status ) ) ),
				$this->status === $status ? ' class="current"' : '',
				esc_html( $label ),
				$counts[ $status ]
			);
		}

		return $views;
	}

	public function no_items() {
		echo 'Тем пока нет. Создайте контент-план и нажмите «Предложить темы», либо добавьте свои ниже.';
	}

	// ----------------------------------------------------------- columns

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="topic_ids[]" value="%d" />', (int) $item['id'] );
	}

	public function column_title( $item ) {
		$title = '<strong>' . esc_html( $item['title'] ) . '</strong>';

		if ( ! empty( $item['angle'] ) ) {
			$title .= '<div class="laa-angle">' . esc_html( wp_trim_words( $item['angle'], 26 ) ) . '</div>';
		}
		if ( ! empty( $item['last_error'] ) ) {
			$title .= '<div class="laa-error">' . esc_html( wp_trim_words( $item['last_error'], 30 ) ) . '</div>';
		}

		$actions = array(
			'edit' => sprintf( '<a href="%s">Править</a>', esc_url( LAA_Admin::queue_url( array( 'plan_id' => $this->plan_id, 'status' => $this->status, 'edit' => (int) $item['id'] ) ) ) ),
		);

		if ( in_array( $item['status'], array( LAA_Topics::PENDING, LAA_Topics::REJECTED, LAA_Topics::FAILED ), true ) ) {
			$actions['approve'] = sprintf( '<a href="%s">Одобрить</a>', esc_url( LAA_Admin::action_url( 'laa_topic_status', array( 'topic_id' => (int) $item['id'], 'status' => LAA_Topics::APPROVED ) ) ) );
		}
		if ( LAA_Topics::APPROVED === $item['status'] ) {
			$actions['reject'] = sprintf( '<a href="%s">Отклонить</a>', esc_url( LAA_Admin::action_url( 'laa_topic_status', array( 'topic_id' => (int) $item['id'], 'status' => LAA_Topics::REJECTED ) ) ) );
		}
		if ( in_array( $item['status'], array( LAA_Topics::APPROVED, LAA_Topics::FAILED ), true ) ) {
			$actions['write'] = sprintf(
				'<a href="%s" onclick="return confirm(\'Статья будет написана и опубликована прямо сейчас. Это может занять минуту-две. Продолжить?\')">Написать сейчас</a>',
				esc_url( LAA_Admin::action_url( 'laa_write_topic', array( 'topic_id' => (int) $item['id'] ) ) )
			);
		}
		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'Удалить тему?\')">Удалить</a>',
			esc_url( LAA_Admin::action_url( 'laa_delete_topic', array( 'topic_id' => (int) $item['id'] ) ) )
		);

		return $title . $this->row_actions( $actions );
	}

	public function column_keywords( $item ) {
		return esc_html( $item['keywords'] );
	}

	public function column_plan( $item ) {
		if ( ! $item['plan_id'] ) {
			return '—';
		}
		$title = get_the_title( (int) $item['plan_id'] );
		return sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( (int) $item['plan_id'] ) ), esc_html( $title ?: '#' . $item['plan_id'] ) );
	}

	public function column_status( $item ) {
		$label = LAA_Topics::status_label( $item['status'] );
		$extra = (int) $item['attempts'] > 1 ? ' <span class="description">(попыток: ' . (int) $item['attempts'] . ')</span>' : '';
		return '<span class="laa-status laa-status-' . esc_attr( $item['status'] ) . '">' . esc_html( $label ) . '</span>' . $extra;
	}

	public function column_result( $item ) {
		$post_id = (int) $item['post_id'];
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return '—';
		}

		$links = array(
			sprintf( '<a href="%s">Редактировать</a>', esc_url( (string) get_edit_post_link( $post_id ) ) ),
			sprintf( '<a href="%s" target="_blank" rel="noopener">Смотреть</a>', esc_url( (string) get_permalink( $post_id ) ) ),
		);

		$report = json_decode( (string) get_post_meta( $post_id, '_laa_seo_report', true ), true );
		$note   = '';
		if ( is_array( $report ) && isset( $report['metrics'] ) ) {
			$note = '<div class="laa-angle">' . esc_html( LAA_SEO_Checker::summary( $report ) ) . '</div>';
		}

		return implode( ' · ', $links ) . $note;
	}

	public function column_updated( $item ) {
		$stamp = $item['updated_at'] ?: $item['created_at'];
		return $stamp ? esc_html( mysql2date( 'd.m.Y H:i', $stamp ) ) : '—';
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}

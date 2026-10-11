<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD over the topic queue table. The queue is the contract between the
 * machine and the human: the generator only ever writes `pending` rows, the
 * scheduler only ever reads `approved` ones. Nothing publishes a topic a
 * person hasn't passed through (unless the plan explicitly auto-approves).
 */
class LAA_Topics {

	const PENDING  = 'pending';
	const APPROVED = 'approved';
	const REJECTED = 'rejected';
	const WRITING  = 'writing';
	const DONE     = 'done';
	const FAILED   = 'failed';

	/** A `writing` row older than this is assumed dead and returned to the queue. */
	const STALE_WRITING_SECONDS = 30 * MINUTE_IN_SECONDS;

	public static function statuses() {
		return array(
			self::PENDING  => 'На согласовании',
			self::APPROVED => 'Одобрена',
			self::REJECTED => 'Отклонена',
			self::WRITING  => 'Пишется',
			self::DONE     => 'Опубликована',
			self::FAILED   => 'Ошибка',
		);
	}

	public static function status_label( $status ) {
		$all = self::statuses();
		return $all[ $status ] ?? $status;
	}

	private static function table() {
		return LAA_Install::table();
	}

	/**
	 * Normalised hash for dedupe — case, punctuation and whitespace shouldn't
	 * make "Что такое RAG?" and "что такое rag" two separate topics.
	 */
	public static function hash( $title ) {
		$norm = mb_strtolower( trim( (string) $title ) );
		$norm = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $norm );
		$norm = trim( preg_replace( '/\s+/u', ' ', $norm ) );
		return md5( $norm );
	}

	/**
	 * Insert one topic. Returns the new id, 0 when it's a duplicate within
	 * the same plan (the UNIQUE key does the work — we don't pre-check and
	 * race ourselves).
	 */
	public static function add( array $data ) {
		global $wpdb;

		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return 0;
		}

		$now = current_time( 'mysql' );

		$row = array(
			'plan_id'    => (int) ( $data['plan_id'] ?? 0 ),
			'title'      => $title,
			'title_hash' => self::hash( $title ),
			'keywords'   => (string) ( $data['keywords'] ?? '' ),
			'angle'      => (string) ( $data['angle'] ?? '' ),
			'status'     => (string) ( $data['status'] ?? self::PENDING ),
			'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : self::next_sort_order( (int) ( $data['plan_id'] ?? 0 ) ),
			'source'     => (string) ( $data['source'] ?? 'llm' ),
			'created_at' => $now,
			'updated_at' => $now,
		);

		// A duplicate title is the normal outcome of re-asking the model for
		// topics, and the UNIQUE key is how we detect it — suppress the error
		// so WP_DEBUG doesn't print "Duplicate entry" over the admin screen.
		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table(), $row );
		$wpdb->suppress_errors( $previous );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @return array{added:int,duplicates:int} */
	public static function add_many( array $rows ) {
		$added      = 0;
		$duplicates = 0;
		foreach ( $rows as $row ) {
			if ( self::add( $row ) ) {
				++$added;
			} else {
				++$duplicates;
			}
		}
		return array(
			'added'      => $added,
			'duplicates' => $duplicates,
		);
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	public static function update( $id, array $data ) {
		global $wpdb;

		$allowed = array( 'plan_id', 'title', 'keywords', 'angle', 'status', 'sort_order', 'post_id', 'attempts', 'last_error', 'published_at', 'source' );
		$row     = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = $data[ $key ];
			}
		}
		if ( isset( $row['title'] ) ) {
			$row['title']      = trim( (string) $row['title'] );
			$row['title_hash'] = self::hash( $row['title'] );
		}
		if ( empty( $row ) ) {
			return false;
		}
		$row['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	public static function set_status( $id, $status, $extra = array() ) {
		return self::update( $id, array_merge( array( 'status' => $status ), $extra ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * @param array $args plan_id, status (string|array), search, orderby, order, per_page, page
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$args = wp_parse_args(
			$args,
			array(
				'plan_id'  => null,
				'status'   => null,
				'search'   => '',
				'orderby'  => 'sort_order',
				'order'    => 'ASC',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		list( $where, $params ) = self::build_where( $args );

		$orderby_whitelist = array( 'sort_order', 'id', 'title', 'status', 'created_at', 'published_at' );
		$orderby           = in_array( $args['orderby'], $orderby_whitelist, true ) ? $args['orderby'] : 'sort_order';
		$order             = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// Secondary key on id keeps paging stable when many rows share sort_order.
		$sql      = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order}, id ASC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public static function count( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$args = wp_parse_args(
			$args,
			array(
				'plan_id' => null,
				'status'  => null,
				'search'  => '',
			)
		);

		list( $where, $params ) = self::build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$table} {$where}";
		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			return (int) $wpdb->get_var( $sql );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/** @return array{0:string,1:array} SQL WHERE clause and its bound params. */
	private static function build_where( array $args ) {
		global $wpdb;

		$clauses = array();
		$params  = array();

		if ( null !== $args['plan_id'] && '' !== $args['plan_id'] ) {
			$clauses[] = 'plan_id = %d';
			$params[]  = (int) $args['plan_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$statuses    = array_values( (array) $args['status'] );
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$clauses[]   = "status IN ({$placeholders})";
			$params      = array_merge( $params, $statuses );
		}
		if ( ! empty( $args['search'] ) ) {
			$clauses[] = '(title LIKE %s OR keywords LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}

		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
		return array( $where, $params );
	}

	/** Counts per status, for the admin screen's status filter links. */
	public static function counts_by_status( $plan_id = null ) {
		global $wpdb;
		$table = self::table();

		if ( null !== $plan_id && '' !== $plan_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE plan_id = %d GROUP BY status", (int) $plan_id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		}

		$out = array_fill_keys( array_keys( self::statuses() ), 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * The next topics the scheduler should write, oldest-first by queue order.
	 * Deliberately a separate method from query(): it's the hot path and it
	 * must never accidentally pick up a non-approved row.
	 */
	public static function next_approved( $plan_id, $limit = 1 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE plan_id = %d AND status = %s ORDER BY sort_order ASC, id ASC LIMIT %d",
				(int) $plan_id,
				self::APPROVED,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	public static function count_approved( $plan_id ) {
		return self::count(
			array(
				'plan_id' => (int) $plan_id,
				'status'  => self::APPROVED,
			)
		);
	}

	/**
	 * Claim a topic for writing. Conditional UPDATE, so two overlapping ticks
	 * can't both pick the same row: whoever flips approved -> writing first
	 * gets it, the other sees 0 affected rows and moves on.
	 */
	public static function claim( $id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = %s",
				self::WRITING,
				current_time( 'mysql' ),
				(int) $id,
				self::APPROVED
			)
		);

		return (int) $updated === 1;
	}

	/** Returns rows stuck in `writing` back to `approved`. */
	public static function release_stale() {
		global $wpdb;
		$table  = self::table();
		// updated_at is written with current_time('mysql'), i.e. site-local —
		// so the cutoff has to be site-local too, hence gmdate() over the
		// already-shifted current_time('timestamp').
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STALE_WRITING_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND updated_at < %s",
				self::APPROVED,
				current_time( 'mysql' ),
				self::WRITING,
				$cutoff
			)
		);

		if ( $n ) {
			LAA_Log::add( "Возвращено в очередь зависших тем: {$n}" );
		}
		return (int) $n;
	}

	public static function next_sort_order( $plan_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE plan_id = %d", (int) $plan_id ) );
		return $max + 10;
	}

	/** Used by the "to the top of the queue" bulk action. */
	public static function move_to_top( array $ids ) {
		global $wpdb;
		$table = self::table();
		$ids   = array_map( 'intval', $ids );
		if ( empty( $ids ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$min = (int) $wpdb->get_var( "SELECT MIN(sort_order) FROM {$table}" );
		$pos = $min - 10 * count( $ids );
		foreach ( $ids as $id ) {
			self::update( $id, array( 'sort_order' => $pos ) );
			$pos += 10;
		}
	}

	/** Titles already in the queue for this plan — fed to the generator so it doesn't repeat them. */
	public static function existing_titles( $plan_id, $limit = 300 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT title FROM {$table} WHERE plan_id = %d ORDER BY id DESC LIMIT %d", (int) $plan_id, (int) $limit )
		);
	}
}

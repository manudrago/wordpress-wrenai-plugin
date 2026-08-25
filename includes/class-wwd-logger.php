<?php
/**
 * Audit log of every question that reached the database.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads the query log.
 */
class WWD_Logger {

	/**
	 * Log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'wwd_query_log';
	}

	/**
	 * Create the log table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			question text NOT NULL,
			sql_text longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT '',
			error text NULL,
			rows_returned int(11) NOT NULL DEFAULT 0,
			duration_ms int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Record one query.
	 *
	 * @param array $entry {
	 *     @type string $question Question asked.
	 *     @type string $sql      Statement.
	 *     @type string $status   ok|rejected|failed.
	 *     @type string $error    Error message.
	 *     @type int    $rows     Row count.
	 *     @type int    $duration Milliseconds.
	 * }
	 * @return void
	 */
	public static function log( array $entry ) {
		global $wpdb;

		if ( ! WWD_Settings::get( 'log_queries', 1 ) ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'user_id'        => get_current_user_id(),
				'question'       => isset( $entry['question'] ) ? (string) $entry['question'] : '',
				'sql_text'       => isset( $entry['sql'] ) ? (string) $entry['sql'] : '',
				'status'         => isset( $entry['status'] ) ? substr( (string) $entry['status'], 0, 20 ) : '',
				'error'          => isset( $entry['error'] ) ? (string) $entry['error'] : '',
				'rows_returned'  => isset( $entry['rows'] ) ? (int) $entry['rows'] : 0,
				'duration_ms'    => isset( $entry['duration'] ) ? (int) $entry['duration'] : 0,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Most recent log entries.
	 *
	 * @param int $limit How many.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 500, (int) $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Whether the current user has asked too many questions in the last minute.
	 *
	 * @return bool
	 */
	public static function is_rate_limited() {
		$limit = (int) WWD_Settings::get( 'rate_limit', 15 );

		if ( $limit <= 0 ) {
			return false;
		}

		$who = get_current_user_id();

		if ( ! $who ) {
			// Logged-out visitors share a user id, so fall back to the address.
			$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$who     = 'ip' . substr( md5( $address ), 0, 12 );
		}

		$key   = 'wwd_rate_' . $who . '_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );

		return false;
	}
}

<?php
/**
 * Executes validated SELECT statements and shapes the result set.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs analytics queries against the WordPress database.
 */
class WWD_Query_Runner {

	/**
	 * Database handle used for analytics queries.
	 *
	 * @var wpdb|null
	 */
	protected static $db = null;

	/**
	 * Connection used for analytics.
	 *
	 * When WWD_DB_USER / WWD_DB_PASSWORD are defined in wp-config.php the
	 * plugin opens a second connection with those credentials, so a MySQL user
	 * with nothing but SELECT rights can be used. Otherwise it falls back to
	 * the regular WordPress connection, and the SQL guard is the only line of
	 * defence.
	 *
	 * @return wpdb
	 */
	public static function db() {
		global $wpdb;

		if ( null !== self::$db ) {
			return self::$db;
		}

		if ( defined( 'WWD_DB_USER' ) && defined( 'WWD_DB_PASSWORD' ) ) {
			$name = defined( 'WWD_DB_NAME' ) ? WWD_DB_NAME : DB_NAME;
			$host = defined( 'WWD_DB_HOST' ) ? WWD_DB_HOST : DB_HOST;

			$read_only = new wpdb( WWD_DB_USER, WWD_DB_PASSWORD, $name, $host );

			$read_only->set_prefix( $wpdb->prefix );
			$read_only->suppress_errors( true );
			$read_only->hide_errors();

			self::$db = $read_only;

			return self::$db;
		}

		self::$db = $wpdb;

		return self::$db;
	}

	/**
	 * Whether analytics run on a dedicated (ideally read-only) connection.
	 *
	 * @return bool
	 */
	public static function has_dedicated_connection() {
		return defined( 'WWD_DB_USER' ) && defined( 'WWD_DB_PASSWORD' );
	}

	/**
	 * Validate, execute and shape a statement.
	 *
	 * @param string $sql      SQL produced by Wren AI.
	 * @param bool   $use_cache Whether the result may come from the cache.
	 * @return array|WP_Error {
	 *     @type string $sql      The executed statement.
	 *     @type array  $columns  Column names.
	 *     @type array  $rows     Rows as positional arrays.
	 *     @type int    $duration Milliseconds spent in the database.
	 *     @type bool   $cached   Whether the result was served from cache.
	 *     @type bool   $truncated Whether the row limit clipped the result.
	 * }
	 */
	public static function run( $sql, $use_cache = true ) {
		$prepared = WWD_SQL_Guard::prepare( $sql );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$safe_sql  = $prepared['sql'];
		$cache_ttl = (int) WWD_Settings::get( 'cache_ttl', 300 );
		$cache_key = 'wwd_q_' . md5( $safe_sql );

		if ( $use_cache && $cache_ttl > 0 ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				$cached['cached'] = true;

				return $cached;
			}
		}

		$db = self::db();

		if ( ! empty( $db->error ) ) {
			return new WP_Error( 'wwd_db_connection', __( 'Could not open the analytics database connection.', 'wp-wren-dashboards' ) );
		}

		$timeout = (int) apply_filters( 'wwd_query_timeout_ms', 15000 );

		if ( $timeout > 0 && preg_match( '/^\s*select\b/i', $safe_sql ) ) {
			// Optimiser hint, honoured by MySQL 5.7+ and ignored elsewhere.
			$executed = preg_replace( '/^\s*select\b/i', 'SELECT /*+ MAX_EXECUTION_TIME(' . $timeout . ') */', $safe_sql, 1 );
		} else {
			$executed = $safe_sql;
		}

		$suppress = $db->suppress_errors( true );
		$start    = microtime( true );
		$rows     = $db->get_results( $executed, ARRAY_A ); // phpcs:ignore WordPress.DB
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		$db->suppress_errors( $suppress );

		if ( ! empty( $db->last_error ) ) {
			return new WP_Error(
				'wwd_query_failed',
				sprintf(
					/* translators: %s: database error message. */
					__( 'The database rejected the query: %s', 'wp-wren-dashboards' ),
					$db->last_error
				),
				array( 'sql' => $safe_sql )
			);
		}

		$rows = is_array( $rows ) ? $rows : array();

		$result = self::shape( $rows, $safe_sql, $duration );

		if ( $use_cache && $cache_ttl > 0 ) {
			set_transient( $cache_key, $result, $cache_ttl );
		}

		return $result;
	}

	/**
	 * Turn associative rows into the column/row shape Wren AI and the chart
	 * renderer both expect, masking anything that must not be displayed.
	 *
	 * @param array  $rows     Associative rows.
	 * @param string $sql      Executed statement.
	 * @param int    $duration Milliseconds.
	 * @return array
	 */
	protected static function shape( array $rows, $sql, $duration ) {
		$columns = array();

		if ( ! empty( $rows ) ) {
			$columns = array_keys( $rows[0] );
		}

		$blocked = WWD_Schema::blocked_columns();
		$max     = (int) WWD_Settings::get( 'max_rows', 1000 );
		$values  = array();

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( $columns as $column ) {
				$value = isset( $row[ $column ] ) ? $row[ $column ] : null;

				if ( in_array( strtolower( $column ), $blocked, true ) ) {
					$value = '***';
				}

				$line[] = self::cast( $value );
			}

			$values[] = $line;
		}

		return array(
			'sql'       => $sql,
			'columns'   => $columns,
			'rows'      => $values,
			'row_count' => count( $values ),
			'duration'  => $duration,
			'cached'    => false,
			'truncated' => count( $values ) >= $max,
		);
	}

	/**
	 * Keep numbers numeric so charts do not have to guess.
	 *
	 * @param mixed $value Raw column value.
	 * @return mixed
	 */
	protected static function cast( $value ) {
		if ( null === $value || '' === $value ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			$float = (float) $value;

			if ( (string) (int) $float === (string) $value ) {
				return (int) $value;
			}

			return $float;
		}

		return $value;
	}

	/**
	 * Clear every cached query result.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wwd_q_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wwd_q_' ) . '%'
			)
		);

		wp_cache_flush();
	}
}

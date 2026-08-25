<?php
/**
 * Validation and normalisation of AI generated SQL.
 *
 * Nothing reaches the database before it passes through this class: the model
 * is treated as an untrusted source of SQL, not as a trusted author.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only SQL gatekeeper.
 */
class WWD_SQL_Guard {

	/**
	 * Statements and functions that have no place in a read-only analytics query.
	 *
	 * @var array
	 */
	protected static $forbidden = array(
		'insert',
		'update',
		'delete',
		'drop',
		'alter',
		'create',
		'truncate',
		'rename',
		'grant',
		'revoke',
		'commit',
		'rollback',
		'savepoint',
		'lock',
		'unlock',
		'call',
		'handler',
		'load',
		'outfile',
		'dumpfile',
		'into',
		'prepare',
		'execute',
		'deallocate',
		'set',
		'do',
		'use',
		'describe',
		'explain',
		'show',
		'grants',
		'sleep',
		'benchmark',
		'load_file',
		'get_lock',
		'master_pos_wait',
	);

	/**
	 * Keywords that are only dangerous as statements: as scalar functions
	 * (REPLACE(subject, search, replacement)) they are perfectly ordinary.
	 *
	 * @var array
	 */
	protected static $forbidden_unless_function = array(
		'replace',
	);

	/**
	 * Schema names that are always off limits, as bare words.
	 *
	 * @var array
	 */
	protected static $forbidden_schemas = array(
		'information_schema',
		'performance_schema',
	);

	/**
	 * Schema names that are only off limits when used to qualify an object,
	 * so a column named `sys_id` or `mysql_version` stays queryable.
	 *
	 * @var array
	 */
	protected static $forbidden_qualifiers = array(
		'mysql',
		'sys',
	);

	/**
	 * Validate and rewrite a statement produced by Wren AI.
	 *
	 * @param string $sql Raw SQL.
	 * @return array|WP_Error Array with `sql` and `tables` on success.
	 */
	public static function prepare( $sql ) {
		$sql = self::normalize( (string) $sql );

		if ( '' === $sql ) {
			return new WP_Error( 'wwd_empty_sql', __( 'Wren AI did not return a query for this question.', 'wp-wren-dashboards' ) );
		}

		$stripped = self::strip_literals( $sql );

		if ( false !== strpos( rtrim( $stripped, "; \t\n\r" ), ';' ) ) {
			return new WP_Error( 'wwd_multi_statement', __( 'Only a single statement can be executed.', 'wp-wren-dashboards' ) );
		}

		if ( ! preg_match( '/^\s*(select|with)\b/i', $stripped ) ) {
			return new WP_Error( 'wwd_not_select', __( 'Only SELECT queries are allowed.', 'wp-wren-dashboards' ) );
		}

		$forbidden = self::find_forbidden( $stripped );

		if ( $forbidden ) {
			return new WP_Error(
				'wwd_forbidden_keyword',
				sprintf(
					/* translators: %s: SQL keyword. */
					__( 'The generated query was rejected: it uses "%s", which is not allowed on a read-only connection.', 'wp-wren-dashboards' ),
					strtoupper( $forbidden )
				)
			);
		}

		$blocked = self::find_blocked_column( $stripped );

		if ( $blocked ) {
			return new WP_Error(
				'wwd_blocked_column',
				sprintf(
					/* translators: %s: column name. */
					__( 'The generated query was rejected: the column "%s" is excluded from analytics.', 'wp-wren-dashboards' ),
					$blocked
				)
			);
		}

		$tables  = self::referenced_tables( $stripped );
		$allowed = (array) WWD_Settings::get( 'allowed_tables', array() );

		$unknown = array_values( array_diff( $tables, $allowed ) );

		if ( $unknown ) {
			return new WP_Error(
				'wwd_table_not_allowed',
				sprintf(
					/* translators: %s: comma separated table names. */
					__( 'The generated query touches tables that are not shared with Wren AI: %s', 'wp-wren-dashboards' ),
					implode( ', ', $unknown )
				)
			);
		}

		if ( empty( $tables ) ) {
			return new WP_Error( 'wwd_no_tables', __( 'The generated query does not read any of the shared tables.', 'wp-wren-dashboards' ) );
		}

		$sql = self::enforce_limit( $sql );

		return array(
			'sql'    => $sql,
			'tables' => $tables,
		);
	}

	/**
	 * Strip comments, trailing semicolons and Wren/DataFusion specific syntax,
	 * then translate the handful of functions MySQL spells differently.
	 *
	 * @param string $sql Raw SQL.
	 * @return string
	 */
	public static function normalize( $sql ) {
		$sql = trim( (string) $sql );

		// Remove SQL comments (outside of string literals is good enough here:
		// anything ambiguous is rejected later by the keyword scan).
		$sql = preg_replace( '#/\*.*?\*/#s', ' ', $sql );
		$sql = preg_replace( '/^\s*--.*$/m', ' ', $sql );
		$sql = preg_replace( '/\s+--\s.*$/m', ' ', $sql );
		$sql = preg_replace( '/^\s*#.*$/m', ' ', $sql );

		// Fully qualified identifiers: "wordpress"."public"."wp_posts" -> `wp_posts`.
		$sql = preg_replace( '/"[A-Za-z0-9_$]+"\."[A-Za-z0-9_$]+"\."([A-Za-z0-9_$]+)"/', '`$1`', $sql );
		$sql = preg_replace( '/"[A-Za-z0-9_$]+"\."([A-Za-z0-9_$]+)"/', '`$1`', $sql );

		// Remaining ANSI double-quoted identifiers become MySQL backticks.
		$sql = preg_replace( '/"([A-Za-z0-9_$ ]+)"/', '`$1`', $sql );

		$sql = self::translate_functions( $sql );

		$sql = rtrim( trim( $sql ), "; \t\n\r" );

		return $sql;
	}

	/**
	 * Translate common DataFusion/ANSI constructs into MySQL syntax.
	 *
	 * @param string $sql SQL statement.
	 * @return string
	 */
	protected static function translate_functions( $sql ) {
		$formats = array(
			'year'    => '%Y-01-01',
			'quarter' => '%Y-%m-01',
			'month'   => '%Y-%m-01',
			'week'    => '%Y-%m-%d',
			'day'     => '%Y-%m-%d',
			'hour'    => '%Y-%m-%d %H:00:00',
			'minute'  => '%Y-%m-%d %H:%i:00',
		);

		// Recursive pattern, so the second argument may itself contain calls.
		$sql = preg_replace_callback(
			"/\\bDATE_TRUNC\\s*\\(\\s*'([a-zA-Z]+)'\\s*,\\s*((?:[^()]++|\\((?2)\\))*)\\)/i",
			static function ( $matches ) use ( $formats ) {
				$unit   = strtolower( $matches[1] );
				$format = isset( $formats[ $unit ] ) ? $formats[ $unit ] : '%Y-%m-%d';

				return 'DATE_FORMAT(' . trim( $matches[2] ) . ", '" . $format . "')";
			},
			$sql
		);

		$replacements = array(
			'/\bCAST\s*\(\s*([^()]+?)\s+AS\s+(BIGINT|INTEGER|INT|SMALLINT|TINYINT)\s*\)/i' => 'CAST($1 AS SIGNED)',
			'/\bCAST\s*\(\s*([^()]+?)\s+AS\s+(DOUBLE|REAL|FLOAT)\s*\)/i'                   => 'CAST($1 AS DECIMAL(20,6))',
			'/\bCAST\s*\(\s*([^()]+?)\s+AS\s+(VARCHAR|TEXT|STRING)\s*\)/i'                 => 'CAST($1 AS CHAR)',
			'/\bILIKE\b/i'                                                                 => 'LIKE',
			'/\bCURRENT_DATE\b(?!\s*\()/i'                                                 => 'CURDATE()',
			'/\bNOW\s*\(\s*\)\s*::\s*date/i'                                               => 'CURDATE()',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$sql = preg_replace( $pattern, $replacement, $sql );
		}

		return $sql;
	}

	/**
	 * Replace string literals with placeholders so keyword scanning never
	 * trips over data such as WHERE post_title LIKE '%delete%'.
	 *
	 * @param string $sql SQL statement.
	 * @return string
	 */
	protected static function strip_literals( $sql ) {
		$sql = preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'/", "''", (string) $sql );
		$sql = preg_replace( '/"(?:[^"\\\\]|\\\\.|"")*"/', '""', $sql );

		return $sql;
	}

	/**
	 * First forbidden keyword found in the statement, if any.
	 *
	 * @param string $sql Literal-free SQL.
	 * @return string
	 */
	protected static function find_forbidden( $sql ) {
		foreach ( self::$forbidden as $keyword ) {
			if ( preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $sql ) ) {
				return $keyword;
			}
		}

		foreach ( self::$forbidden_unless_function as $keyword ) {
			if ( preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b\s*(?!\()/i', $sql ) ) {
				return $keyword;
			}
		}

		foreach ( self::$forbidden_schemas as $schema ) {
			if ( preg_match( '/\b' . preg_quote( $schema, '/' ) . '\b/i', $sql ) ) {
				return $schema;
			}
		}

		foreach ( self::$forbidden_qualifiers as $schema ) {
			if ( preg_match( '/\b' . preg_quote( $schema, '/' ) . '\s*\./i', $sql ) ) {
				return $schema;
			}
		}

		// Session and global variables.
		if ( false !== strpos( $sql, '@@' ) ) {
			return '@@';
		}

		return '';
	}

	/**
	 * First blocked column referenced by the statement, if any.
	 *
	 * @param string $sql Literal-free SQL.
	 * @return string
	 */
	protected static function find_blocked_column( $sql ) {
		foreach ( WWD_Schema::blocked_columns() as $column ) {
			if ( '' === $column ) {
				continue;
			}

			if ( preg_match( '/\b' . preg_quote( $column, '/' ) . '\b/i', $sql ) ) {
				return $column;
			}
		}

		return '';
	}

	/**
	 * Every real table the statement reads, with CTE names excluded.
	 *
	 * @param string $sql Literal-free SQL.
	 * @return array
	 */
	public static function referenced_tables( $sql ) {
		$ctes = array();

		if ( preg_match( '/^\s*with\b/i', $sql ) ) {
			preg_match_all( '/(?:\bwith\b|,)\s*`?([A-Za-z0-9_$]+)`?\s+as\s*\(/i', $sql, $cte_matches );

			if ( ! empty( $cte_matches[1] ) ) {
				$ctes = array_map( 'strtolower', $cte_matches[1] );
			}
		}

		preg_match_all( '/\b(?:from|join)\s+`?([A-Za-z0-9_$]+)`?/i', $sql, $matches );

		$tables = array();

		foreach ( $matches[1] as $table ) {
			if ( in_array( strtolower( $table ), $ctes, true ) ) {
				continue;
			}

			$tables[ $table ] = $table;
		}

		return array_values( $tables );
	}

	/**
	 * Make sure the statement can never return an unbounded result set.
	 *
	 * @param string $sql SQL statement.
	 * @return string
	 */
	public static function enforce_limit( $sql ) {
		$max = (int) WWD_Settings::get( 'max_rows', 1000 );
		$max = $max > 0 ? $max : 1000;

		if ( preg_match( '/\blimit\s+(\d+)\s*(?:,\s*(\d+)\s*)?$/i', $sql, $matches ) ) {
			$limit = isset( $matches[2] ) && '' !== $matches[2] ? (int) $matches[2] : (int) $matches[1];

			if ( $limit <= $max ) {
				return $sql;
			}

			return preg_replace( '/\blimit\s+\d+\s*(?:,\s*\d+\s*)?$/i', 'LIMIT ' . $max, $sql );
		}

		return $sql . ' LIMIT ' . $max;
	}
}

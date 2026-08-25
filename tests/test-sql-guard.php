<?php
/**
 * Standalone checks for the SQL guard — the security-critical part of the
 * plugin. No WordPress needed:
 *
 *     php tests/test-sql-guard.php
 *
 * @package WP_Wren_Dashboards
 */

define( 'ABSPATH', __DIR__ );

/**
 * Minimal WP_Error stand-in.
 */
class WP_Error {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param mixed  $data    Unused.
	 */
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * Error message.
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->message;
	}
}

/**
 * Whether a value is an error.
 *
 * @param mixed $thing Value.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Translation stub.
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

/**
 * Filter stub.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return $value;
}

/**
 * Settings stub.
 */
class WWD_Settings {

	/**
	 * Values the guard reads.
	 *
	 * @var array
	 */
	public static $values = array(
		'allowed_tables' => array( 'wp_posts', 'wp_postmeta', 'wp_comments', 'wp_terms', 'wp_term_relationships', 'wp_term_taxonomy' ),
		'max_rows'       => 1000,
	);

	/**
	 * Read a setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default;
	}
}

/**
 * Schema stub.
 */
class WWD_Schema {

	/**
	 * Columns that must never be queried.
	 *
	 * @return array
	 */
	public static function blocked_columns() {
		return array( 'user_pass', 'user_activation_key', 'user_email' );
	}
}

require __DIR__ . '/../includes/class-wwd-sql-guard.php';

$failures = 0;
$checks   = 0;

/**
 * Assert a statement is accepted, and optionally that the rewrite matches.
 *
 * @param string $label    Test label.
 * @param string $sql      Input SQL.
 * @param string $contains Substring the rewritten SQL must contain.
 * @return void
 */
function accepts( $label, $sql, $contains = '' ) {
	global $failures, $checks;

	$checks++;
	$result = WWD_SQL_Guard::prepare( $sql );

	if ( is_wp_error( $result ) ) {
		$failures++;
		echo "FAIL  {$label}\n      rejected: " . $result->get_error_message() . "\n";

		return;
	}

	if ( '' !== $contains && false === strpos( $result['sql'], $contains ) ) {
		$failures++;
		echo "FAIL  {$label}\n      expected to contain: {$contains}\n      got: {$result['sql']}\n";

		return;
	}

	echo "ok    {$label}\n";
}

/**
 * Assert a statement is rejected.
 *
 * @param string $label Test label.
 * @param string $sql   Input SQL.
 * @return void
 */
function rejects( $label, $sql ) {
	global $failures, $checks;

	$checks++;
	$result = WWD_SQL_Guard::prepare( $sql );

	if ( ! is_wp_error( $result ) ) {
		$failures++;
		echo "FAIL  {$label}\n      accepted: {$result['sql']}\n";

		return;
	}

	echo "ok    {$label} (" . $result->get_error_code() . ")\n";
}

echo "SQL guard\n---------\n";

accepts(
	'plain select gets a LIMIT',
	'SELECT post_status, COUNT(*) AS total FROM wp_posts GROUP BY post_status',
	'LIMIT 1000'
);

accepts(
	'wren style qualified identifiers are rewritten',
	'SELECT "wordpress"."public"."wp_posts"."post_title" FROM "wordpress"."public"."wp_posts" LIMIT 10',
	'`wp_posts`'
);

accepts(
	'joins across allowed tables',
	'SELECT p.post_title, COUNT(c.comment_ID) AS comments FROM wp_posts p JOIN wp_comments c ON c.comment_post_ID = p.ID GROUP BY p.post_title ORDER BY comments DESC LIMIT 10'
);

accepts(
	'common table expressions are not mistaken for tables',
	'WITH monthly AS (SELECT DATE_FORMAT(post_date, \'%Y-%m\') AS m, COUNT(*) c FROM wp_posts GROUP BY m) SELECT * FROM monthly ORDER BY m'
);

accepts(
	'literals that look like keywords are fine',
	"SELECT post_title FROM wp_posts WHERE post_title LIKE '%delete everything; drop table%'"
);

accepts(
	'DATE_TRUNC is translated to DATE_FORMAT',
	"SELECT DATE_TRUNC('month', post_date) AS m, COUNT(*) FROM wp_posts GROUP BY m",
	"DATE_FORMAT(post_date, '%Y-%m-01')"
);

accepts(
	'nested calls inside DATE_TRUNC survive translation',
	"SELECT DATE_TRUNC('year', COALESCE(post_modified, post_date)) AS y FROM wp_posts GROUP BY y",
	"DATE_FORMAT(COALESCE(post_modified, post_date), '%Y-01-01')"
);

accepts(
	'BIGINT casts become SIGNED',
	'SELECT CAST(meta_value AS BIGINT) AS v FROM wp_postmeta',
	'CAST(meta_value AS SIGNED)'
);

accepts(
	'REPLACE() as a function is allowed',
	"SELECT REPLACE(post_title, 'a', 'b') AS t FROM wp_posts"
);

accepts(
	'an oversized LIMIT is clamped',
	'SELECT ID FROM wp_posts LIMIT 999999',
	'LIMIT 1000'
);

accepts(
	'a small LIMIT is kept',
	'SELECT ID FROM wp_posts LIMIT 5',
	'LIMIT 5'
);

rejects( 'writes', 'DELETE FROM wp_posts' );
rejects( 'updates hidden after a select', 'SELECT 1; UPDATE wp_posts SET post_title = \'x\'' );
rejects( 'stacked drop', 'SELECT ID FROM wp_posts; DROP TABLE wp_posts' );
rejects( 'insert', "INSERT INTO wp_posts (post_title) VALUES ('x')" );
rejects( 'tables outside the allow list', 'SELECT user_login FROM wp_users' );
rejects( 'a union that reaches a private table', 'SELECT post_title FROM wp_posts UNION SELECT user_login FROM wp_users' );
rejects( 'blocked columns', 'SELECT user_pass FROM wp_posts' );
rejects( 'information_schema', 'SELECT table_name FROM information_schema.tables' );
rejects( 'the mysql schema', 'SELECT host FROM mysql.user' );
rejects( 'server variables', 'SELECT @@version FROM wp_posts' );
rejects( 'time based probing', 'SELECT SLEEP(5) FROM wp_posts' );
rejects( 'file writes', "SELECT ID FROM wp_posts INTO OUTFILE '/tmp/x'" );
rejects( 'file reads', "SELECT LOAD_FILE('/etc/passwd') FROM wp_posts" );
rejects( 'a comment hiding a second statement', "SELECT ID FROM wp_posts -- \n; DROP TABLE wp_posts" );
rejects( 'statements that read no shared table', 'SELECT 1' );
rejects( 'empty input', '   ' );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

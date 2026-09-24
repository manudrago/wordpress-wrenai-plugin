<?php
/**
 * Just enough WordPress to exercise the plugin's pure logic from the CLI.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

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
	 * Error data.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param mixed  $data    Data.
	 */
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
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
	 * Error data.
	 *
	 * @return mixed
	 */
	public function get_error_data() {
		return $this->data;
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
 * @param string $hook  Hook.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	global $wwd_filters;

	return isset( $wwd_filters[ $hook ] ) ? $wwd_filters[ $hook ] : $value;
}

$wwd_filters = array();

/**
 * Pin a filtered value for the rest of a test run.
 *
 * @param string $hook  Filter name.
 * @param mixed  $value Value apply_filters() will return.
 * @return void
 */
function add_test_filter( $hook, $value ) {
	global $wwd_filters;

	$wwd_filters[ $hook ] = $value;
}

/**
 * JSON encoder stub.
 *
 * @param mixed $data  Data.
 * @param int   $flags Flags.
 * @return string
 */
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Trailing slash remover.
 *
 * @param string $value Value.
 * @return string
 */
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

/**
 * Site locale.
 *
 * @return string
 */
function get_locale() {
	return 'it_IT';
}

/**
 * Site timezone.
 *
 * @return string
 */
function wp_timezone_string() {
	return 'Europe/Rome';
}

/**
 * Text sanitiser.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Key sanitiser.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

$wwd_options = array();

/**
 * Option reader.
 *
 * @param string $name    Option name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	global $wwd_options;

	return array_key_exists( $name, $wwd_options ) ? $wwd_options[ $name ] : $default;
}

/**
 * Option writer.
 *
 * @param string $name     Option name.
 * @param mixed  $value    Value.
 * @param string $autoload Ignored.
 * @return bool
 */
function update_option( $name, $value, $autoload = null ) {
	global $wwd_options;

	$wwd_options[ $name ] = $value;

	return true;
}

/**
 * Option remover.
 *
 * @param string $name Option name.
 * @return bool
 */
function delete_option( $name ) {
	global $wwd_options;

	unset( $wwd_options[ $name ] );

	return true;
}

$wwd_cache = array();

/**
 * Object cache read.
 *
 * @param string $key   Key.
 * @param string $group Group.
 * @return mixed
 */
function wp_cache_get( $key, $group = '' ) {
	global $wwd_cache;

	return isset( $wwd_cache[ $group . ':' . $key ] ) ? $wwd_cache[ $group . ':' . $key ] : false;
}

/**
 * Object cache write.
 *
 * @param string $key    Key.
 * @param mixed  $value  Value.
 * @param string $group  Group.
 * @param int    $expire Ignored.
 * @return bool
 */
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
	global $wwd_cache;

	$wwd_cache[ $group . ':' . $key ] = $value;

	return true;
}

/**
 * Records the last HTTP request the client made.
 */
class WWD_Test_HTTP {

	/**
	 * Last request: url, method, headers, body.
	 *
	 * @var array
	 */
	public static $last = array();

	/**
	 * Canned response body.
	 *
	 * @var array
	 */
	public static $response = array( 'query_id' => 'test-query' );

	/**
	 * Status code to answer with.
	 *
	 * @var int
	 */
	public static $status = 200;

	/**
	 * Raw body, when a test needs something that is not JSON.
	 *
	 * @var string|null
	 */
	public static $raw = null;

	/**
	 * Back to the defaults.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$last     = array();
		self::$response = array( 'query_id' => 'test-query' );
		self::$status   = 200;
		self::$raw      = null;
	}
}

/**
 * HTTP stub that records instead of sending.
 *
 * @param string $url  URL.
 * @param array  $args Request arguments.
 * @return array
 */
function wp_remote_request( $url, $args = array() ) {
	WWD_Test_HTTP::$last = array(
		'url'     => $url,
		'method'  => isset( $args['method'] ) ? $args['method'] : 'GET',
		'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
		'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
	);

	return array(
		'response' => array( 'code' => WWD_Test_HTTP::$status ),
		'body'     => null === WWD_Test_HTTP::$raw ? wp_json_encode( WWD_Test_HTTP::$response ) : WWD_Test_HTTP::$raw,
	);
}

/**
 * HTTP POST stub.
 *
 * @param string $url  URL.
 * @param array  $args Request arguments.
 * @return array
 */
function wp_remote_post( $url, $args = array() ) {
	$args['method'] = 'POST';

	return wp_remote_request( $url, $args );
}

/**
 * Site time.
 *
 * @param string $type Ignored, always a timestamp here.
 * @param int    $gmt  Ignored.
 * @return int
 */
function current_time( $type, $gmt = 0 ) {
	return time();
}

/**
 * URL parser.
 *
 * @param string $url       URL.
 * @param int    $component Component constant, or -1 for all.
 * @return mixed
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * HTTP GET stub.
 *
 * @param string $url  URL.
 * @param array  $args Request arguments.
 * @return array
 */
function wp_remote_get( $url, $args = array() ) {
	$args['method'] = 'GET';

	return wp_remote_request( $url, $args );
}

/**
 * Response status.
 *
 * @param array $response Response.
 * @return int
 */
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

/**
 * Response body.
 *
 * @param array $response Response.
 * @return string
 */
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

/**
 * Tag stripper.
 *
 * @param string $value Value.
 * @return string
 */
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * A WordPress-shaped database, without a database.
 */
class WWD_Test_WPDB {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Database name.
	 *
	 * @var string
	 */
	public $dbname = 'wp_test';

	/**
	 * Columns per table, in "SHOW FULL COLUMNS" shape.
	 *
	 * @var array
	 */
	public $schema = array(
		'wp_posts'    => array(
			array( 'Field' => 'ID', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Comment' => '' ),
			array( 'Field' => 'post_author', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'MUL', 'Comment' => '' ),
			array( 'Field' => 'post_title', 'Type' => 'text', 'Null' => 'NO', 'Key' => '', 'Comment' => '' ),
			array( 'Field' => 'post_date', 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Comment' => '' ),
			array( 'Field' => 'post_status', 'Type' => "varchar(20)", 'Null' => 'NO', 'Key' => '', 'Comment' => '' ),
			array( 'Field' => 'post_password', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => '', 'Comment' => '' ),
		),
		'wp_postmeta' => array(
			array( 'Field' => 'meta_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Comment' => '' ),
			array( 'Field' => 'post_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'MUL', 'Comment' => '' ),
			array( 'Field' => 'meta_key', 'Type' => 'varchar(255)', 'Null' => 'YES', 'Key' => 'MUL', 'Comment' => '' ),
			array( 'Field' => 'meta_value', 'Type' => 'longtext', 'Null' => 'YES', 'Key' => '', 'Comment' => '' ),
		),
		'wp_comments' => array(
			array( 'Field' => 'comment_ID', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Comment' => '' ),
			array( 'Field' => 'comment_post_ID', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'MUL', 'Comment' => '' ),
			array( 'Field' => 'comment_approved', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Key' => '', 'Comment' => '' ),
			array( 'Field' => 'comment_date', 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Comment' => '' ),
		),
		'wp_users'    => array(
			array( 'Field' => 'ID', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Comment' => '' ),
			array( 'Field' => 'user_login', 'Type' => 'varchar(60)', 'Null' => 'NO', 'Key' => 'MUL', 'Comment' => '' ),
			array( 'Field' => 'user_pass', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => '', 'Comment' => '' ),
		),
	);

	/**
	 * Table list.
	 *
	 * @param string $query Query.
	 * @return array
	 */
	public function get_col( $query ) {
		return array_keys( $this->schema );
	}

	/**
	 * Column metadata and foreign keys.
	 *
	 * @param string $query  Query.
	 * @param string $output Ignored.
	 * @return array
	 */
	public function get_results( $query, $output = null ) {
		if ( false !== stripos( $query, 'information_schema' ) ) {
			return array();
		}

		if ( preg_match( '/SHOW FULL COLUMNS FROM `([^`]+)`/i', $query, $matches ) ) {
			return isset( $this->schema[ $matches[1] ] ) ? $this->schema[ $matches[1] ] : array();
		}

		return array();
	}

	/**
	 * Query preparer.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
	}
}

$GLOBALS['wpdb'] = new WWD_Test_WPDB();

$wwd_transients = array();

/**
 * Transient reader.
 *
 * @param string $key Key.
 * @return mixed
 */
function get_transient( $key ) {
	global $wwd_transients;

	return array_key_exists( $key, $wwd_transients ) ? $wwd_transients[ $key ] : false;
}

/**
 * Transient writer.
 *
 * @param string $key     Key.
 * @param mixed  $value   Value.
 * @param int    $expires Ignored.
 * @return bool
 */
function set_transient( $key, $value, $expires = 0 ) {
	global $wwd_transients;

	$wwd_transients[ $key ] = $value;

	return true;
}

/**
 * Transient remover.
 *
 * @param string $key Key.
 * @return bool
 */
function delete_transient( $key ) {
	global $wwd_transients;

	unset( $wwd_transients[ $key ] );

	return true;
}

/**
 * Current user.
 *
 * @return int
 */
function get_current_user_id() {
	return 1;
}

/**
 * Capability check.
 *
 * @param string $capability Capability.
 * @return bool
 */
function current_user_can( $capability ) {
	return true;
}

/**
 * Identifier.
 *
 * @return string
 */
function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', ...array_map( static function () {
		return wp_rand_int();
	}, range( 1, 8 ) ) );
}

/**
 * Small random integer for the stub uuid.
 *
 * @return int
 */
function wp_rand_int() {
	return random_int( 0, 0xffff );
}

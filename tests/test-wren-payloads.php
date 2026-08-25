<?php
/**
 * Contract checks for what the plugin sends to Wren AI and for the MDL it
 * builds from a WordPress-shaped database. Runs without WordPress:
 *
 *     php tests/test-wren-payloads.php
 *
 * The expected shapes come from wren-ai-service:
 *   POST /v1/semantics-preparations  {mdl: string, mdl_hash: string}
 *   POST /v1/asks                    {query, mdl_hash, histories, ...}
 *   POST /v1/charts                  {query, sql, data: {columns, data}, ...}
 *
 * @package WP_Wren_Dashboards
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';

require __DIR__ . '/../includes/class-wwd-settings.php';
require __DIR__ . '/../includes/class-wwd-schema.php';
require __DIR__ . '/../includes/class-wwd-wren-client.php';

WWD_Settings::update(
	array(
		'endpoint'        => 'http://wren.test:5555',
		'api_prefix'      => '/v1',
		'api_key'         => '',
		'allowed_tables'  => array( 'wp_posts', 'wp_postmeta', 'wp_comments' ),
		'blocked_columns' => array( 'post_password', 'user_pass' ),
	)
);

$failures = 0;
$checks   = 0;

/**
 * Assert a condition.
 *
 * @param string $label   Test label.
 * @param bool   $passed  Result.
 * @param string $details Extra output on failure.
 * @return void
 */
function check( $label, $passed, $details = '' ) {
	global $failures, $checks;

	$checks++;

	if ( $passed ) {
		echo "ok    {$label}\n";

		return;
	}

	$failures++;
	echo "FAIL  {$label}\n";

	if ( '' !== $details ) {
		echo "      {$details}\n";
	}
}

echo "MDL\n---\n";

$mdl = WWD_Schema::build_mdl();

check( 'has the MDL top level keys', array() === array_diff( array( 'catalog', 'schema', 'models', 'relationships', 'views', 'dataSource' ), array_keys( $mdl ) ) );
check( 'lists only the shared tables', 3 === count( $mdl['models'] ), 'models: ' . count( $mdl['models'] ) );

$posts = null;

foreach ( $mdl['models'] as $model ) {
	if ( 'wp_posts' === $model['name'] ) {
		$posts = $model;
	}
}

check( 'includes wp_posts', null !== $posts );
check( 'points at the real table', 'wp_posts' === $posts['tableReference']['table'] );
check( 'keeps the primary key', 'ID' === $posts['primaryKey'] );
check( 'maps bigint to BIGINT', 'BIGINT' === $posts['columns'][0]['type'], wp_json_encode( $posts['columns'][0] ) );
check( 'maps datetime to TIMESTAMP', 'TIMESTAMP' === $posts['columns'][3]['type'], wp_json_encode( $posts['columns'][3] ) );
check( 'describes the table for the model', ! empty( $posts['properties']->description ) );

$column_names = array_map(
	static function ( $column ) {
		return $column['name'];
	},
	$posts['columns']
);

check( 'strips blocked columns from the model', ! in_array( 'post_password', $column_names, true ), implode( ', ', $column_names ) );

$conditions = array_map(
	static function ( $relationship ) {
		return $relationship['condition'];
	},
	$mdl['relationships']
);

check(
	'declares the postmeta join WordPress never does',
	in_array( 'wp_postmeta.post_id = wp_posts.ID', $conditions, true ),
	implode( ' | ', $conditions )
);

check(
	'declares the comments join',
	in_array( 'wp_comments.comment_post_ID = wp_posts.ID', $conditions, true )
);

check(
	'drops joins to tables that are not shared',
	! in_array( 'wp_posts.post_author = wp_users.ID', $conditions, true )
);

$hash = WWD_Schema::mdl_hash( $mdl );

check( 'hashes the model deterministically', $hash === WWD_Schema::mdl_hash( $mdl ) && 32 === strlen( $hash ) );

echo "\nRequest payloads\n----------------\n";

WWD_Settings::update( array( 'mdl_hash' => $hash ) );

$client = new WWD_Wren_Client();

$client->deploy_semantics( $mdl, $hash );
$sent = WWD_Test_HTTP::$last;

check( 'deploys to /v1/semantics-preparations', 'http://wren.test:5555/v1/semantics-preparations' === $sent['url'], $sent['url'] );
check( 'sends the MDL as a JSON string', is_string( $sent['body']['mdl'] ) && null !== json_decode( $sent['body']['mdl'], true ) );
check( 'sends the hash as mdl_hash', $hash === $sent['body']['mdl_hash'] );
check( 'identifies itself as an API caller', 'api' === $sent['body']['request_from'] );
check( 'passes the language configuration', 'Italian' === $sent['body']['configurations']['language'], wp_json_encode( $sent['body']['configurations'] ) );

$client->ask( 'Quanti post al mese?', array( array( 'question' => 'e prima?', 'sql' => 'SELECT 1' ) ) );
$sent = WWD_Test_HTTP::$last;

check( 'asks on /v1/asks', 'http://wren.test:5555/v1/asks' === $sent['url'] );
check( 'sends the question as query', 'Quanti post al mese?' === $sent['body']['query'] );
check( 'binds the question to the deployed model', $hash === $sent['body']['mdl_hash'] );
check( 'passes the follow-up history', 1 === count( $sent['body']['histories'] ) );
check( 'passes the business context', false !== strpos( $sent['body']['custom_instruction'], 'MySQL' ) );

$client->chart( 'Quanti post al mese?', 'SELECT 1', array( 'm', 'total' ), array( array( '2026-01', 4 ), array( '2026-02', 9 ) ) );
$sent = WWD_Test_HTTP::$last;

check( 'charts on /v1/charts', 'http://wren.test:5555/v1/charts' === $sent['url'] );
check( 'sends columns and rows in the shape the chart pipeline reads', array( 'm', 'total' ) === $sent['body']['data']['columns'] && 2 === count( $sent['body']['data']['data'] ) );
check( 'asks Wren to leave the data out of the schema', true === $sent['body']['remove_data_from_chart_schema'] );

$client->ask_result( 'abc-123' );
check( 'reads results from /v1/asks/{id}/result', 'http://wren.test:5555/v1/asks/abc-123/result' === WWD_Test_HTTP::$last['url'], WWD_Test_HTTP::$last['url'] );

$client->chart_result( 'abc-123' );
check( 'reads charts from /v1/charts/{id}', 'http://wren.test:5555/v1/charts/abc-123' === WWD_Test_HTTP::$last['url'] );

$client->semantics_status( $hash );
check( 'reads indexing status from /v1/semantics-preparations/{hash}/status', 'http://wren.test:5555/v1/semantics-preparations/' . $hash . '/status' === WWD_Test_HTTP::$last['url'] );

WWD_Settings::update( array( 'api_key' => 'secret-token' ) );
$authorised = new WWD_Wren_Client();
$authorised->ask_result( 'abc' );

check( 'sends the API key as a bearer token', 'Bearer secret-token' === WWD_Test_HTTP::$last['headers']['Authorization'] );

WWD_Settings::update( array( 'api_prefix' => '/api/v1' ) );
$cloud = new WWD_Wren_Client();
$cloud->ask_result( 'abc' );

check( 'honours a different API prefix', 'http://wren.test:5555/api/v1/asks/abc/result' === WWD_Test_HTTP::$last['url'] );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

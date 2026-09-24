<?php
/**
 * A busy provider must cost a moment, not the question. Runs without
 * WordPress:
 *
 *     php tests/test-retries.php
 *
 * This one really waits out a backoff, so it takes a few seconds.
 *
 * @package WP_Wren_Dashboards
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';

require __DIR__ . '/../includes/class-wwd-settings.php';
require __DIR__ . '/../includes/class-wwd-schema.php';
require __DIR__ . '/../includes/class-wwd-model-client.php';
require __DIR__ . '/../includes/class-wwd-engine.php';
require __DIR__ . '/../includes/class-wwd-engine-direct.php';
require __DIR__ . '/../includes/class-wwd-ask-session.php';

WWD_Settings::update(
	array(
		'engine'          => 'direct',
		'model_provider'  => 'google',
		'model_api_key'   => 'test-key',
		'allowed_tables'  => array( 'wp_posts', 'wp_postmeta' ),
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

/**
 * Make the next provider call fail with this status.
 *
 * @param int    $status  HTTP status.
 * @param string $message Provider message.
 * @return void
 */
function provider_fails( $status, $message ) {
	WWD_Test_HTTP::reset();

	WWD_Test_HTTP::$status   = $status;
	WWD_Test_HTTP::$response = array( 'error' => array( 'message' => $message ) );
}

/**
 * Make the next provider call answer with this text.
 *
 * @param string $text Model output.
 * @return void
 */
function provider_answers( $text ) {
	WWD_Test_HTTP::reset();

	WWD_Test_HTTP::$response = array(
		'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => $text ) ) ) ) ),
	);
}

// ---------------------------------------------------------------------------
// Which failures deserve another chance
// ---------------------------------------------------------------------------

$client = new WWD_Model_Client();

$retryable = array(
	503 => 'This model is currently experiencing high demand.',
	500 => 'Internal error',
	502 => 'Bad gateway',
	429 => 'Quota exceeded',
);

foreach ( $retryable as $status => $message ) {
	provider_fails( $status, $message );

	check(
		sprintf( 'HTTP %d is worth another try', $status ),
		WWD_Model_Client::is_retryable( $client->complete( 'x', 'y' ) )
	);
}

$final = array(
	401 => 'API key not valid',
	403 => 'Forbidden',
	404 => 'models/gone is not found',
	400 => 'Bad request',
);

foreach ( $final as $status => $message ) {
	provider_fails( $status, $message );

	check(
		sprintf( 'HTTP %d is not', $status ),
		! WWD_Model_Client::is_retryable( $client->complete( 'x', 'y' ) )
	);
}

provider_answers( 'not json' );

check( 'a malformed answer is not retried either', ! WWD_Model_Client::is_retryable( $client->complete( 'x', 'y' ) ) );

// ---------------------------------------------------------------------------
// The question survives a busy provider
// ---------------------------------------------------------------------------

provider_fails( 503, 'This model is currently experiencing high demand.' );

$session = WWD_Ask_Session::start( 'how many posts?' );

check( 'a 503 does not throw the question away', ! is_wp_error( $session ) );

if ( is_wp_error( $session ) ) {
	echo "\n{$checks} checks, {$failures} failures\n";

	exit( 1 );
}

$state = $session->to_array();

check( 'the card waits instead of failing', 'generating_sql' === $state['status'] );
check( 'and says why', false !== strpos( $state['stage'], 'busy' ) );

// A poll before the backoff has elapsed must not hammer the provider.
WWD_Test_HTTP::reset();
$session->advance();

check( 'polling during the backoff does not call the provider', empty( WWD_Test_HTTP::$last ) );

// Once it has, the same question is asked again - and this time it works.
provider_answers( '{"sql":"SELECT COUNT(*) AS posts FROM `wp_posts` LIMIT 100","explanation":"ok"}' );

sleep( WWD_Ask_Session::BACKOFF[0] + 1 );

$state = $session->advance();

check( 'after the wait the provider is called again', ! empty( WWD_Test_HTTP::$last ) );
check( 'the recovered statement is kept', false !== strpos( $state['sql'], 'SELECT COUNT(*)' ), $state['sql'] );
check( 'and the question moves on to running it', 'running_query' === $state['status'], $state['status'] );

// ---------------------------------------------------------------------------
// Patience is finite
// ---------------------------------------------------------------------------

add_test_filter( 'wwd_max_provider_retries', 1 );

provider_fails( 503, 'This model is currently experiencing high demand.' );

$session = WWD_Ask_Session::start( 'how many posts?' );
$state   = $session->to_array();

check( 'the first 503 still buys a retry', 'generating_sql' === $state['status'] );

sleep( WWD_Ask_Session::BACKOFF[0] + 1 );

$state = $session->advance();

check( 'but the budget runs out', 'failed' === $state['status'], $state['status'] );
check( 'and the provider message is what the person reads', false !== strpos( $state['error'], 'high demand' ), $state['error'] );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

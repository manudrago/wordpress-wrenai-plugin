<?php
/**
 * The licence behind the paid editions: what unlocks them, what does not, and
 * what happens when the shop cannot be reached. Runs without WordPress:
 *
 *     php tests/test-license.php
 *
 * @package DataChat_AI
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-wwd-license.php';

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
 * Answer the next licence check with this body.
 *
 * @param array $body Decoded JSON the shop would return.
 * @return void
 */
function shop_says( array $body ) {
	WWD_Test_HTTP::reset();

	WWD_Test_HTTP::$response = $body;
}

// ---------------------------------------------------------------------------
// The free build has no licence at all
// ---------------------------------------------------------------------------

check( 'the free build is not a paid edition', ! WWD_License::is_paid_edition() );
check( 'and unlocks nothing', ! WWD_License::is_valid() );

define( 'WWD_EDITION', 'pro' );
define( 'WWD_LICENSE_ENDPOINT', 'https://shop.example/licence' );

check( 'a paid build knows which edition it is', 'pro' === WWD_License::edition() && WWD_License::is_paid_edition() );
check( 'but not licensed until a key is activated', ! WWD_License::is_valid() );

// ---------------------------------------------------------------------------
// Activating
// ---------------------------------------------------------------------------

check( 'an empty key is refused without asking the shop', is_wp_error( WWD_License::activate( '  ' ) ) );

shop_says( array( 'status' => 'valid', 'expires' => gmdate( 'Y-m-d', time() + 31536000 ) ) );

$result = WWD_License::activate( 'KEY-1234' );

check( 'a good key activates', ! is_wp_error( $result ) );
check( 'and unlocks the paid features', WWD_License::is_valid() );

$sent = WWD_Test_HTTP::$last;

check( 'the shop is asked at the endpoint baked into the build', 'https://shop.example/licence' === $sent['url'], $sent['url'] );
check( 'the key is sent', 'KEY-1234' === $sent['body']['license_key'] );
check( 'with the edition it belongs to', 'datachat-ai-pro' === $sent['body']['product'] );
check( 'and the domain it is used on, without the www', 'shop.example' === $sent['body']['domain'], $sent['body']['domain'] );

// Shops word the same answer differently.
foreach ( array( 'active', 'success', 'ok', 'VALID' ) as $wording ) {
	shop_says( array( 'license' => $wording ) );

	check(
		sprintf( '"%s" is read as valid', $wording ),
		! is_wp_error( WWD_License::activate( 'KEY-1234' ) )
	);
}

shop_says( array( 'success' => true ) );

check( 'so is a plain boolean', ! is_wp_error( WWD_License::activate( 'KEY-1234' ) ) );

// ---------------------------------------------------------------------------
// Refusals
// ---------------------------------------------------------------------------

shop_says( array( 'status' => 'invalid', 'message' => 'Key not found' ) );

$refused = WWD_License::activate( 'WRONG' );

check( 'a refused key is an error', is_wp_error( $refused ) );
check( 'and the shop gets to explain why', false !== strpos( $refused->get_error_message(), 'Key not found' ) );
check( 'and nothing is unlocked', ! WWD_License::is_valid() );

shop_says( array( 'status' => 'valid', 'expires' => gmdate( 'Y-m-d', time() - 86400 ) ) );
WWD_License::activate( 'KEY-1234' );

check( 'a key that expired yesterday unlocks nothing', ! WWD_License::is_valid() );

// ---------------------------------------------------------------------------
// When the shop is unreachable
// ---------------------------------------------------------------------------

shop_says( array( 'status' => 'valid' ) );
WWD_License::activate( 'KEY-1234' );

check( 'a licence with no expiry date is fine', WWD_License::is_valid() );

// A week later, the shop is down.
$state               = WWD_License::state();
$state['checked_at'] = time() - ( 8 * DAY_IN_SECONDS );
update_option( WWD_License::OPTION, $state, false );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status = 500;

WWD_License::maybe_recheck();

check( 'a shop that is down does not turn the plugin off', WWD_License::is_valid() );
check( 'and the failed check is remembered', WWD_License::state()['checked_at'] > time() - 10 );

// Long enough with no confirmation, and it does lapse.
$state                 = WWD_License::state();
$state['confirmed_at'] = time() - ( 40 * DAY_IN_SECONDS );
update_option( WWD_License::OPTION, $state, false );

check( 'but silence for long enough does', ! WWD_License::is_valid() );

// A shop that answers "no" is not silence: it lapses at once.
shop_says( array( 'status' => 'valid' ) );
WWD_License::activate( 'KEY-1234' );

$state               = WWD_License::state();
$state['checked_at'] = time() - ( 2 * DAY_IN_SECONDS );
update_option( WWD_License::OPTION, $state, false );

shop_says( array( 'status' => 'invalid', 'message' => 'Refunded' ) );
WWD_License::maybe_recheck();

check( 'a refusal at re-check locks immediately', ! WWD_License::is_valid() );
check( 'with the reason kept', 'Refunded' === WWD_License::state()['message'] );

// ---------------------------------------------------------------------------
// Removing
// ---------------------------------------------------------------------------

shop_says( array( 'status' => 'valid' ) );
WWD_License::activate( 'KEY-1234' );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$response = array( 'released' => true );

$removed = WWD_License::deactivate();

check( 'removing the key locks the paid features', ! WWD_License::is_valid() );
check( 'and forgets it', '' === WWD_License::state()['key'] );
check( 'the shop is told, so the licence can move', 'https://shop.example/licence/release' === WWD_Test_HTTP::$last['url'], WWD_Test_HTTP::$last['url'] );
check( 'with the key and the site to free', 'KEY-1234' === WWD_Test_HTTP::$last['body']['license_key'] && 'shop.example' === WWD_Test_HTTP::$last['body']['domain'] );
check( 'and it says the seat came back', true === $removed );

// A shop that cannot be reached must not trap the licence here.
shop_says( array( 'status' => 'valid' ) );
WWD_License::activate( 'KEY-1234' );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status = 500;

$offline = WWD_License::deactivate();

check( 'a shop that is down does not stop the key being removed', '' === WWD_License::state()['key'] );
check( 'but it says the seat was not confirmed free', false === $offline );

// ---------------------------------------------------------------------------
// One licence, one site
// ---------------------------------------------------------------------------

shop_says(
	array(
		'status' => 'valid',
		'seats'  => array( 'limit' => 1, 'used' => 1, 'sites' => array( 'shop.example' ) ),
	)
);

WWD_License::activate( 'KEY-1234' );

check( 'the seat count is kept', 1 === WWD_License::state()['seats']['limit'] );
check( 'and the settings screen says where the licence is', false !== strpos( WWD_License::summary(), 'In use on 1 of 1 sites' ), WWD_License::summary() );

shop_says(
	array(
		'status'  => 'invalid',
		'message' => 'This licence is for 1 site and is already in use on other-site.example.',
		'seats'   => array( 'limit' => 1, 'used' => 1, 'sites' => array( 'other-site.example' ) ),
	)
);

$taken = WWD_License::activate( 'KEY-1234' );

check( 'a key already used elsewhere is refused', is_wp_error( $taken ) );
check( 'and the other site is named', false !== strpos( $taken->get_error_message(), 'other-site.example' ) );
check( 'and remembered for the screen', array( 'other-site.example' ) === WWD_License::state()['seats']['sites'] );
check( 'and nothing is unlocked', ! WWD_License::is_valid() );

// ---------------------------------------------------------------------------
// Answers worded in other ways
// ---------------------------------------------------------------------------

shop_says( array( 'data' => array( 'status' => 'active' ) ) );

check( 'an answer wrapped in a data object is read', ! is_wp_error( WWD_License::activate( 'KEY-1234' ) ) );

shop_says( array( 'token' => 'eyJhbGciOi...' ) );

check( 'an endpoint that hands back a token has said yes', ! is_wp_error( WWD_License::activate( 'KEY-1234' ) ) );

shop_says( array( 'hello' => 'world' ) );

$unreadable = WWD_License::activate( 'KEY-1234' );

check( 'an answer that means nothing is not taken as a yes', is_wp_error( $unreadable ) );
check( 'and it says so as silence, not as a refusal', 'wwd_license_unreadable' === $unreadable->get_error_code(), $unreadable->get_error_code() );

// ---------------------------------------------------------------------------
// The raw reply is kept, so an unreadable shop can be looked at
// ---------------------------------------------------------------------------

check( 'the body of that reply was kept', false !== strpos( WWD_License::state()['last_body'], 'world' ), WWD_License::state()['last_body'] );
check( 'with the status beside it', 200 === WWD_License::state()['last_code'] );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status = 502;
WWD_Test_HTTP::$raw    = '<html><body>Bad gateway</body></html>';

WWD_License::activate( 'KEY-1234' );

$kept = WWD_License::state();

check( 'an error page is kept too', false !== strpos( $kept['last_body'], 'Bad gateway' ), $kept['last_body'] );
check( 'without its markup', false === strpos( $kept['last_body'], '<body>' ), $kept['last_body'] );
check( 'and with the status that came with it', 502 === $kept['last_code'] );

// ---------------------------------------------------------------------------
// Adapting to a shop that speaks differently
// ---------------------------------------------------------------------------

add_test_filter( 'wwd_license_response', array( 'status' => 'valid', 'message' => '', 'expires' => '' ) );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$raw = 'LICENCE OK';

check( 'a filter can read an answer that is not JSON', ! is_wp_error( WWD_License::activate( 'KEY-1234' ) ) );
check( 'and that unlocks the features too', WWD_License::is_valid() );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

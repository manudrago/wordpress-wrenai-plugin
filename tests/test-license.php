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
WWD_License::deactivate();

check( 'removing the key locks the paid features', ! WWD_License::is_valid() );
check( 'and forgets it', '' === WWD_License::state()['key'] );

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

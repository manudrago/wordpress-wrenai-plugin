<?php
/**
 * A build that carries the shop's public key must believe only the shop. This
 * is the test for forgery, replay and tampering, in a process where the public
 * key is defined - which is why it cannot live in test-license.php, where it is
 * not. Runs without WordPress:
 *
 *     php tests/test-license-signature.php
 *
 * @package DataChat_AI
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';

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
 * A fresh RSA pair, as the shop generates on first use.
 *
 * @return array {private, public}
 */
function make_keys() {
	$pair = openssl_pkey_new(
		array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		)
	);

	$private = '';

	openssl_pkey_export( $pair, $private );

	return array(
		'private' => $private,
		'public'  => openssl_pkey_get_details( $pair )['key'],
	);
}

$shop     = make_keys();
$impostor = make_keys();

define( 'WWD_EDITION', 'pro' );
define( 'WWD_LICENSE_ENDPOINT', 'https://shop.example/licence' );
define( 'WWD_LICENSE_PUBLIC_KEY', $shop['public'] );

require __DIR__ . '/../includes/class-wwd-license.php';

/**
 * Sign the fields the plugin checks, exactly as deploy/license-endpoint.php does.
 *
 * @param array  $answer  status, expires, domain, nonce, issued_at.
 * @param string $private Private key to sign with.
 * @return string
 */
function sign_like_the_shop( array $answer, $private ) {
	$ordered = array();

	foreach ( array( 'status', 'expires', 'domain', 'nonce', 'issued_at' ) as $field ) {
		$ordered[] = isset( $answer[ $field ] ) ? (string) $answer[ $field ] : '';
	}

	$signature = '';

	openssl_sign( implode( '|', $ordered ), $signature, $private, OPENSSL_ALGO_SHA256 );

	return base64_encode( $signature );
}

/**
 * Answer the next check the way the shop would: signing the nonce it was sent.
 *
 * @param array  $answer  Partial answer, defaults to a valid licence.
 * @param string $private Key to sign with, the shop's by default.
 * @param array  $tamper  Fields changed after signing.
 * @return void
 */
function shop_signs( array $answer = array(), $private = null, array $tamper = array() ) {
	global $shop;

	$private = null === $private ? $shop['private'] : $private;

	WWD_Test_HTTP::reset();

	WWD_Test_HTTP::$responder = static function ( array $request ) use ( $answer, $private, $tamper ) {
		$body = array_merge(
			array(
				'status'    => 'valid',
				'expires'   => '',
				'domain'    => WWD_License::domain(),
				'nonce'     => isset( $request['body']['nonce'] ) ? $request['body']['nonce'] : '',
				'issued_at' => gmdate( 'c' ),
			),
			$answer
		);

		$body['signature'] = sign_like_the_shop( $body, $private );

		// What a man in the middle would do: keep the signature, change the
		// words.
		return array( 'response' => array_merge( $body, $tamper ) );
	};
}

// ---------------------------------------------------------------------------
// A signed answer from the shop
// ---------------------------------------------------------------------------

check( 'the build knows the shop it trusts', '' !== WWD_License::public_key() );

shop_signs();

$activated = WWD_License::activate( 'KEY-1234' );

check( 'a properly signed answer activates', ! is_wp_error( $activated ), is_wp_error( $activated ) ? $activated->get_error_message() : '' );
check( 'and unlocks the paid features', WWD_License::is_valid() );

$sent = WWD_Test_HTTP::$last;

check( 'a nonce was sent to sign', ! empty( $sent['body']['nonce'] ) );

shop_signs();
WWD_License::activate( 'KEY-1234' );

check( 'and a different one each time', WWD_Test_HTTP::$last['body']['nonce'] !== $sent['body']['nonce'] );

shop_signs( array( 'status' => 'invalid', 'message' => 'Refunded' ) );

$refused = WWD_License::activate( 'KEY-1234' );

check( 'a signed refusal is still a refusal', is_wp_error( $refused ) && 'wwd_license_refused' === $refused->get_error_code(), $refused->get_error_code() );
check( 'and it locks', ! WWD_License::is_valid() );

// ---------------------------------------------------------------------------
// What an impostor cannot do
// ---------------------------------------------------------------------------

WWD_License::deactivate();

shop_signs( array(), $impostor['private'] );

$forged = WWD_License::activate( 'KEY-1234' );

check( 'an answer signed by someone else does not activate', is_wp_error( $forged ) );
check( 'and says so as a bad signature', 'wwd_license_forged' === $forged->get_error_code(), $forged->get_error_code() );
check( 'and unlocks nothing', ! WWD_License::is_valid() );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$response = array( 'status' => 'valid' );

$unsigned = WWD_License::activate( 'KEY-1234' );

check( 'an unsigned "valid" does not activate', is_wp_error( $unsigned ) );
check( 'and says the answer was not signed', 'wwd_license_unsigned' === $unsigned->get_error_code(), $unsigned->get_error_code() );

// The shop signs a refusal; something in the middle flips the word.
shop_signs( array( 'status' => 'invalid' ), null, array( 'status' => 'valid' ) );

$flipped = WWD_License::activate( 'KEY-1234' );

check( 'a refusal flipped to valid in flight is caught', is_wp_error( $flipped ) && 'wwd_license_forged' === $flipped->get_error_code(), $flipped->get_error_code() );
check( 'and nothing is unlocked by it', ! WWD_License::is_valid() );

// An expiry stretched by a year, over the shop's own signature.
shop_signs( array( 'expires' => gmdate( 'Y-m-d', time() + 86400 ) ), null, array( 'expires' => gmdate( 'Y-m-d', time() + 31536000 ) ) );

$stretched = WWD_License::activate( 'KEY-1234' );

check( 'an expiry stretched in flight is caught', is_wp_error( $stretched ) && 'wwd_license_forged' === $stretched->get_error_code(), $stretched->get_error_code() );

// ---------------------------------------------------------------------------
// Replay: yesterday's yes, played back today
// ---------------------------------------------------------------------------

shop_signs();
WWD_License::activate( 'KEY-1234' );

check( 'a good licence to replay against', WWD_License::is_valid() );

$captured = array(
	'status'    => 'valid',
	'expires'   => '',
	'domain'    => WWD_License::domain(),
	'nonce'     => WWD_Test_HTTP::$last['body']['nonce'],
	'issued_at' => gmdate( 'c' ),
);

$captured['signature'] = sign_like_the_shop( $captured, $shop['private'] );

WWD_License::deactivate();

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$response = $captured;

$replayed = WWD_License::activate( 'KEY-1234' );

check( 'that same answer played back to a new request fails', is_wp_error( $replayed ) );
check( 'because the nonce is not the one asked about', 'wwd_license_replayed' === $replayed->get_error_code(), $replayed->get_error_code() );
check( 'so a captured yes unlocks nothing', ! WWD_License::is_valid() );

// Correctly signed, correct nonce, but dated last month.
shop_signs( array( 'issued_at' => gmdate( 'c', time() - ( 30 * DAY_IN_SECONDS ) ) ) );

$stale = WWD_License::activate( 'KEY-1234' );

check( 'an answer dated a month ago fails', is_wp_error( $stale ) && 'wwd_license_stale' === $stale->get_error_code(), $stale->get_error_code() );

// ---------------------------------------------------------------------------
// A rejected signature is silence, not a refusal
// ---------------------------------------------------------------------------

shop_signs();
WWD_License::activate( 'KEY-1234' );

check( 'a confirmed licence, before anything goes wrong', WWD_License::is_valid() );

$state               = WWD_License::state();
$state['checked_at'] = time() - ( 2 * DAY_IN_SECONDS );
update_option( WWD_License::OPTION, $state, false );

// Something in front of the shop starts answering unsigned.
WWD_Test_HTTP::reset();
WWD_Test_HTTP::$response = array( 'status' => 'invalid' );

WWD_License::maybe_recheck();

check( 'an unsigned answer at re-check does not lock a paying site out', WWD_License::is_valid() );
check( 'and the re-check is not retried until tomorrow', WWD_License::state()['checked_at'] > time() - 10 );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

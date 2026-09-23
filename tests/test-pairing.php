<?php
/**
 * The pairing door: it must open only for the administrator's code, only for
 * an hour, and only for a handful of guesses. Runs without WordPress:
 *
 *     php tests/test-pairing.php
 *
 * @package WP_Wren_Dashboards
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-wwd-pairing.php';

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
 * Error code of a result, or the empty string when it is not an error.
 *
 * @param mixed $result Result.
 * @return string
 */
function error_code( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}

// A site nobody opened pairing on refuses everything.
WWD_Pairing::close();

check( 'closed by default', ! WWD_Pairing::is_open() );
check( 'a closed site rejects any code', 'wwd_pair_closed' === error_code( WWD_Pairing::claim( 'whatever' ) ) );

$opened = WWD_Pairing::open( true );

check( 'the code is 128 bits of hex', (bool) preg_match( '/^[a-f0-9]{32}$/', $opened['code'] ) );
check( 'pairing is open afterwards', WWD_Pairing::is_open() );
check( 'it expires within the hour', $opened['expires'] > time() && $opened['expires'] <= time() + HOUR_IN_SECONDS );

$state = WWD_Pairing::state();

check( 'the code itself is not stored', ! in_array( $opened['code'], $state, true ) );
check( 'only a digest is stored', hash( 'sha256', $opened['code'] ) === $state['hash'] );

check( 'a wrong code is rejected', 'wwd_pair_rejected' === error_code( WWD_Pairing::claim( str_repeat( 'a', 32 ) ) ) );
check( 'a wrong code is counted', 1 === (int) WWD_Pairing::state()['failures'] );
check( 'the right code is accepted', true === WWD_Pairing::claim( $opened['code'] ) );

// A server allowed to refresh keeps the code alive, because a quick tunnel
// hands out a new address on every restart.
WWD_Pairing::note_success( 'https://example.trycloudflare.com' );

check( 'a success clears the failure count', 0 === (int) WWD_Pairing::state()['failures'] );
check( 'a success is recorded', WWD_Pairing::state()['paired_at'] > 0 );
check( 'the endpoint is remembered', 'https://example.trycloudflare.com' === WWD_Pairing::state()['endpoint'] );
check( 'a refreshing server may come back', true === WWD_Pairing::claim( $opened['code'] ) );

// Without refresh the code is spent on first use.
$once = WWD_Pairing::open( false );

check( 'the second code differs from the first', $once['code'] !== $opened['code'] );
check( 'the old code no longer works', 'wwd_pair_rejected' === error_code( WWD_Pairing::claim( $opened['code'] ) ) );

WWD_Pairing::claim( $once['code'] );
WWD_Pairing::note_success( 'http://127.0.0.1:5555' );

check( 'a single-use code closes after pairing', ! WWD_Pairing::is_open() );
check( 'and cannot be replayed', 'wwd_pair_closed' === error_code( WWD_Pairing::claim( $once['code'] ) ) );

// Expiry.
$stale = WWD_Pairing::open( true );

$state            = WWD_Pairing::state();
$state['expires'] = time() - 1;
update_option( WWD_Pairing::OPTION, $state, false );

check( 'an expired code is rejected', 'wwd_pair_expired' === error_code( WWD_Pairing::claim( $stale['code'] ) ) );
check( 'and expiry shuts the door', ! WWD_Pairing::is_open() );

// Guessing.
$guessed = WWD_Pairing::open( true );

for ( $i = 0; $i < WWD_Pairing::MAX_FAILURES; $i++ ) {
	WWD_Pairing::claim( str_repeat( 'b', 32 ) );
}

check(
	'too many wrong codes burn the pairing',
	'wwd_pair_burned' === error_code( WWD_Pairing::claim( $guessed['code'] ) )
);
check( 'even the right code cannot reopen it', 'wwd_pair_closed' === error_code( WWD_Pairing::claim( $guessed['code'] ) ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

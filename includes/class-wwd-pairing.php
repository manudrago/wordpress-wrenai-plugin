<?php
/**
 * One-time pairing between this site and a Wren AI server.
 *
 * Installing Wren AI produces two values the plugin needs - an endpoint and a
 * token - and with a Cloudflare quick tunnel the endpoint is random and
 * changes whenever the tunnel restarts. Copying it by hand every time is the
 * worst part of the setup, so the installer can post it here instead.
 *
 * The door is shut unless an administrator opens it: a 128-bit code, stored
 * only as a hash, valid for an hour, burned after a handful of wrong guesses.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues and verifies pairing codes.
 */
class WWD_Pairing {

	const OPTION = 'wwd_pairing';

	/**
	 * How long a freshly issued code stays usable.
	 */
	const TTL = HOUR_IN_SECONDS;

	/**
	 * Wrong codes tolerated before the pairing closes itself.
	 */
	const MAX_FAILURES = 10;

	/**
	 * Stored pairing state, with every key present.
	 *
	 * @return array
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge(
			array(
				'hash'      => '',
				'expires'   => 0,
				'failures'  => 0,
				'refresh'   => 1,
				'paired_at' => 0,
				'endpoint'  => '',
			),
			$stored
		);
	}

	/**
	 * Open the door and return the code in clear - the only time it exists.
	 *
	 * @param bool $refresh Whether the server may keep updating the endpoint.
	 * @return array Code and expiry.
	 */
	public static function open( $refresh = true ) {
		$code = bin2hex( random_bytes( 16 ) );

		update_option(
			self::OPTION,
			array(
				'hash'      => self::hash( $code ),
				'expires'   => time() + self::TTL,
				'failures'  => 0,
				'refresh'   => $refresh ? 1 : 0,
				'paired_at' => 0,
				'endpoint'  => '',
			),
			false
		);

		return array(
			'code'    => $code,
			'expires' => time() + self::TTL,
		);
	}

	/**
	 * Shut the door.
	 *
	 * @return void
	 */
	public static function close() {
		delete_option( self::OPTION );
	}

	/**
	 * Whether a code is currently accepted.
	 *
	 * @return bool
	 */
	public static function is_open() {
		$state = self::state();

		return '' !== $state['hash'] && $state['expires'] > time();
	}

	/**
	 * Check a code, and note the outcome.
	 *
	 * @param string $code Code as sent by the installer.
	 * @return true|WP_Error
	 */
	public static function claim( $code ) {
		$state = self::state();

		if ( '' === $state['hash'] ) {
			return new WP_Error(
				'wwd_pair_closed',
				__( 'This site is not waiting to be paired. Open pairing from DataChat → Settings.', 'datachat-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( $state['expires'] <= time() ) {
			self::close();

			return new WP_Error(
				'wwd_pair_expired',
				__( 'The pairing code has expired. Generate a new one.', 'datachat-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( $state['failures'] >= self::MAX_FAILURES ) {
			self::close();

			return new WP_Error(
				'wwd_pair_burned',
				__( 'Too many wrong pairing codes. Generate a new one.', 'datachat-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( ! hash_equals( (string) $state['hash'], self::hash( (string) $code ) ) ) {
			$state['failures']++;

			update_option( self::OPTION, $state, false );

			return new WP_Error(
				'wwd_pair_rejected',
				__( 'Wrong pairing code.', 'datachat-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Record a successful pairing.
	 *
	 * A server that may refresh its endpoint keeps the code alive, because a
	 * quick tunnel hands out a new address every time it restarts and the
	 * whole point is that nobody has to copy it by hand. Otherwise the code is
	 * spent.
	 *
	 * @param string $endpoint Endpoint just stored.
	 * @return void
	 */
	public static function note_success( $endpoint ) {
		$state = self::state();

		$state['failures']  = 0;
		$state['paired_at'] = time();
		$state['endpoint']  = $endpoint;

		if ( empty( $state['refresh'] ) ) {
			$state['hash']    = '';
			$state['expires'] = 0;
		} else {
			$state['expires'] = time() + self::TTL;
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Hash of a code. The code is 128 random bits, so a plain digest is enough
	 * - there is nothing to brute force.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function hash( $code ) {
		return hash( 'sha256', (string) $code );
	}
}

<?php
/**
 * The licence behind the paid editions.
 *
 * Validation talks to the shop that sold the key. Which shop, and in what
 * dialect, is deliberately not hard-coded: the request and the reading of the
 * answer both go through filters, so pointing this at a different licensing
 * plugin is a few lines in a site's own code rather than a fork of this one.
 *
 * @package DataChat_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores, checks and re-checks the licence key.
 */
class WWD_License {

	const OPTION = 'wwd_license';

	/**
	 * How often a stored licence is checked again.
	 */
	const RECHECK = DAY_IN_SECONDS;

	/**
	 * How long a licence keeps working when the shop cannot be reached.
	 *
	 * A shop that is down, a DNS hiccup or an outbound firewall must not turn
	 * a paying customer's plugin off. Only an answer that actually says the
	 * licence is invalid does that.
	 */
	const GRACE = 14 * DAY_IN_SECONDS;

	/**
	 * How far out a signed answer's own timestamp may be.
	 *
	 * Generous, because the clock that matters is the customer's and plenty of
	 * them are minutes out. Replay is stopped by the nonce, not by this.
	 */
	const SIGNATURE_WINDOW = 2 * DAY_IN_SECONDS;

	/**
	 * The fields a signature covers, in this order, joined by a pipe.
	 *
	 * The shop signs exactly this string. deploy/license-endpoint.php builds it
	 * the same way; the two have to stay in step, so change neither alone.
	 */
	const SIGNED_FIELDS = array( 'status', 'expires', 'domain', 'nonce', 'issued_at' );

	/**
	 * Stored licence state, with every key present.
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
				'key'          => '',
				'status'       => 'none',
				'message'      => '',
				'expires'      => '',
				'checked_at'   => 0,
				'confirmed_at' => 0,
				// What the server last replied, kept so a shop whose dialect
				// this plugin does not speak yet can be read by a human.
				'last_code'    => 0,
				'last_body'    => '',
			),
			$stored
		);
	}

	/**
	 * Which edition this build is.
	 *
	 * @return string free, pro or agency.
	 */
	public static function edition() {
		return defined( 'WWD_EDITION' ) ? (string) WWD_EDITION : 'free';
	}

	/**
	 * Whether this build asks for a licence at all.
	 *
	 * @return bool
	 */
	public static function is_paid_edition() {
		return in_array( self::edition(), array( 'pro', 'agency' ), true );
	}

	/**
	 * Whether the paid features are unlocked right now.
	 *
	 * @return bool
	 */
	public static function is_valid() {
		if ( ! self::is_paid_edition() ) {
			return false;
		}

		$state = self::state();

		if ( '' === $state['key'] ) {
			return false;
		}

		if ( 'invalid' === $state['status'] || 'expired' === $state['status'] ) {
			return false;
		}

		if ( $state['expires'] && strtotime( $state['expires'] ) < time() ) {
			return false;
		}

		if ( 'valid' !== $state['status'] ) {
			return false;
		}

		// Confirmed at some point, and not so long ago that the silence has
		// become suspicious.
		return $state['confirmed_at'] > 0 && ( time() - (int) $state['confirmed_at'] ) < self::GRACE + self::RECHECK;
	}

	/**
	 * Check a key with the shop and store the outcome.
	 *
	 * @param string $key Licence key.
	 * @return array|WP_Error The new state.
	 */
	public static function activate( $key ) {
		$key = trim( sanitize_text_field( $key ) );

		if ( '' === $key ) {
			return new WP_Error( 'wwd_license_empty', __( 'Paste the licence key you were sent.', 'datachat-ai' ) );
		}

		$answer = self::ask_the_shop( $key );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$state = array_merge(
			self::state(),
			array(
				'key'        => $key,
				'status'     => $answer['status'],
				'message'    => $answer['message'],
				'expires'    => $answer['expires'],
				'checked_at' => time(),
			)
		);

		if ( 'valid' === $answer['status'] ) {
			$state['confirmed_at'] = time();
		}

		update_option( self::OPTION, $state, false );

		if ( 'valid' !== $answer['status'] ) {
			return new WP_Error(
				'wwd_license_refused',
				'' !== $answer['message'] ? $answer['message'] : __( 'That licence key was not accepted.', 'datachat-ai' )
			);
		}

		return $state;
	}

	/**
	 * Forget the key on this site.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_option( self::OPTION );
	}

	/**
	 * Check again, quietly, when the last check is old enough.
	 *
	 * @return void
	 */
	public static function maybe_recheck() {
		if ( ! self::is_paid_edition() ) {
			return;
		}

		$state = self::state();

		if ( '' === $state['key'] ) {
			return;
		}

		if ( time() - (int) $state['checked_at'] < self::RECHECK ) {
			return;
		}

		$answer = self::ask_the_shop( $state['key'] );

		$state['checked_at'] = time();

		if ( is_wp_error( $answer ) ) {
			// Unreachable is not the same as refused: keep whatever was last
			// confirmed and try again tomorrow.
			$state['message'] = $answer->get_error_message();

			update_option( self::OPTION, $state, false );

			return;
		}

		$state['status']  = $answer['status'];
		$state['message'] = $answer['message'];
		$state['expires'] = $answer['expires'];

		if ( 'valid' === $answer['status'] ) {
			$state['confirmed_at'] = time();
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Ask the shop about a key.
	 *
	 * The shape below is a starting point, not a standard: every licensing
	 * plugin words this differently. `wwd_license_request` rewrites what is
	 * sent, `wwd_license_response` rewrites how the answer is read, and both
	 * receive the raw material, so adapting to a particular shop never means
	 * touching this file.
	 *
	 * @param string $key Licence key.
	 * @return array|WP_Error {status, message, expires}
	 */
	protected static function ask_the_shop( $key ) {
		// Fresh per request, so an answer captured once cannot be replayed to
		// unlock a second site, or the same site tomorrow.
		$nonce = wp_generate_uuid4();

		$request = array(
			'url'  => self::endpoint(),
			'body' => array(
				'action'      => 'validate',
				// Licensing plugins disagree about what to call these, and an
				// extra field is ignored where a missing one is fatal.
				'license_key' => $key,
				'key'         => $key,
				'product'     => 'datachat-ai-' . self::edition(),
				'domain'      => self::domain(),
				'url'         => home_url(),
				'nonce'       => $nonce,
			),
		);

		/**
		 * Filters the validation request.
		 *
		 * @param array  $request URL and body.
		 * @param string $key     Licence key.
		 */
		$request = apply_filters( 'wwd_license_request', $request, $key );

		if ( empty( $request['url'] ) ) {
			return new WP_Error(
				'wwd_license_unconfigured',
				__( 'No licensing endpoint is configured for this build.', 'datachat-ai' )
			);
		}

		$response = wp_remote_post(
			$request['url'],
			array(
				'timeout' => 20,
				'body'    => $request['body'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wwd_license_unreachable',
				sprintf(
					/* translators: %s: transport error. */
					__( 'Could not reach the licence server: %s', 'datachat-ai' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// A shop answering 500, or an error page from something in front of
		// it, is a shop that is down. That is silence, not a refusal, and the
		// difference decides whether a paying customer keeps working.
		if ( $code < 200 || $code >= 300 ) {
			self::remember_reply( $code, $body );

			return new WP_Error(
				'wwd_license_unreachable',
				sprintf(
					/* translators: %d: HTTP status. */
					__( 'The licence server answered HTTP %d.', 'datachat-ai' ),
					$code
				)
			);
		}

		self::remember_reply( $code, $body );

		$data = json_decode( $body, true );
		$data = is_array( $data ) ? $data : array();

		$answer = array(
			'status'  => self::read_status( $data ),
			'message' => isset( $data['message'] ) ? (string) $data['message'] : '',
			'expires' => self::read_expiry( $data ),
		);

		/**
		 * Filters how a shop's answer is read.
		 *
		 * @param array  $answer Status, message and expiry.
		 * @param array  $data   Decoded body.
		 * @param string $body   Raw body, for a shop that does not answer JSON.
		 */
		$answer = apply_filters( 'wwd_license_response', $answer, $data, $body );

		// Understood neither as valid nor as refused: treat it as silence
		// rather than guessing, and say so plainly.
		if ( 'unknown' === $answer['status'] ) {
			return new WP_Error(
				'wwd_license_unreadable',
				__( 'The licence server answered something this plugin could not read. If your shop words it differently, map it with the wwd_license_response filter.', 'datachat-ai' )
			);
		}

		// The last word, after every filter: a build that carries the shop's
		// public key trusts nothing the shop did not sign.
		$signature = self::check_signature( $answer, $data, $nonce );

		if ( is_wp_error( $signature ) ) {
			return $signature;
		}

		return $answer;
	}

	/**
	 * Whether this answer really came from the shop that sold the key.
	 *
	 * Without this, the endpoint is only as trustworthy as the network in
	 * front of it: anything that can answer at that URL - a hosts file, a
	 * filter on the site, a proxy - can say "valid". The shop signs its answer
	 * with a private key that never leaves it, and the public half is baked
	 * into the build, where being readable costs nothing.
	 *
	 * A build with no public key skips all of this, so a shop that does not
	 * sign still works.
	 *
	 * @param array  $answer Status, message and expiry.
	 * @param array  $data   Decoded body.
	 * @param string $nonce  The nonce this request sent.
	 * @return true|WP_Error
	 */
	protected static function check_signature( array $answer, array $data, $nonce ) {
		$public_key = self::public_key();

		if ( '' === $public_key ) {
			return true;
		}

		if ( ! function_exists( 'openssl_verify' ) ) {
			// Refusing here would lock out a site whose PHP is merely thin, so
			// this is silence: whatever was last confirmed stands.
			return new WP_Error(
				'wwd_license_no_openssl',
				__( 'This licence is signed by the shop, but PHP here has no OpenSSL to check the signature with. Ask your host to enable it.', 'datachat-ai' )
			);
		}

		$offered = isset( $data['signature'] ) ? (string) $data['signature'] : '';

		if ( '' === $offered ) {
			return new WP_Error(
				'wwd_license_unsigned',
				__( 'The licence server did not sign its answer. Nothing was changed, because an unsigned answer could have come from anywhere.', 'datachat-ai' )
			);
		}

		// Its own nonce, or it is an older answer being played back.
		if ( ! isset( $data['nonce'] ) || ! hash_equals( (string) $nonce, (string) $data['nonce'] ) ) {
			return new WP_Error(
				'wwd_license_replayed',
				__( 'The licence server answered a different question than the one asked. Nothing was changed.', 'datachat-ai' )
			);
		}

		$issued_at = isset( $data['issued_at'] ) ? (string) $data['issued_at'] : '';
		$stamp     = '' === $issued_at ? 0 : strtotime( $issued_at );

		if ( ! $stamp || abs( time() - $stamp ) > self::SIGNATURE_WINDOW ) {
			return new WP_Error(
				'wwd_license_stale',
				__( 'The licence server\'s answer is not dated within the last two days. Check the clock on this site.', 'datachat-ai' )
			);
		}

		$signed = self::signed_message(
			array(
				'status'    => $answer['status'],
				'expires'   => $answer['expires'],
				'domain'    => self::domain(),
				'nonce'     => (string) $nonce,
				'issued_at' => $issued_at,
			)
		);

		$verified = openssl_verify( $signed, (string) base64_decode( $offered, true ), $public_key, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $verified ) {
			return new WP_Error(
				'wwd_license_forged',
				__( 'The licence server\'s signature did not match its answer. Nothing was changed. If you are pointing this at your own endpoint, it has to sign what it says.', 'datachat-ai' )
			);
		}

		return true;
	}

	/**
	 * The exact string a signature covers.
	 *
	 * @param array $parts Values by field name.
	 * @return string
	 */
	protected static function signed_message( array $parts ) {
		$ordered = array();

		foreach ( self::SIGNED_FIELDS as $field ) {
			$ordered[] = isset( $parts[ $field ] ) ? (string) $parts[ $field ] : '';
		}

		return implode( '|', $ordered );
	}

	/**
	 * The shop's public key, baked into a signing build.
	 *
	 * @return string PEM, or empty when this build does not expect signatures.
	 */
	public static function public_key() {
		$key = defined( 'WWD_LICENSE_PUBLIC_KEY' ) ? (string) WWD_LICENSE_PUBLIC_KEY : '';

		/**
		 * Filters the public key answers are checked against.
		 *
		 * @param string $key PEM.
		 */
		return trim( (string) apply_filters( 'wwd_license_public_key', $key ) );
	}

	/**
	 * Status out of a shop's answer, tolerating the usual spellings.
	 *
	 * @param array $data Decoded body.
	 * @return string valid, invalid, expired or unknown.
	 */
	protected static function read_status( array $data ) {
		foreach ( array( 'status', 'license', 'license_status', 'result' ) as $field ) {
			if ( empty( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				continue;
			}

			$value = strtolower( $data[ $field ] );

			if ( in_array( $value, array( 'valid', 'active', 'success', 'ok' ), true ) ) {
				return 'valid';
			}

			if ( false !== strpos( $value, 'expire' ) ) {
				return 'expired';
			}

			if ( in_array( $value, array( 'invalid', 'inactive', 'failed', 'error' ), true ) ) {
				return 'invalid';
			}
		}

		foreach ( array( 'success', 'valid' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_bool( $data[ $field ] ) ) {
				return $data[ $field ] ? 'valid' : 'invalid';
			}
		}

		// An endpoint that hands back a token has answered the question by
		// handing it back: there is nothing to issue for a key it refuses.
		foreach ( array( 'token', 'access_token', 'jwt' ) as $field ) {
			if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				return 'valid';
			}
		}

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$nested = self::read_status( $data['data'] );

			if ( 'unknown' !== $nested ) {
				return $nested;
			}
		}

		return 'unknown';
	}

	/**
	 * Keep the server's last reply, so an unreadable one can be looked at.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Raw body.
	 * @return void
	 */
	protected static function remember_reply( $code, $body ) {
		$state = self::state();

		$state['last_code'] = (int) $code;
		$state['last_body'] = substr( trim( wp_strip_all_tags( (string) $body ) ), 0, 1000 );

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Expiry date out of a shop's answer.
	 *
	 * @param array $data Decoded body.
	 * @return string
	 */
	protected static function read_expiry( array $data ) {
		foreach ( array( 'expires', 'expiry', 'expires_at', 'expire_date' ) as $field ) {
			if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				return $data[ $field ];
			}
		}

		return '';
	}

	/**
	 * Where keys are checked. Baked into the build, overridable on a site.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$endpoint = defined( 'WWD_LICENSE_ENDPOINT' ) ? (string) WWD_LICENSE_ENDPOINT : '';

		/**
		 * Filters the licensing endpoint.
		 *
		 * @param string $endpoint URL.
		 */
		return (string) apply_filters( 'wwd_license_endpoint', $endpoint );
	}

	/**
	 * The domain a key is being used on, as a shop expects to see it.
	 *
	 * @return string
	 */
	public static function domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return preg_replace( '/^www\./i', '', (string) $host );
	}

	/**
	 * A sentence for the settings screen.
	 *
	 * @return string
	 */
	public static function summary() {
		$state = self::state();

		if ( '' === $state['key'] ) {
			return __( 'No licence key yet.', 'datachat-ai' );
		}

		if ( self::is_valid() ) {
			if ( $state['expires'] ) {
				return sprintf(
					/* translators: %s: expiry date. */
					__( 'Active until %s.', 'datachat-ai' ),
					$state['expires']
				);
			}

			return __( 'Active.', 'datachat-ai' );
		}

		if ( '' !== $state['message'] ) {
			return $state['message'];
		}

		return __( 'This licence is not active on this site.', 'datachat-ai' );
	}
}

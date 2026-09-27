<?php
/**
 * Plugin Name:       DataChat Licence Endpoint
 * Description:       Answers "is this licence key valid for this site?" for the DataChat AI paid editions, and signs the answer. Install this on the shop that sells them, not on a customer's site.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Emanuel Draghetti
 * License:           GPL-2.0-or-later
 *
 * Why this exists
 * ---------------
 * Simple License Key for WooCommerce publishes newly issued keys, encrypted,
 * at /wp-json/rf/slk-woo-open-key_api/token. That is a feed of keys, not a way
 * to ask about one, and reading it needs a passphrase. Shipping that passphrase
 * inside a GPL plugin would hand every customer the means to read every other
 * customer's key.
 *
 * So the credentials stay here and the shop answers the question. The feed is
 * read on this server, where the passphrase is no more exposed than the
 * database password beside it; a key that is not in the feed - because the feed
 * holds only what has not expired yet - is looked up in the order it was sold
 * with. Either way the answer that goes back is yes, no or expired. No key ever
 * leaves this server.
 *
 * And the answer is signed. Without that, the endpoint is only as trustworthy
 * as the network in front of it: anything able to answer at that URL can say
 * "valid". This holds an RSA private key, generated here on first use, and
 * signs what it says; the customer's build carries only the public half.
 *
 * Setting up
 * ----------
 * 1. Install and activate this on the shop.
 * 2. Put the SLKWoo passphrase in wp-config.php - never in this file, which is
 *    GPL and gets copied:
 *
 *        define( 'DATACHAT_SLKWOO_PASSPHRASE', '...' );
 *
 *    Without it the feed is skipped and lookups fall back to order meta.
 * 3. Fetch the public key from /wp-json/datachat/v1/license/pubkey and build
 *    the paid archives with it:
 *
 *        ./bin/build-zip.sh --all --public-key shop-public-key.pem
 *
 * @package DataChat_Licence
 */

defined( 'ABSPATH' ) || exit;

/**
 * Looks a licence key up, and signs what it finds.
 */
class DataChat_Licence_Endpoint {

	const NAMESPACE_V1 = 'datachat/v1';

	/**
	 * Where the RSA pair lives once generated.
	 */
	const KEYS = 'datachat_licence_keys';

	/**
	 * What SLKWoo encrypts its feed with.
	 */
	const CIPHER = 'aes-256-cfb';

	/**
	 * How long a read of the feed is reused.
	 */
	const FEED_CACHE = 300;

	/**
	 * Order statuses that count as paid.
	 *
	 * @var array
	 */
	const PAID = array( 'completed', 'processing' );

	/**
	 * The fields a signature covers, in this order, joined by a pipe.
	 *
	 * WWD_License::SIGNED_FIELDS in the plugin is the same list. The two have
	 * to stay in step, so change neither alone.
	 *
	 * @var array
	 */
	const SIGNED_FIELDS = array( 'status', 'expires', 'domain', 'nonce', 'issued_at' );

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/license',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'check' ),
				// Customer sites have no account here: the key is the secret.
				'permission_callback' => '__return_true',
				'args'                => array(
					'license_key' => array( 'type' => 'string' ),
					'key'         => array( 'type' => 'string' ),
					'domain'      => array( 'type' => 'string' ),
					'product'     => array( 'type' => 'string' ),
					'nonce'       => array( 'type' => 'string' ),
				),
			)
		);

		// The public half of the signing key. Public on purpose: it only lets
		// the holder check a signature, never make one.
		register_rest_route(
			self::NAMESPACE_V1,
			'/license/pubkey',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'pubkey' ),
				'permission_callback' => '__return_true',
			)
		);

		// Says where a key was found and what surrounds it, so the lookup can
		// be tuned to how this shop actually stores them.
		register_rest_route(
			self::NAMESPACE_V1,
			'/license/probe',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'probe' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Answer a customer site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function check( WP_REST_Request $request ) {
		$key    = trim( (string) ( $request->get_param( 'license_key' ) ? $request->get_param( 'license_key' ) : $request->get_param( 'key' ) ) );
		$domain = (string) $request->get_param( 'domain' );
		$nonce  = (string) $request->get_param( 'nonce' );

		if ( ! self::allowed() ) {
			return new WP_REST_Response(
				array(
					'status'  => 'invalid',
					'message' => 'Too many checks from this address. Try again later.',
				),
				429
			);
		}

		if ( '' === $key ) {
			return self::answer( array( 'status' => 'invalid', 'message' => 'No licence key given.' ), $domain, $nonce );
		}

		$found = self::find( $key );

		if ( ! $found ) {
			return self::answer( array( 'status' => 'invalid', 'message' => 'Unknown licence key.' ), $domain, $nonce );
		}

		// A key still in SLKWoo's feed is a live key: the feed drops them when
		// they expire. There may be no order to look at, so this stands alone.
		if ( empty( $found['order_id'] ) ) {
			return self::answer(
				array(
					'status'  => 'valid',
					'expires' => isset( $found['expires'] ) ? (string) $found['expires'] : '',
				),
				$domain,
				$nonce,
				$key,
				$found
			);
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $found['order_id'] ) : null;

		if ( ! $order ) {
			return self::answer( array( 'status' => 'invalid', 'message' => 'The order behind this key no longer exists.' ), $domain, $nonce );
		}

		$status = $order->get_status();

		if ( ! in_array( $status, self::PAID, true ) ) {
			return self::answer(
				array(
					'status'  => 'invalid',
					'message' => sprintf( 'The order behind this key is %s.', $status ),
				),
				$domain,
				$nonce
			);
		}

		$expires = self::expiry( $found['order_id'] );

		if ( $expires && strtotime( $expires ) < time() ) {
			return self::answer(
				array( 'status' => 'expired', 'expires' => $expires, 'message' => 'This licence has expired.' ),
				$domain,
				$nonce
			);
		}

		return self::answer(
			array( 'status' => 'valid', 'expires' => $expires ),
			$domain,
			$nonce,
			$key,
			$found
		);
	}

	/**
	 * Fill an answer out, let the shop have its say, and sign it.
	 *
	 * @param array  $answer Status, and optionally expiry and message.
	 * @param string $domain Domain that asked.
	 * @param string $nonce  Nonce that asked.
	 * @param string $key    Licence key, for the filter.
	 * @param array  $found  Where it was found, for the filter.
	 * @return WP_REST_Response
	 */
	protected static function answer( array $answer, $domain, $nonce, $key = '', $found = array() ) {
		$answer = array_merge(
			array(
				'status'  => 'invalid',
				'expires' => '',
				'message' => '',
			),
			$answer
		);

		/**
		 * Filters the answer, for a shop with rules of its own - a seat count,
		 * a domain allow-list, a grace period after a refund.
		 *
		 * @param array  $answer Status, expiry and message.
		 * @param string $key    Licence key.
		 * @param array  $found  Where it was found.
		 * @param string $domain Domain asking.
		 */
		$answer = apply_filters( 'datachat_license_answer', $answer, $key, $found, $domain );

		$answer['issued_at'] = gmdate( 'c' );
		$answer['nonce']     = (string) $nonce;

		$signature = self::sign(
			array(
				'status'    => (string) $answer['status'],
				'expires'   => (string) $answer['expires'],
				'domain'    => (string) $domain,
				'nonce'     => (string) $nonce,
				'issued_at' => (string) $answer['issued_at'],
			)
		);

		if ( '' !== $signature ) {
			$answer['signature'] = $signature;
		}

		return new WP_REST_Response( $answer, 200 );
	}

	/**
	 * Sign the fields a customer's build checks.
	 *
	 * @param array $parts Values by field name.
	 * @return string Base64 signature, or empty when this server cannot sign.
	 */
	protected static function sign( array $parts ) {
		$keys = self::keypair();

		if ( empty( $keys['private'] ) || ! function_exists( 'openssl_sign' ) ) {
			return '';
		}

		$ordered = array();

		foreach ( self::SIGNED_FIELDS as $field ) {
			$ordered[] = isset( $parts[ $field ] ) ? (string) $parts[ $field ] : '';
		}

		$signature = '';

		if ( ! openssl_sign( implode( '|', $ordered ), $signature, $keys['private'], OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}

		return base64_encode( $signature );
	}

	/**
	 * The signing pair, generated on first use and kept here after.
	 *
	 * @return array {private, public}
	 */
	protected static function keypair() {
		$stored = get_option( self::KEYS, array() );

		if ( is_array( $stored ) && ! empty( $stored['private'] ) && ! empty( $stored['public'] ) ) {
			return $stored;
		}

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return array();
		}

		$pair = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		if ( ! $pair ) {
			return array();
		}

		$private = '';

		openssl_pkey_export( $pair, $private );

		$details = openssl_pkey_get_details( $pair );

		if ( '' === $private || empty( $details['key'] ) ) {
			return array();
		}

		$keys = array(
			'private' => $private,
			'public'  => $details['key'],
		);

		// Never autoloaded: the private key has no business in every request.
		update_option( self::KEYS, $keys, false );

		return $keys;
	}

	/**
	 * Hand out the public half, for baking into a build.
	 *
	 * @return WP_REST_Response
	 */
	public static function pubkey() {
		$keys = self::keypair();

		return new WP_REST_Response(
			array(
				'public_key' => isset( $keys['public'] ) ? $keys['public'] : '',
			),
			200
		);
	}

	/**
	 * Where a key lives, for tuning the lookup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function probe( WP_REST_Request $request ) {
		global $wpdb;

		$key   = trim( (string) $request->get_param( 'key' ) );
		$found = '' === $key ? null : self::find( $key );

		$tables = $wpdb->get_col( "SHOW TABLES LIKE '%slk%'" ); // phpcs:ignore WordPress.DB

		$report = array(
			'key_given'      => '' !== $key,
			'found'          => $found,
			'licence_tables' => $tables,
			'hpos'           => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ), // phpcs:ignore WordPress.DB
			// Whether, not what.
			'passphrase_set' => '' !== self::passphrase(),
			'can_sign'       => '' !== self::sign( array( 'status' => 'probe' ) ),
			'feed'           => self::feed_report(),
		);

		if ( $found && ! empty( $found['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $found['order_id'] );

			if ( $order ) {
				$report['order'] = array(
					'id'     => $order->get_id(),
					'status' => $order->get_status(),
					'date'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
					'items'  => array(),
				);

				foreach ( $order->get_items() as $item ) {
					$report['order']['items'][] = array(
						'name'      => $item->get_name(),
						'meta_keys' => array_map(
							static function ( $meta ) {
								return $meta->key;
							},
							$item->get_meta_data()
						),
					);
				}
			}
		}

		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * How the feed is doing, without printing anybody's key.
	 *
	 * @return array
	 */
	protected static function feed_report() {
		$raw = self::feed();

		return array(
			'url'         => self::feed_url(),
			'reachable'   => null !== $raw,
			'entries'     => null === $raw ? 0 : count( self::encrypted_strings( $raw ) ),
			'decryptable' => null === $raw ? 0 : count( self::feed_keys() ),
		);
	}

	/**
	 * Find the order a key was sold with, or the feed entry that proves it live.
	 *
	 * Four places. The feed first, because it needs no guessing about how this
	 * shop stores keys; then order item meta, where these plugins usually put
	 * it, then HPOS order meta, then the old post meta.
	 *
	 * @param string $key Licence key.
	 * @return array|null {order_id, where, meta_key}
	 */
	public static function find( $key ) {
		global $wpdb;

		/**
		 * Filters the lookup, for a shop that stores keys somewhere else.
		 *
		 * @param array|null $found Where the key is, or null to search here.
		 * @param string     $key   Licence key.
		 */
		$found = apply_filters( 'datachat_license_lookup', null, $key );

		if ( is_array( $found ) && ( ! empty( $found['order_id'] ) || ! empty( $found['where'] ) ) ) {
			return $found;
		}

		$in_feed = self::from_feed( $key );

		if ( $in_feed ) {
			return $in_feed;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT i.order_id, m.meta_key
				 FROM {$wpdb->prefix}woocommerce_order_itemmeta m
				 INNER JOIN {$wpdb->prefix}woocommerce_order_items i ON i.order_item_id = m.order_item_id
				 WHERE m.meta_value = %s LIMIT 1",
				$key
			),
			ARRAY_A
		);

		if ( $row ) {
			return array(
				'order_id' => (int) $row['order_id'],
				'where'    => 'order_itemmeta',
				'meta_key' => $row['meta_key'],
			);
		}

		$hpos = $wpdb->prefix . 'wc_orders_meta';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) === $hpos ) { // phpcs:ignore WordPress.DB
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT order_id, meta_key FROM {$hpos} WHERE meta_value = %s LIMIT 1", $key ),
				ARRAY_A
			);

			if ( $row ) {
				return array(
					'order_id' => (int) $row['order_id'],
					'where'    => 'wc_orders_meta',
					'meta_key' => $row['meta_key'],
				);
			}
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_value = %s LIMIT 1", $key ),
			ARRAY_A
		);

		if ( $row ) {
			return array(
				'order_id' => (int) $row['post_id'],
				'where'    => 'postmeta',
				'meta_key' => $row['meta_key'],
			);
		}

		return null;
	}

	/**
	 * Whether SLKWoo's feed still carries this key.
	 *
	 * @param string $key Licence key.
	 * @return array|null {order_id, where, expires}
	 */
	protected static function from_feed( $key ) {
		// Too short to match on safely: a two-character "key" would collide
		// with half the feed.
		if ( strlen( $key ) < 8 ) {
			return null;
		}

		foreach ( self::feed_keys() as $entry ) {
			if ( ! hash_equals( $entry['key'], $key ) ) {
				continue;
			}

			return array(
				'order_id' => isset( $entry['order_id'] ) ? (int) $entry['order_id'] : 0,
				'where'    => 'slkwoo_feed',
				'expires'  => isset( $entry['expires'] ) ? (string) $entry['expires'] : '',
			);
		}

		return null;
	}

	/**
	 * Every key the feed currently carries, decrypted.
	 *
	 * The feed's shape is not documented, so this does not assume one: it walks
	 * whatever JSON comes back, tries to decrypt every string it finds, and
	 * keeps what comes out legible. A plaintext that is itself JSON is read for
	 * a key, an order id and an expiry; a bare string is the key.
	 *
	 * @return array List of {key, order_id, expires}.
	 */
	protected static function feed_keys() {
		$raw = self::feed();

		if ( null === $raw ) {
			return array();
		}

		$passphrase = self::passphrase();

		if ( '' === $passphrase ) {
			return array();
		}

		$keys = array();

		foreach ( self::encrypted_strings( $raw ) as $candidate ) {
			$plain = self::decrypt( $candidate, $passphrase );

			if ( '' === $plain ) {
				continue;
			}

			$decoded = json_decode( $plain, true );

			if ( is_array( $decoded ) ) {
				$keys = array_merge( $keys, self::entries_from( $decoded ) );

				continue;
			}

			// Some builds pack several fields into one string.
			foreach ( preg_split( '/[|;,\s]+/', $plain ) as $piece ) {
				$piece = trim( (string) $piece );

				if ( strlen( $piece ) >= 8 ) {
					$keys[] = array( 'key' => $piece, 'order_id' => 0, 'expires' => '' );
				}
			}
		}

		return $keys;
	}

	/**
	 * Keys out of a decrypted structure.
	 *
	 * @param array $decoded Decrypted JSON.
	 * @return array
	 */
	protected static function entries_from( array $decoded ) {
		$entries = array();

		// A list of records, or one record.
		$records = isset( $decoded[0] ) && is_array( $decoded[0] ) ? $decoded : array( $decoded );

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$key = '';

			foreach ( array( 'license_key', 'licence_key', 'key', 'code', 'serial' ) as $field ) {
				if ( ! empty( $record[ $field ] ) && is_string( $record[ $field ] ) ) {
					$key = trim( $record[ $field ] );

					break;
				}
			}

			if ( strlen( $key ) < 8 ) {
				continue;
			}

			$expires = '';

			foreach ( array( 'expiry', 'expires', 'expiry_date', 'expires_at' ) as $field ) {
				if ( ! empty( $record[ $field ] ) && is_string( $record[ $field ] ) ) {
					$expires = $record[ $field ];

					break;
				}
			}

			$entries[] = array(
				'key'      => $key,
				'order_id' => isset( $record['order_id'] ) ? (int) $record['order_id'] : 0,
				'expires'  => $expires,
			);
		}

		return $entries;
	}

	/**
	 * Every string in the feed that could be an encrypted key.
	 *
	 * @param mixed $raw Decoded feed.
	 * @return array
	 */
	protected static function encrypted_strings( $raw ) {
		$found = array();

		$walk = static function ( $node ) use ( &$walk, &$found ) {
			if ( is_string( $node ) ) {
				$node = trim( $node );

				// Base64, and long enough to be a block or two of ciphertext.
				if ( strlen( $node ) >= 16 && preg_match( '#^[A-Za-z0-9+/=\r\n]+$#', $node ) ) {
					$found[] = $node;
				}

				return;
			}

			if ( is_array( $node ) ) {
				foreach ( $node as $child ) {
					$walk( $child );
				}
			}
		};

		$walk( $raw );

		return array_values( array_unique( $found ) );
	}

	/**
	 * Undo SLKWoo's encryption.
	 *
	 * The odd fifth argument is deliberate. SLKWoo documents decryption as
	 * openssl_decrypt( $data, 'aes-256-cfb', $pass, 0, openssl_cipher_iv_length( 'aes-256-cfb' ) ),
	 * which passes the integer 16 where an IV belongs; PHP reads it as the
	 * string "16" and pads it with nulls. That is what encrypted the feed, so
	 * that is what has to decrypt it - hence the warning this silences.
	 *
	 * @param string $data       Ciphertext.
	 * @param string $passphrase Shared passphrase.
	 * @return string Plaintext, or empty.
	 */
	protected static function decrypt( $data, $passphrase ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$plain = @openssl_decrypt( $data, self::CIPHER, $passphrase, 0, openssl_cipher_iv_length( self::CIPHER ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! is_string( $plain ) ) {
			return '';
		}

		// Decrypting with the wrong passphrase succeeds and returns rubbish, so
		// anything that is not plain printable text is treated as a miss. Byte
		// by byte, not by Unicode class: rubbish is often invalid UTF-8, and a
		// /u pattern fails rather than not matching on that.
		if ( '' === $plain || ! preg_match( '/^[\x09\x0A\x0D\x20-\x7E]+$/', $plain ) ) {
			return '';
		}

		return trim( $plain );
	}

	/**
	 * The feed, decoded, briefly cached.
	 *
	 * @return mixed|null Decoded body, or null when it cannot be read.
	 */
	protected static function feed() {
		$cached = get_transient( 'datachat_slkwoo_feed' );

		if ( is_array( $cached ) && array_key_exists( 'body', $cached ) ) {
			return $cached['body'];
		}

		$response = wp_remote_get( self::feed_url(), array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cached as a miss too, so a broken feed is not fetched per request.
			set_transient( 'datachat_slkwoo_feed', array( 'body' => null ), 60 );

			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		set_transient( 'datachat_slkwoo_feed', array( 'body' => $decoded ), self::FEED_CACHE );

		return $decoded;
	}

	/**
	 * Where the feed is. On this shop, by default.
	 *
	 * @return string
	 */
	protected static function feed_url() {
		/**
		 * Filters the SLKWoo token feed URL.
		 *
		 * @param string $url Feed URL.
		 */
		return (string) apply_filters( 'datachat_license_feed_url', rest_url( 'rf/slk-woo-open-key_api/token' ) );
	}

	/**
	 * The shared passphrase, from wp-config.php or an option. Never from here.
	 *
	 * @return string
	 */
	protected static function passphrase() {
		$passphrase = defined( 'DATACHAT_SLKWOO_PASSPHRASE' ) ? (string) DATACHAT_SLKWOO_PASSPHRASE : (string) get_option( 'datachat_slkwoo_passphrase', '' );

		/**
		 * Filters the passphrase the feed is decrypted with.
		 *
		 * @param string $passphrase Passphrase.
		 */
		return trim( (string) apply_filters( 'datachat_license_passphrase', $passphrase ) );
	}

	/**
	 * When the licence behind an order runs out, if the shop records that.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	protected static function expiry( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return '';
		}

		foreach ( array( '_slkwoo_expiry', '_license_expiry', '_expiry_date', 'expiry_date' ) as $meta_key ) {
			$value = $order->get_meta( $meta_key );

			if ( $value ) {
				return (string) $value;
			}
		}

		/**
		 * Filters the expiry date of a licence.
		 *
		 * @param string $expires  Date, or empty for a licence that does not expire.
		 * @param int    $order_id Order id.
		 */
		return (string) apply_filters( 'datachat_license_expiry', '', $order_id );
	}

	/**
	 * A cheap cap, so the endpoint cannot be used to grind through keys.
	 *
	 * @return bool
	 */
	protected static function allowed() {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$slot    = 'datachat_lic_' . md5( $address );
		$hits    = (int) get_transient( $slot );

		if ( $hits >= 60 ) {
			return false;
		}

		set_transient( $slot, $hits + 1, HOUR_IN_SECONDS );

		return true;
	}
}

DataChat_Licence_Endpoint::init();

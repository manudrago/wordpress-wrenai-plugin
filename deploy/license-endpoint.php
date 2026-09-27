<?php
/**
 * Plugin Name:       DataChat Licence Endpoint
 * Description:       Answers "is this licence key valid for this site?" for the DataChat AI paid editions. Install this on the shop that sells them, not on a customer's site.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Emanuel Draghetti
 * License:           GPL-2.0-or-later
 *
 * Why this exists
 * ---------------
 * Simple License Key for WooCommerce publishes newly issued keys, encrypted,
 * at /wp-json/rf/slk-woo-open-key_api/token. That is a feed of keys, not a way
 * to ask about one: it holds only what has not expired yet, and reading it
 * needs a passphrase. Shipping that passphrase inside a GPL plugin would hand
 * every customer the means to read every other customer's key, and a licence
 * issued last year would not be in the feed at all.
 *
 * So the shop answers the question instead. The customer's site sends a key
 * and a domain; this looks the key up in the order it was sold with, checks
 * that order is paid and not refunded, and answers valid or invalid. The keys
 * never leave this server.
 *
 * @package DataChat_Licence
 */

defined( 'ABSPATH' ) || exit;

/**
 * Looks a licence key up in the WooCommerce orders that carry it.
 */
class DataChat_Licence_Endpoint {

	const NAMESPACE_V1 = 'datachat/v1';

	/**
	 * Order statuses that count as paid.
	 *
	 * @var array
	 */
	const PAID = array( 'completed', 'processing' );

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
				),
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
		$key = trim( (string) ( $request->get_param( 'license_key' ) ? $request->get_param( 'license_key' ) : $request->get_param( 'key' ) ) );

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
			return new WP_REST_Response( array( 'status' => 'invalid', 'message' => 'No licence key given.' ), 200 );
		}

		$found = self::find( $key );

		if ( ! $found ) {
			return new WP_REST_Response( array( 'status' => 'invalid', 'message' => 'Unknown licence key.' ), 200 );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $found['order_id'] ) : null;

		if ( ! $order ) {
			return new WP_REST_Response( array( 'status' => 'invalid', 'message' => 'The order behind this key no longer exists.' ), 200 );
		}

		$status = $order->get_status();

		if ( ! in_array( $status, self::PAID, true ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'invalid',
					'message' => sprintf( 'The order behind this key is %s.', $status ),
				),
				200
			);
		}

		$expires = self::expiry( $found['order_id'] );

		if ( $expires && strtotime( $expires ) < time() ) {
			return new WP_REST_Response(
				array( 'status' => 'expired', 'expires' => $expires, 'message' => 'This licence has expired.' ),
				200
			);
		}

		/**
		 * Filters the answer, for a shop with rules of its own - a seat count,
		 * a domain allow-list, a grace period after a refund.
		 *
		 * @param array  $answer  Status, expiry and message.
		 * @param string $key     Licence key.
		 * @param array  $found   Where it was found.
		 * @param string $domain  Domain asking.
		 */
		$answer = apply_filters(
			'datachat_license_answer',
			array(
				'status'  => 'valid',
				'expires' => $expires,
				'message' => '',
			),
			$key,
			$found,
			(string) $request->get_param( 'domain' )
		);

		return new WP_REST_Response( $answer, 200 );
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
			'key_given'       => '' !== $key,
			'found'           => $found,
			'licence_tables'  => $tables,
			'hpos'            => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ), // phpcs:ignore WordPress.DB
		);

		if ( $found && function_exists( 'wc_get_order' ) ) {
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
	 * Find the order a key was sold with.
	 *
	 * Three places, because WooCommerce has moved twice: order item meta is
	 * where these plugins usually put it, then HPOS order meta, then the old
	 * post meta.
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

		if ( is_array( $found ) && ! empty( $found['order_id'] ) ) {
			return $found;
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

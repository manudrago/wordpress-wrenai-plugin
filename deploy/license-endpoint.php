<?php
/**
 * Plugin Name:       DataChat Licence Endpoint
 * Description:       Answers "is this licence key valid for this site?" for the DataChat AI paid editions, counts one site per licence, and signs the answer. Install this on the shop that sells them, not on a customer's site.
 * Version:           1.7.0
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
 * It also keeps this shop's own licence keys out of that public feed, once
 * DATACHAT_PRODUCT_IDS names the products. Publishing them buys nothing when the
 * keys are checked here, and costs a seat: anyone watching the feed could claim
 * a site before the customer has installed anything. Other products are left
 * alone, so a plugin that still reads the feed for its own keys carries on
 * working and its customers notice nothing.
 *
 * One licence covers one site. That too can only be counted here, because only
 * the shop sees every site a key is used on: each answer records the domain
 * that asked, a second domain is refused and told which site to free, and
 * /license/release gives a seat back so a customer can move without writing in.
 * A site that stops checking in for sixty days gives its seat back by itself.
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
	 * Where the sites each key is in use on are recorded.
	 */
	const SITES = 'datachat_licence_sites';

	/**
	 * How many sites one key runs on, unless a shop says otherwise.
	 */
	const SEATS = 1;

	/**
	 * How long a site can go unseen before its seat is given back.
	 *
	 * A customer who abandons a site without deactivating would otherwise hold
	 * a seat forever and have to write in. The plugin re-checks daily, so sixty
	 * days of silence means the site is gone.
	 */
	const STALE = 60 * DAY_IN_SECONDS;

	/**
	 * Model calls a Pro licence gets each calendar month, unless the shop says
	 * otherwise. One question is two calls - the SQL, then the chart - so this
	 * is three hundred questions.
	 */
	const AI_CALLS = 600;

	/**
	 * The model Pro questions go to, unless the shop picks another.
	 */
	const AI_MODEL = 'gpt-4.1-mini';

	/**
	 * Calls one licence may make in a minute. A person asking questions makes a
	 * handful; a script burning the allowance makes hundreds.
	 */
	const AI_BURST = 20;

	/**
	 * Where the monthly count per licence is kept.
	 */
	const AI_USAGE = 'datachat_ai_usage';

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
	 * True only while this class is reading the feed for itself.
	 *
	 * @var bool
	 */
	protected static $own_read = false;

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'hide_own_keys' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_datachat_licence_settings', array( __CLASS__, 'save_settings' ) );
	}

	/**
	 * A page under Tools, because the REST probe cannot be opened in a browser.
	 *
	 * WordPress drops cookie authentication on a REST request that carries no
	 * nonce - rest_cookie_check_errors() calls wp_set_current_user( 0 ) - so
	 * pasting the probe URL into the address bar answers 401 however logged in
	 * you are. An admin page has the nonce by being an admin page.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page(
			'DataChat licences',
			'DataChat licences',
			'manage_options',
			'datachat-licence',
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Print the report, and the public key to build with.
	 *
	 * @return void
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access required.', 'default' ) );
		}

		$key = '';

		if ( isset( $_POST['datachat_probe_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'datachat_probe' );

			$key = sanitize_text_field( wp_unslash( $_POST['datachat_probe_key'] ) );
		}

		$report = self::report( $key );
		$keys   = self::keypair();

		echo '<div class="wrap">';
		echo '<h1>DataChat licences</h1>';

		echo '<p>Paste a licence key this shop has already sold - any product will do - to see where it is stored and whether the feed can be read. No key is printed back.</p>';

		echo '<form method="post">';
		wp_nonce_field( 'datachat_probe' );
		printf(
			'<p><input type="text" name="datachat_probe_key" class="regular-text code" value="%s" autocomplete="off" placeholder="XXXX-XXXX-XXXX"> <button class="button button-primary">Look it up</button></p>',
			esc_attr( $key )
		);
		echo '</form>';

		self::settings_form();

		echo '<h2>Report</h2>';
		echo '<textarea readonly rows="22" style="width:100%;font-family:monospace;font-size:12px">';
		echo esc_textarea( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</textarea>';

		echo '<h2>Public key</h2>';
		echo '<p>This is what the paid builds are compiled with. Public on purpose: it only checks a signature, it cannot make one.</p>';
		echo '<textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px">';
		echo esc_textarea( isset( $keys['public'] ) ? $keys['public'] : '' );
		echo '</textarea>';

		echo '</div>';
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

		// Gives a site's seat back, so a customer can move the licence without
		// writing in. Public like the check above: the key is the secret.
		register_rest_route(
			self::NAMESPACE_V1,
			'/license/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'release' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'license_key' => array( 'type' => 'string' ),
					'key'         => array( 'type' => 'string' ),
					'domain'      => array( 'type' => 'string' ),
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

		// The model that comes with Pro, relayed with this shop's API key. It
		// speaks the OpenAI chat-completions shape, so the plugin talks to it
		// exactly as it talks to any provider. The licence is the credential.
		register_rest_route(
			self::NAMESPACE_V1,
			'/ai/chat/completions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ai_chat' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/ai/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ai_models' ),
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

		list( $verdict, $found ) = self::verdict( $key );

		if ( 'valid' === $verdict['status'] ) {
			return self::answer( $verdict, $domain, $nonce, $key, $found );
		}

		return self::answer( $verdict, $domain, $nonce );
	}

	/**
	 * Whether a key is good, before any question of which site holds it.
	 *
	 * @param string $key Licence key.
	 * @return array Two items: the answer so far, and where the key was found.
	 */
	protected static function verdict( $key ) {
		$found = self::find( $key );

		if ( ! $found ) {
			return array( array( 'status' => 'invalid', 'message' => 'Unknown licence key.' ), array() );
		}

		// A key of ours that the feed also carries: its order is what says how
		// long it runs and whose it is, so look that up rather than trust the
		// feed's own date.
		if ( empty( $found['order_id'] ) && in_array( self::product_of( $found ), self::own_products(), true ) ) {
			$in_orders = self::find_in_orders( $key );

			if ( $in_orders ) {
				$found = array_merge( $in_orders, array_filter( array( 'product_id' => isset( $found['product_id'] ) ? $found['product_id'] : '' ) ) );
			}
		}

		// A key still in SLKWoo's feed is a live key: the feed drops them when
		// they expire. There may be no order to look at, so this stands alone.
		if ( empty( $found['order_id'] ) ) {
			return array(
				array(
					'status'  => 'valid',
					'expires' => isset( $found['expires'] ) ? (string) $found['expires'] : '',
				),
				$found,
			);
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $found['order_id'] ) : null;

		if ( ! $order ) {
			return array( array( 'status' => 'invalid', 'message' => 'The order behind this key no longer exists.' ), $found );
		}

		$status = $order->get_status();

		if ( ! in_array( $status, self::PAID, true ) ) {
			return array(
				array(
					'status'  => 'invalid',
					'message' => sprintf( 'The order behind this key is %s.', $status ),
				),
				$found,
			);
		}

		$licence = self::renewal_state( $found, $order );

		if ( $licence ) {
			$found   = array_merge( $found, $licence );
			$expires = $licence['expires'];
		} else {
			$expires = self::expiry( $found['order_id'] );
		}

		if ( $expires && strtotime( $expires ) < time() ) {
			return array(
				array( 'status' => 'expired', 'expires' => $expires, 'message' => 'This licence has expired.' ),
				$found,
			);
		}

		return array( array( 'status' => 'valid', 'expires' => $expires ), $found );
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

		// One licence, one site. Counted here, because here is the only place
		// that can see every site a key is used on.
		if ( 'valid' === $answer['status'] && '' !== $key ) {
			$answer = self::gate_seats( $answer, $key, $domain, $found );
		}

		/**
		 * Filters the answer, for a shop with rules of its own - a domain
		 * allow-list, a grace period after a refund, a seat decision of its own.
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
	 * Let this site have the licence, or say who already has it.
	 *
	 * @param array  $answer Answer so far, known valid.
	 * @param string $key    Licence key.
	 * @param string $domain Domain asking.
	 * @param array  $found  Where the key was found.
	 * @return array
	 */
	protected static function gate_seats( array $answer, $key, $domain, array $found ) {
		$site = self::normalise( $domain );

		// Nothing to count against. Allowing it is the lesser evil: refusing
		// would lock out a site whose home_url() this cannot parse.
		if ( '' === $site ) {
			return $answer;
		}

		$seats = self::seats_for( $key, $found );
		$all   = self::sites();
		$slot  = self::slot_of( $key, $found );
		$taken = isset( $all[ $slot ] ) && is_array( $all[ $slot ] ) ? $all[ $slot ] : array();
		$now   = time();

		// A site that has not checked in for long enough has gone away.
		foreach ( $taken as $known => $seen ) {
			if ( ! is_array( $seen ) || ( $now - (int) ( isset( $seen['last'] ) ? $seen['last'] : 0 ) ) > self::STALE ) {
				unset( $taken[ $known ] );
			}
		}

		$known = isset( $taken[ $site ] );

		if ( ! $known && count( $taken ) >= $seats ) {
			$answer['status']  = 'invalid';
			$answer['expires'] = '';
			$answer['message'] = sprintf(
				/* One site, named, so the customer knows what to turn off. */
				'This licence is for %1$d site and is already in use on %2$s. Remove it there first - the licence screen has a button for that - or ask us to free it.',
				$seats,
				implode( ', ', array_keys( $taken ) )
			);
			$answer['seats'] = array(
				'limit' => $seats,
				'used'  => count( $taken ),
				'sites' => array_keys( $taken ),
			);

			// Whatever was there stays there; this site simply does not join.
			self::remember_sites( $slot, $taken );

			return $answer;
		}

		$taken[ $site ] = array(
			'first' => $known && isset( $taken[ $site ]['first'] ) ? (int) $taken[ $site ]['first'] : $now,
			'last'  => $now,
		);

		self::remember_sites( $slot, $taken );

		$answer['seats'] = array(
			'limit' => $seats,
			'used'  => count( $taken ),
			'sites' => array_keys( $taken ),
		);

		return $answer;
	}

	/**
	 * Give a site's seat back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function release( WP_REST_Request $request ) {
		if ( ! self::allowed() ) {
			return new WP_REST_Response( array( 'released' => false, 'message' => 'Too many requests from this address.' ), 429 );
		}

		$key  = trim( (string) ( $request->get_param( 'license_key' ) ? $request->get_param( 'license_key' ) : $request->get_param( 'key' ) ) );
		$site = self::normalise( (string) $request->get_param( 'domain' ) );

		if ( '' === $key || '' === $site ) {
			return new WP_REST_Response( array( 'released' => false, 'message' => 'A key and a domain are both needed.' ), 200 );
		}

		$all  = self::sites();
		$slot = self::slot_for_key( $key );

		if ( ! isset( $all[ $slot ][ $site ] ) ) {
			// Nothing held, which is the state the caller wanted anyway.
			return new WP_REST_Response( array( 'released' => true ), 200 );
		}

		$taken = $all[ $slot ];

		unset( $taken[ $site ] );

		self::remember_sites( $slot, $taken );

		return new WP_REST_Response( array( 'released' => true, 'used' => count( $taken ) ), 200 );
	}

	/**
	 * How many sites this key covers.
	 *
	 * @param string $key   Licence key.
	 * @param array  $found Where it was found.
	 * @return int
	 */
	protected static function seats_for( $key, array $found ) {
		/**
		 * Filters the number of sites one key runs on - by product, say, so an
		 * Agency licence covers more than one.
		 *
		 * @param int    $seats Seats.
		 * @param string $key   Licence key.
		 * @param array  $found Where the key was found, product_id included.
		 */
		$base  = ! empty( $found['seats'] ) ? (int) $found['seats'] : self::product_sites( self::product_of( $found ) );
		$seats = (int) apply_filters( 'datachat_license_seats', $base, $key, $found );

		return $seats > 0 ? $seats : 1;
	}

	/**
	 * Every key's sites. Keys are stored hashed: this option is not a list of
	 * licence keys, and a leak of it hands nobody a working one.
	 *
	 * @return array
	 */
	protected static function sites() {
		$stored = get_option( self::SITES, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write one key's sites back.
	 *
	 * @param string $slot  Hashed key.
	 * @param array  $taken Sites.
	 * @return void
	 */
	protected static function remember_sites( $slot, array $taken ) {
		$all = self::sites();

		if ( empty( $taken ) ) {
			unset( $all[ $slot ] );
		} else {
			$all[ $slot ] = $taken;
		}

		update_option( self::SITES, $all, false );
	}

	/**
	 * A domain in the one form everything here compares against.
	 *
	 * @param string $domain Domain, or a URL.
	 * @return string
	 */
	protected static function normalise( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );

		if ( '' === $domain ) {
			return '';
		}

		// A URL where a domain was expected.
		if ( false !== strpos( $domain, '//' ) ) {
			$host   = wp_parse_url( $domain, PHP_URL_HOST );
			$domain = is_string( $host ) ? $host : '';
		}

		$domain = preg_replace( '#[/?].*$#', '', $domain );
		$domain = preg_replace( '#:\d+$#', '', (string) $domain );
		$domain = preg_replace( '#^www\.#', '', (string) $domain );

		return (string) $domain;
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
		return new WP_REST_Response( self::report( (string) $request->get_param( 'key' ) ), 200 );
	}

	/**
	 * What this shop looks like from in here: where a key was found, whether
	 * the feed can be read, what is being hidden from it, and which sites hold
	 * the licence. No key is ever printed.
	 *
	 * @param string $key Licence key to look up, or empty for the rest.
	 * @return array
	 */
	public static function report( $key = '' ) {
		global $wpdb;

		$key   = trim( (string) $key );
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
			'hidden_products' => self::own_products(),
			'seats'          => '' === $key ? null : array(
				'limit' => self::seats_for( $key, is_array( $found ) ? $found : array() ),
				'sites' => array_keys( (array) ( self::sites()[ self::slot_of( $key, is_array( $found ) ? $found : array() ) ] ?? array() ) ),
			),
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

		return $report;
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

		return self::find_in_orders( $key );
	}

	/**
	 * Find the order a key was sold with, ignoring the feed.
	 *
	 * @param string $key Licence key.
	 * @return array|null {order_id, where, meta_key, item_id}
	 */
	protected static function find_in_orders( $key ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT i.order_id, i.order_item_id, m.meta_key
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
				'item_id'  => isset( $row['order_item_id'] ) ? (int) $row['order_item_id'] : 0,
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
				'order_id'   => isset( $entry['order_id'] ) ? (int) $entry['order_id'] : 0,
				'where'      => 'slkwoo_feed',
				'expires'    => isset( $entry['expires'] ) ? (string) $entry['expires'] : '',
				'product_id' => isset( $entry['product_id'] ) ? (string) $entry['product_id'] : '',
			);
		}

		return null;
	}

	/**
	 * Every key the feed currently carries, decrypted.
	 *
	 * SLKWoo publishes a flat list of {product_id, open_key, date_expiry,
	 * expiry_stamp}, where open_key is the key itself and the rest is in clear.
	 * That shape is read first, because it is the one that also yields the
	 * expiry and the product without guessing.
	 *
	 * Anything else falls back to walking whatever JSON arrived and trying to
	 * decrypt every string in it, which is what this did before the shape was
	 * known - a feed that changes shape then still works, with less detail.
	 *
	 * @return array List of {key, order_id, expires, product_id}.
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

		$keys = self::slkwoo_entries( $raw, $passphrase );

		if ( ! empty( $keys ) ) {
			return $keys;
		}

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
	 * Keys out of SLKWoo's own shape.
	 *
	 * @param mixed  $raw        Decoded feed.
	 * @param string $passphrase Shared passphrase.
	 * @return array
	 */
	protected static function slkwoo_entries( $raw, $passphrase ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Either the list itself, or a list under a wrapper.
		$list = $raw;

		if ( ! isset( $raw[0] ) ) {
			foreach ( array( 'data', 'tokens', 'keys', 'result' ) as $wrapper ) {
				if ( isset( $raw[ $wrapper ] ) && is_array( $raw[ $wrapper ] ) ) {
					$list = $raw[ $wrapper ];

					break;
				}
			}
		}

		$keys = array();

		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['open_key'] ) || ! is_string( $entry['open_key'] ) ) {
				continue;
			}

			$plain = self::decrypt( $entry['open_key'], $passphrase );

			if ( '' === $plain ) {
				continue;
			}

			$expires = '';

			if ( ! empty( $entry['date_expiry'] ) && is_string( $entry['date_expiry'] ) ) {
				$expires = $entry['date_expiry'];
			} elseif ( ! empty( $entry['expiry_stamp'] ) && is_numeric( $entry['expiry_stamp'] ) ) {
				$expires = gmdate( 'Y-m-d', (int) $entry['expiry_stamp'] );
			}

			$keys[] = array(
				'key'        => $plain,
				'order_id'   => 0,
				'expires'    => $expires,
				'product_id' => isset( $entry['product_id'] ) ? (string) $entry['product_id'] : '',
			);
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
	 * The odd IV is deliberate. SLKWoo documents decryption as
	 * openssl_decrypt( $data, 'aes-256-cfb', $pass, 0, openssl_cipher_iv_length( 'aes-256-cfb' ) ),
	 * which passes the integer 16 where an IV belongs; PHP reads it as the
	 * string "16" and pads it with nulls to length. That is what encrypted the
	 * feed, so that is what has to decrypt it.
	 *
	 * @param string $data       Ciphertext.
	 * @param string $passphrase Shared passphrase.
	 * @return string Plaintext, or empty.
	 */
	protected static function decrypt( $data, $passphrase ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// Base64 in, raw out, with the IV spelled out rather than left to PHP's
		// cast of the integer: the two are byte for byte the same thing, and
		// this form is the one proven against the live feed.
		$length = openssl_cipher_iv_length( self::CIPHER );
		$plain  = openssl_decrypt( (string) base64_decode( $data ), self::CIPHER, $passphrase, OPENSSL_RAW_DATA, str_pad( (string) $length, $length, "\0" ) );

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

		$decoded = self::read_feed();

		// A miss is cached too, briefly, so a broken feed is not fetched again
		// on every request.
		set_transient( 'datachat_slkwoo_feed', array( 'body' => $decoded ), null === $decoded ? 60 : self::FEED_CACHE );

		return $decoded;
	}

	/**
	 * Fetch the feed, from inside this WordPress when it lives here.
	 *
	 * An in-process dispatch rather than an HTTP request to ourselves: it is
	 * faster, it works on hosts that refuse loopback connections, and - since
	 * hide_own_keys() strips this shop's own products from what the route
	 * serves to the outside - it is the only way left to see them. The flag it
	 * sets cannot be set by a caller, because it is not a header or a parameter:
	 * it is a static property of this class, set for the length of one call.
	 *
	 * @return mixed|null Decoded feed, or null.
	 */
	protected static function read_feed() {
		if ( self::feed_is_ours() && function_exists( 'rest_do_request' ) ) {
			self::$own_read = true;

			$response = rest_do_request( new WP_REST_Request( 'GET', '/' . ltrim( self::feed_route(), '/' ) ) );

			self::$own_read = false;

			if ( ! $response || $response->is_error() ) {
				return null;
			}

			return $response->get_data();
		}

		$response = wp_remote_get( self::feed_url(), array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return json_decode( (string) wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Whether the feed is served by this same WordPress.
	 *
	 * @return bool
	 */
	protected static function feed_is_ours() {
		$feed = wp_parse_url( self::feed_url(), PHP_URL_HOST );
		$here = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $feed ) && is_string( $here ) && strtolower( $feed ) === strtolower( $here );
	}

	/**
	 * The REST route SLKWoo publishes its keys at.
	 *
	 * @return string
	 */
	protected static function feed_route() {
		/**
		 * Filters the SLKWoo token feed route.
		 *
		 * @param string $route Route, without the /wp-json prefix.
		 */
		return (string) apply_filters( 'datachat_license_feed_route', 'rf/slk-woo-open-key_api/token' );
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
		return (string) apply_filters( 'datachat_license_feed_url', rest_url( self::feed_route() ) );
	}

	/**
	 * Keep this shop's own licence keys out of the public feed.
	 *
	 * SLKWoo publishes newly issued keys, encrypted, to anyone who asks, and the
	 * passphrase is shared by every plugin that reads it - so a key in that feed
	 * is a key somebody else can decrypt. For a product whose licences are
	 * checked here instead, publishing them buys nothing and costs a seat: a
	 * watcher can claim the site before the customer has installed anything.
	 *
	 * So those entries are removed on the way out. Other products are untouched,
	 * which is the point: a plugin that still reads this feed for its own keys
	 * carries on working, and its customers notice nothing.
	 *
	 * Nothing is hidden until DATACHAT_PRODUCT_IDS says which products to hide.
	 *
	 * @param mixed           $response Response object.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function hide_own_keys( $response, $server = null, $request = null ) {
		// Our own in-process read, which has to see everything.
		if ( self::$own_read ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request || ! $response ) {
			return $response;
		}

		$route = (string) $request->get_route();

		if ( '/' . ltrim( self::feed_route(), '/' ) !== $route ) {
			return $response;
		}

		$products = self::own_products();

		if ( empty( $products ) ) {
			return $response;
		}

		$response->set_data( self::without_products( $response->get_data(), $products ) );

		return $response;
	}

	/**
	 * The same feed, without the entries for these products.
	 *
	 * @param mixed $data     Feed data.
	 * @param array $products Product ids to drop.
	 * @return mixed
	 */
	protected static function without_products( $data, array $products ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// A wrapper around the list, rather than the list itself.
		if ( ! isset( $data[0] ) ) {
			foreach ( array( 'data', 'tokens', 'keys', 'result' ) as $wrapper ) {
				if ( isset( $data[ $wrapper ] ) && is_array( $data[ $wrapper ] ) ) {
					$data[ $wrapper ] = self::without_products( $data[ $wrapper ], $products );

					return $data;
				}
			}

			return $data;
		}

		$kept = array();

		foreach ( $data as $entry ) {
			if ( is_array( $entry ) && isset( $entry['product_id'] ) && in_array( (string) $entry['product_id'], $products, true ) ) {
				continue;
			}

			$kept[] = $entry;
		}

		return array_values( $kept );
	}

	/**
	 * The shop's own products, whose keys are checked here rather than read
	 * from the feed by the plugin itself.
	 *
	 * @return array List of ids, as strings.
	 */
	protected static function own_products() {
		$products = defined( 'DATACHAT_PRODUCT_IDS' ) ? (string) DATACHAT_PRODUCT_IDS : (string) get_option( 'datachat_product_ids', '' );

		/**
		 * Filters which products are kept out of the public feed.
		 *
		 * @param string $products Comma-separated product ids.
		 */
		$products = (string) apply_filters( 'datachat_license_own_products', $products );

		if ( '' === trim( $products ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $products ) ) ) );
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

	// -----------------------------------------------------------------------
	// Settings: which products are DataChat, which include the model, and the
	// model itself. On the Tools page, so nothing has to go in wp-config.php.
	// -----------------------------------------------------------------------

	/**
	 * Print the settings form and this month's use.
	 *
	 * @return void
	 */
	protected static function settings_form() {
		$saved = isset( $_GET['datachat_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<h2>Settings</h2>';

		if ( $saved ) {
			echo '<div class="notice notice-success inline"><p>Saved.</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="datachat_licence_settings">';
		wp_nonce_field( 'datachat_licence_settings' );
		echo '<table class="form-table" role="presentation">';

		$locked = defined( 'DATACHAT_PRODUCT_IDS' );
		printf(
			'<tr><th scope="row"><label for="dc-products">DataChat products</label></th><td><input id="dc-products" name="datachat_product_ids" type="text" class="regular-text code" value="%s" %s><p class="description">Product ids of every paid DataChat edition, comma-separated. Their keys are checked here and kept out of the public SLKWoo feed; other products (Qomon) are left alone.%s</p></td></tr>',
			esc_attr( implode( ',', self::own_products() ) ),
			$locked ? 'readonly' : '',
			$locked ? ' Set in wp-config.php.' : ''
		);

		printf(
			'<tr><th scope="row"><label for="dc-ai-products">Products with AI included</label></th><td><input id="dc-ai-products" name="datachat_ai_product_ids" type="text" class="regular-text code" value="%s"><p class="description">The editions whose licence can ask this shop\'s model (Pro). A licence for any other product brings its own key.</p></td></tr>',
			esc_attr( implode( ',', self::ai_products() ) )
		);

		printf(
			'<tr><th scope="row"><label for="dc-sites">Sites per licence</label></th><td><input id="dc-sites" name="datachat_product_sites" type="text" class="regular-text code" value="%s" placeholder="5764:10"><p class="description">product:sites, comma-separated, for editions that cover more than one site. Anything not listed covers one.</p></td></tr>',
			esc_attr( (string) get_option( 'datachat_product_sites', '' ) )
		);

		$has_key = '' !== self::openai_key();
		printf(
			'<tr><th scope="row"><label for="dc-openai">OpenAI API key</label></th><td><input id="dc-openai" name="datachat_openai_api_key" type="password" class="regular-text code" value="" autocomplete="new-password" placeholder="%s" %s><p class="description">%s</p></td></tr>',
			$has_key ? esc_attr( 'Saved - leave empty to keep it' ) : 'sk-...',
			defined( 'DATACHAT_OPENAI_API_KEY' ) ? 'readonly' : '',
			defined( 'DATACHAT_OPENAI_API_KEY' ) ? 'Set in wp-config.php.' : ( $has_key ? 'A key is saved. It is never shown again; type a new one to replace it.' : 'Pro questions are answered with this key. It stays on this server and is never sent to a customer.' )
		);

		printf(
			'<tr><th scope="row"><label for="dc-model">Model</label></th><td><input id="dc-model" name="datachat_ai_model" type="text" class="regular-text code" value="%s" placeholder="%s"><p class="description">Whatever a customer\'s site asks for, this is the model that answers.</p></td></tr>',
			esc_attr( (string) get_option( 'datachat_ai_model', '' ) ),
			esc_attr( self::AI_MODEL )
		);

		printf(
			'<tr><th scope="row"><label for="dc-calls">Model calls per licence per month</label></th><td><input id="dc-calls" name="datachat_ai_monthly_calls" type="number" min="0" step="10" class="small-text" value="%d"><p class="description">A question is two calls, so %d is about %d questions.</p></td></tr>',
			(int) self::ai_allowance(),
			(int) self::ai_allowance(),
			(int) floor( self::ai_allowance() / 2 )
		);

		echo '</table>';
		submit_button( 'Save settings' );
		echo '</form>';

		$usage = self::ai_usage();
		$month = gmdate( 'Y-m' );

		echo '<h2>AI use this month</h2>';

		$rows = array();

		foreach ( $usage as $slot => $use ) {
			if ( isset( $use['month'] ) && $month === $use['month'] ) {
				$rows[] = sprintf(
					'<tr><td><code>%s…</code></td><td>%s</td><td>%d / %d</td></tr>',
					esc_html( substr( (string) $slot, 0, 10 ) ),
					esc_html( isset( $use['site'] ) ? (string) $use['site'] : '' ),
					(int) $use['calls'],
					(int) self::ai_allowance()
				);
			}
		}

		if ( $rows ) {
			echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Licence (hashed)</th><th>Site</th><th>Calls</th></tr></thead><tbody>' . implode( '', $rows ) . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo '<p>No questions yet this month.</p>';
		}
	}

	/**
	 * Store the settings.
	 *
	 * @return void
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access required.', 'default' ) );
		}

		check_admin_referer( 'datachat_licence_settings' );

		$ids = static function ( $field ) {
			$raw = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

			return implode( ',', array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
		};

		if ( ! defined( 'DATACHAT_PRODUCT_IDS' ) ) {
			update_option( 'datachat_product_ids', $ids( 'datachat_product_ids' ), false );
		}

		update_option( 'datachat_ai_product_ids', $ids( 'datachat_ai_product_ids' ), false );

		$sites_raw = isset( $_POST['datachat_product_sites'] ) ? sanitize_text_field( wp_unslash( $_POST['datachat_product_sites'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sites     = array();

		foreach ( array_filter( array_map( 'trim', explode( ',', $sites_raw ) ) ) as $pair ) {
			$parts = array_map( 'absint', explode( ':', $pair ) );

			if ( 2 === count( $parts ) && $parts[0] && $parts[1] ) {
				$sites[] = $parts[0] . ':' . $parts[1];
			}
		}

		update_option( 'datachat_product_sites', implode( ',', $sites ), false );

		$key = isset( $_POST['datachat_openai_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['datachat_openai_api_key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' !== $key && ! defined( 'DATACHAT_OPENAI_API_KEY' ) ) {
			update_option( 'datachat_openai_api_key', $key, false );
		}

		$model = isset( $_POST['datachat_ai_model'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['datachat_ai_model'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		update_option( 'datachat_ai_model', $model, false );

		if ( isset( $_POST['datachat_ai_monthly_calls'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			update_option( 'datachat_ai_monthly_calls', absint( $_POST['datachat_ai_monthly_calls'] ), false ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'datachat-licence', 'datachat_saved' => 1 ), admin_url( 'tools.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// The model included with Pro.
	// -----------------------------------------------------------------------

	/**
	 * Relay one chat-completions request to OpenAI for a Pro licence.
	 *
	 * The customer's site never sees this shop's API key, and this shop never
	 * sees the customer's data beyond what the question itself carries - the
	 * schema and a few sample rows. Nothing of it is stored here: only a count.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function ai_chat( WP_REST_Request $request ) {
		$key  = self::bearer( $request );
		$site = self::normalise( (string) $request->get_header( 'x_datachat_site' ) );
		$gate = self::ai_gate( $key, $site );

		if ( true !== $gate ) {
			return $gate;
		}

		$slot = self::ai_slot( $key );

		if ( ! self::ai_burst_ok( $slot ) ) {
			return self::ai_error( 429, 'Too many questions in a minute. Wait a moment and ask again.', 'rate_limit' );
		}

		$used = self::ai_used( $slot );

		if ( $used >= self::ai_allowance() ) {
			return self::ai_error(
				402,
				sprintf(
					'Your licence\'s %d questions for this month are used up. They renew on the 1st; to keep going now, choose another provider under DataChat → Settings and paste your own API key.',
					(int) floor( self::ai_allowance() / 2 )
				),
				'quota_exceeded'
			);
		}

		$body = self::ai_body( (array) $request->get_json_params() );

		if ( empty( $body['messages'] ) ) {
			return self::ai_error( 400, 'No messages to answer.', 'invalid_request' );
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::openai_key(),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::ai_error( 502, 'The AI service could not be reached. Try again in a moment.', 'upstream_unreachable' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( 401 === $code || 403 === $code ) {
			// The shop's own key was refused. That is ours to fix, and saying
			// "your key was refused" to a customer who typed no key would only
			// send them looking in the wrong place.
			return self::ai_error( 503, 'The AI included with your licence is temporarily unavailable. We have been notified; you can use your own key meanwhile.', 'upstream_auth' );
		}

		if ( $code >= 200 && $code < 300 ) {
			self::ai_count( $slot, $site );

			// Which model answered is the shop's business.
			$data['model'] = 'included';
		}

		// Anything else - a parameter this model will not take, a context too
		// long - goes back as the provider worded it, because the plugin
		// already knows how to adapt to those.
		return new WP_REST_Response( $data, $code ? $code : 502 );
	}

	/**
	 * The one model there is, for a site that asks what it can use.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function ai_models( WP_REST_Request $request ) {
		unset( $request );

		return new WP_REST_Response(
			array(
				'object' => 'list',
				'data'   => array( array( 'id' => 'included', 'object' => 'model' ) ),
			),
			200
		);
	}

	/**
	 * Whether this key, on this site, may ask the model. True, or the refusal.
	 *
	 * @param string $key  Licence key.
	 * @param string $site Normalised domain asking.
	 * @return true|WP_REST_Response
	 */
	protected static function ai_gate( $key, $site ) {
		if ( '' === $key ) {
			return self::ai_error( 401, 'No licence key was sent. Enter your key under DataChat → Settings → Licence.', 'no_licence' );
		}

		if ( '' === self::openai_key() ) {
			return self::ai_error( 503, 'The AI included with Pro is not switched on yet. Use your own key under DataChat → Settings meanwhile.', 'not_configured' );
		}

		$cache  = 'datachat_ai_ok_' . hash( 'sha256', $key );
		$cached = get_transient( $cache );

		if ( ! is_array( $cached ) ) {
			if ( ! self::allowed() ) {
				return self::ai_error( 429, 'Too many requests from this address. Try again later.', 'rate_limit' );
			}

			list( $verdict, $found ) = self::verdict( $key );

			$cached = array(
				'status'  => (string) $verdict['status'],
				'message' => isset( $verdict['message'] ) ? (string) $verdict['message'] : '',
				'product' => $found ? self::product_of( $found ) : '',
				'slot'    => self::slot_of( $key, $found ? $found : array() ),
			);

			// Ten minutes: long enough that a question does not cost a feed
			// decryption per call, short enough that a refund bites quickly.
			set_transient( $cache, $cached, 10 * MINUTE_IN_SECONDS );
		}

		$slot = isset( $cached['slot'] ) ? (string) $cached['slot'] : hash( 'sha256', $key );

		if ( 'valid' !== $cached['status'] ) {
			return self::ai_error( 403, '' !== $cached['message'] ? $cached['message'] : 'This licence is not valid.', 'invalid_licence' );
		}

		if ( ! in_array( (string) $cached['product'], self::ai_products(), true ) ) {
			return self::ai_error( 403, 'This licence does not include the AI service - it is the edition that brings its own key. Choose a provider under DataChat → Settings and paste your API key.', 'not_included' );
		}

		$all = self::sites();

		if ( '' === $site || ! isset( $all[ $slot ][ $site ] ) ) {
			return self::ai_error(
				403,
				sprintf( 'This licence is not active on %s. Activate it under DataChat → Settings → Licence first.', '' !== $site ? $site : 'this site' ),
				'not_activated'
			);
		}

		return true;
	}

	/**
	 * Where a licence's sites and use are counted.
	 *
	 * A licence of ours is the customer's purchase of a product, not the key
	 * string: a renewal issues a new key, and both keys must share the same
	 * seats and the same monthly questions. Anything else is counted by key,
	 * as before.
	 *
	 * @param string $key   Licence key.
	 * @param array  $found Where it was found, slot included when known.
	 * @return string
	 */
	protected static function slot_of( $key, array $found ) {
		return ! empty( $found['slot'] ) ? (string) $found['slot'] : hash( 'sha256', $key );
	}

	/**
	 * The slot of a key, looked up from scratch.
	 *
	 * @param string $key Licence key.
	 * @return string
	 */
	protected static function slot_for_key( $key ) {
		list( , $found ) = self::verdict( $key );

		return self::slot_of( $key, is_array( $found ) ? $found : array() );
	}

	/**
	 * The slot the relay counts a key's questions in, cached with its verdict.
	 *
	 * @param string $key Licence key.
	 * @return string
	 */
	protected static function ai_slot( $key ) {
		$cached = get_transient( 'datachat_ai_ok_' . hash( 'sha256', $key ) );

		return is_array( $cached ) && ! empty( $cached['slot'] ) ? (string) $cached['slot'] : hash( 'sha256', $key );
	}

	/**
	 * How long a licence of ours runs, counting renewals, and how many sites
	 * it covers.
	 *
	 * Every paid purchase of the product by the same customer adds a term,
	 * starting when it was paid or when the previous term ends, whichever is
	 * later - so renewing early loses nothing. The key the customer already
	 * has keeps working: renewing is buying again, not typing a new key.
	 *
	 * @param array    $found Where the key was found.
	 * @param WC_Order $order The order it was sold with.
	 * @return array|null {expires, slot, seats, product_id}, or null for a product that is not ours.
	 */
	protected static function renewal_state( array $found, $order ) {
		$product = self::product_of( $found );

		if ( '' === $product || ! in_array( $product, self::own_products(), true ) ) {
			return null;
		}

		$customer = self::customer_of( $order );
		$days     = self::licence_days( $product );

		$purchases = self::purchases( $order, $product );

		/**
		 * Filters the purchases a licence is made of - the paid orders of this
		 * product by this customer, oldest first.
		 *
		 * @param array  $purchases List of {paid (timestamp), quantity, order_id}.
		 * @param string $product   Product id.
		 * @param string $customer  Customer identity.
		 */
		$purchases = (array) apply_filters( 'datachat_license_purchases', $purchases, $product, $customer );

		usort(
			$purchases,
			static function ( $a, $b ) {
				return (int) $a['paid'] - (int) $b['paid'];
			}
		);

		$ends  = 0;
		$seats = 1;

		foreach ( $purchases as $purchase ) {
			$paid  = (int) $purchase['paid'];
			$ends  = max( $ends, $paid ) + $days * DAY_IN_SECONDS;
			$seats = max( 1, isset( $purchase['quantity'] ) ? (int) $purchase['quantity'] : 1 ) * self::product_sites( $product );
		}

		return array(
			'product_id' => $product,
			'slot'       => 'lic_' . hash( 'sha256', $customer . '|' . $product ),
			'seats'      => $seats,
			// No term, or nothing on record: no end date, as for a lifetime.
			'expires'    => $days > 0 && $ends > 0 ? gmdate( 'Y-m-d', $ends ) : '',
		);
	}

	/**
	 * Sites one licence of a product covers: 1, unless the shop says more -
	 * the Agency edition, say, covering ten client sites.
	 *
	 * @param string $product Product id.
	 * @return int
	 */
	protected static function product_sites( $product ) {
		$map   = defined( 'DATACHAT_PRODUCT_SITES' ) ? (string) DATACHAT_PRODUCT_SITES : (string) get_option( 'datachat_product_sites', '' );
		$sites = self::SEATS;

		foreach ( array_filter( array_map( 'trim', explode( ',', $map ) ) ) as $pair ) {
			$parts = array_map( 'trim', explode( ':', $pair ) );

			if ( 2 === count( $parts ) && (string) $parts[0] === (string) $product && (int) $parts[1] > 0 ) {
				$sites = (int) $parts[1];
			}
		}

		return $sites;
	}

	/**
	 * Who bought an order: their account, or failing that their email.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	protected static function customer_of( $order ) {
		$id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

		if ( $id > 0 ) {
			return 'user:' . $id;
		}

		$email = method_exists( $order, 'get_billing_email' ) ? strtolower( trim( (string) $order->get_billing_email() ) ) : '';

		return '' !== $email ? 'email:' . $email : 'order:' . ( method_exists( $order, 'get_id' ) ? $order->get_id() : 0 );
	}

	/**
	 * Days one purchase of a product runs, from SLKWoo's own setting on it.
	 *
	 * @param string $product Product id.
	 * @return int Zero for a licence that does not expire.
	 */
	protected static function licence_days( $product ) {
		$days = function_exists( 'get_post_meta' ) ? (int) get_post_meta( (int) $product, 'slkwoo_expiry', true ) : 0;

		/**
		 * Filters how many days one purchase of a product runs.
		 *
		 * @param int    $days    Days; zero for no end.
		 * @param string $product Product id.
		 */
		return max( 0, (int) apply_filters( 'datachat_license_days', $days, $product ) );
	}

	/**
	 * The paid orders of a product by the customer behind an order.
	 *
	 * @param WC_Order $order   Order a key was sold with.
	 * @param string   $product Product id.
	 * @return array List of {paid, quantity, order_id}.
	 */
	protected static function purchases( $order, $product ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( self::purchase_in( $order, $product ) );
		}

		$args = array(
			'status'  => array_map(
				static function ( $status ) {
					return 'wc-' . $status;
				},
				self::PAID
			),
			'limit'   => 200,
			'orderby' => 'date',
			'order'   => 'ASC',
			'type'    => 'shop_order',
		);

		$id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

		$email = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';

		if ( $id > 0 ) {
			$args['customer_id'] = $id;
		} elseif ( '' !== $email ) {
			$args['billing_email'] = $email;
		} else {
			// Nobody to look further for: this order is the whole licence.
			return array( self::purchase_in( $order, $product ) );
		}

		$found = array();

		foreach ( (array) wc_get_orders( $args ) as $candidate ) {
			$purchase = self::purchase_in( $candidate, $product );

			if ( $purchase['quantity'] > 0 ) {
				$found[] = $purchase;
			}
		}

		// The order the key came from counts even if the query missed it.
		$own = self::purchase_in( $order, $product );

		if ( $own['quantity'] > 0 && ! in_array( $own['order_id'], array_column( $found, 'order_id' ), true ) ) {
			$found[] = $own;
		}

		return $found;
	}

	/**
	 * One order as a purchase of a product.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $product Product id.
	 * @return array {paid, quantity, order_id}
	 */
	protected static function purchase_in( $order, $product ) {
		$quantity = 0;

		if ( method_exists( $order, 'get_items' ) ) {
			foreach ( $order->get_items() as $item ) {
				if ( method_exists( $item, 'get_product_id' ) && (string) $item->get_product_id() === (string) $product ) {
					$quantity += max( 1, (int) $item->get_quantity() );
				}
			}
		}

		$date = null;

		if ( method_exists( $order, 'get_date_paid' ) ) {
			$date = $order->get_date_paid();
		}

		if ( ! $date && method_exists( $order, 'get_date_created' ) ) {
			$date = $order->get_date_created();
		}

		return array(
			'paid'     => $date ? (int) $date->getTimestamp() : time(),
			'quantity' => $quantity,
			'order_id' => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
		);
	}

	/**
	 * The product a key was sold as.
	 *
	 * @param array $found Where the key was found.
	 * @return string Product id, or empty when it cannot be told.
	 */
	protected static function product_of( array $found ) {
		if ( ! empty( $found['product_id'] ) ) {
			return (string) $found['product_id'];
		}

		if ( ! empty( $found['item_id'] ) && function_exists( 'wc_get_order_item_meta' ) ) {
			$product = wc_get_order_item_meta( (int) $found['item_id'], '_product_id', true );

			if ( $product ) {
				return (string) $product;
			}
		}

		$order = ! empty( $found['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( $found['order_id'] ) : null;

		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return '';
		}

		// A key stored on the order rather than a line: if one line is a
		// DataChat edition, that is what was sold.
		$first = '';

		foreach ( $order->get_items() as $item ) {
			$product = method_exists( $item, 'get_product_id' ) ? (string) $item->get_product_id() : '';

			if ( in_array( $product, self::own_products(), true ) ) {
				return $product;
			}

			if ( '' === $first ) {
				$first = $product;
			}
		}

		return $first;
	}

	/**
	 * The request as OpenAI will get it: this shop's model, a sane budget, and
	 * nothing but the fields a chat completion takes.
	 *
	 * @param array $in What the site sent.
	 * @return array
	 */
	protected static function ai_body( array $in ) {
		$body = array( 'model' => self::ai_model() );

		$messages = isset( $in['messages'] ) && is_array( $in['messages'] ) ? $in['messages'] : array();
		$clean    = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}

			if ( ! in_array( $message['role'], array( 'system', 'user', 'assistant' ), true ) ) {
				continue;
			}

			$clean[] = array(
				'role'    => $message['role'],
				'content' => $message['content'],
			);
		}

		$body['messages'] = $clean;

		foreach ( array( 'max_tokens', 'max_completion_tokens' ) as $budget ) {
			if ( isset( $in[ $budget ] ) ) {
				$body[ $budget ] = max( 16, min( 8192, (int) $in[ $budget ] ) );
			}
		}

		if ( isset( $in['temperature'] ) ) {
			$body['temperature'] = max( 0, min( 1, (float) $in['temperature'] ) );
		}

		if ( isset( $in['response_format']['type'] ) && 'json_object' === $in['response_format']['type'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		return $body;
	}

	/**
	 * The licence key a request carries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	protected static function bearer( WP_REST_Request $request ) {
		$own = trim( (string) $request->get_header( 'x_datachat_licence' ) );

		if ( '' !== $own ) {
			return $own;
		}

		$auth = (string) $request->get_header( 'authorization' );

		return preg_match( '/^Bearer\s+(.+)$/i', trim( $auth ), $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * An error in the shape OpenAI uses, which the plugin already reads.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message For the customer.
	 * @param string $type    Machine-readable reason.
	 * @return WP_REST_Response
	 */
	protected static function ai_error( $code, $message, $type ) {
		return new WP_REST_Response(
			array(
				'error' => array(
					'message' => $message,
					'type'    => $type,
					'code'    => $type,
				),
			),
			$code
		);
	}

	/**
	 * Every licence's count for the month.
	 *
	 * @return array
	 */
	protected static function ai_usage() {
		$stored = get_option( self::AI_USAGE, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Calls this licence has made this month.
	 *
	 * @param string $slot Hashed key.
	 * @return int
	 */
	protected static function ai_used( $slot ) {
		$usage = self::ai_usage();

		if ( ! isset( $usage[ $slot ]['month'] ) || gmdate( 'Y-m' ) !== $usage[ $slot ]['month'] ) {
			return 0;
		}

		return (int) $usage[ $slot ]['calls'];
	}

	/**
	 * Count one answered call.
	 *
	 * @param string $slot Hashed key.
	 * @param string $site Domain.
	 * @return void
	 */
	protected static function ai_count( $slot, $site ) {
		$usage = self::ai_usage();
		$month = gmdate( 'Y-m' );

		// Last month's counts are of no further use.
		foreach ( $usage as $known => $use ) {
			if ( ! isset( $use['month'] ) || $month !== $use['month'] ) {
				unset( $usage[ $known ] );
			}
		}

		$usage[ $slot ] = array(
			'month' => $month,
			'calls' => ( isset( $usage[ $slot ]['calls'] ) ? (int) $usage[ $slot ]['calls'] : 0 ) + 1,
			'site'  => $site,
		);

		update_option( self::AI_USAGE, $usage, false );
	}

	/**
	 * Whether this licence is within its per-minute burst.
	 *
	 * @param string $slot Hashed key.
	 * @return bool
	 */
	protected static function ai_burst_ok( $slot ) {
		$name = 'datachat_ai_burst_' . substr( $slot, 0, 20 );
		$hits = (int) get_transient( $name );

		if ( $hits >= self::AI_BURST ) {
			return false;
		}

		set_transient( $name, $hits + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Calls per licence per month.
	 *
	 * @return int
	 */
	protected static function ai_allowance() {
		$stored = get_option( 'datachat_ai_monthly_calls', '' );
		$calls  = '' === $stored || null === $stored || false === $stored ? self::AI_CALLS : (int) $stored;

		/**
		 * Filters the monthly calls a Pro licence gets.
		 *
		 * @param int $calls Calls.
		 */
		return max( 0, (int) apply_filters( 'datachat_ai_monthly_calls', $calls ) );
	}

	/**
	 * The model that answers.
	 *
	 * @return string
	 */
	protected static function ai_model() {
		$model = defined( 'DATACHAT_AI_MODEL' ) ? (string) DATACHAT_AI_MODEL : trim( (string) get_option( 'datachat_ai_model', '' ) );

		return '' !== $model ? $model : self::AI_MODEL;
	}

	/**
	 * This shop's OpenAI key. Never printed, never sent anywhere but OpenAI.
	 *
	 * @return string
	 */
	protected static function openai_key() {
		$key = defined( 'DATACHAT_OPENAI_API_KEY' ) ? (string) DATACHAT_OPENAI_API_KEY : (string) get_option( 'datachat_openai_api_key', '' );

		return trim( $key );
	}

	/**
	 * The products whose licence includes the model.
	 *
	 * @return array Product ids as strings.
	 */
	protected static function ai_products() {
		$products = defined( 'DATACHAT_AI_PRODUCT_IDS' ) ? (string) DATACHAT_AI_PRODUCT_IDS : (string) get_option( 'datachat_ai_product_ids', '' );

		/**
		 * Filters which products include the model.
		 *
		 * @param string $products Comma-separated product ids.
		 */
		$products = (string) apply_filters( 'datachat_ai_products', $products );

		return array_values( array_filter( array_map( 'trim', explode( ',', $products ) ) ) );
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

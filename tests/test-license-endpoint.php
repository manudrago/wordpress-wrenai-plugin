<?php
/**
 * The shop's side: reading SLKWoo's encrypted feed, and signing an answer the
 * plugin will accept. The last part matters most - the canonical string a
 * signature covers is written out in two files that ship separately, and this
 * is what catches them drifting apart. Runs without WordPress or WooCommerce:
 *
 *     php tests/test-license-endpoint.php
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

// ---------------------------------------------------------------------------
// The little of WordPress's REST layer that the endpoint touches
// ---------------------------------------------------------------------------

/**
 * Hook registrar.
 *
 * @return void
 */
function add_action() {}

/**
 * Route registrar.
 *
 * @return void
 */
function register_rest_route() {}

/**
 * REST URL.
 *
 * @param string $path Path.
 * @return string
 */
function rest_url( $path = '' ) {
	return 'https://shop.example/wp-json/' . ltrim( $path, '/' );
}

/**
 * Slash remover.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return $value;
}

/**
 * Server constants.
 */
class WP_REST_Server {

	const READABLE  = 'GET';
	const CREATABLE = 'POST';
}

/**
 * Response stub: holds what the endpoint decided.
 */
class WP_REST_Response {

	/**
	 * Body.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * Status.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Construct.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status Status.
	 */
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/**
	 * Body.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}
}

/**
 * Request stub.
 */
class WP_REST_Request {

	/**
	 * Parameters.
	 *
	 * @var array
	 */
	protected $params;

	/**
	 * Construct.
	 *
	 * @param array $params Parameters.
	 */
	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	/**
	 * One parameter.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
}

require __DIR__ . '/../deploy/license-endpoint.php';

/**
 * Reaches the endpoint's own workings, which are protected for the shop's sake
 * and not for the test's.
 */
class Shop_Probe extends DataChat_Licence_Endpoint {

	/**
	 * Call anything on the endpoint.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	public static function call( $method, array $args = array() ) {
		return call_user_func_array( array( 'static', $method ), $args );
	}
}

/**
 * Encrypt the way SLKWoo does, quirk and all, so decryption can be tested
 * against something real rather than against itself.
 *
 * @param string $plain      Plaintext.
 * @param string $passphrase Passphrase.
 * @return string
 */
function slkwoo_encrypt( $plain, $passphrase ) {
	// The integer where an IV belongs is SLKWoo's, not a slip: PHP reads it as
	// "16" and pads it, and warns while doing so. Silenced, because that warning
	// is the point of the test.
	return (string) @openssl_encrypt( $plain, 'aes-256-cfb', $passphrase, 0, openssl_cipher_iv_length( 'aes-256-cfb' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

$passphrase = 'a-passphrase-only-this-test-knows';

add_test_filter( 'datachat_license_passphrase', $passphrase );

// ---------------------------------------------------------------------------
// Decryption
// ---------------------------------------------------------------------------

$cipher = slkwoo_encrypt( 'DCAI-PRO-0001-ABCD', $passphrase );

check( 'a key encrypted the way SLKWoo does comes back out', 'DCAI-PRO-0001-ABCD' === Shop_Probe::call( 'decrypt', array( $cipher, $passphrase ) ), $cipher );
check( 'the wrong passphrase yields nothing usable', '' === Shop_Probe::call( 'decrypt', array( $cipher, 'wrong-passphrase-entirely' ) ) );
check( 'and neither does something that is not ciphertext', '' === Shop_Probe::call( 'decrypt', array( 'bm90LWNpcGhlcnRleHQ=', $passphrase ) ) );

// ---------------------------------------------------------------------------
// Walking a feed whose shape nobody documented
// ---------------------------------------------------------------------------

$found = Shop_Probe::call(
	'encrypted_strings',
	array(
		array(
			'ok'   => true,
			'data' => array(
				array( 'encrypt_data' => $cipher ),
				array( 'encrypt_data' => slkwoo_encrypt( 'DCAI-PRO-0002-EFGH', $passphrase ) ),
			),
			'note' => 'not base64 at all!',
		),
	)
);

check( 'every candidate in a nested feed is found', 2 === count( $found ), wp_json_encode( $found ) );
check( 'and prose is not mistaken for one', ! in_array( 'not base64 at all!', $found, true ) );

$entries = Shop_Probe::call(
	'entries_from',
	array(
		array(
			array( 'license_key' => 'DCAI-PRO-0003-IJKL', 'order_id' => 42, 'expiry' => '2027-01-01' ),
			array( 'key' => 'short' ),
		),
	)
);

check( 'a record gives up its key, order and expiry', 1 === count( $entries ) && 'DCAI-PRO-0003-IJKL' === $entries[0]['key'] && 42 === $entries[0]['order_id'] && '2027-01-01' === $entries[0]['expires'], wp_json_encode( $entries ) );
check( 'and a key too short to be one is ignored', 1 === count( $entries ) );

// ---------------------------------------------------------------------------
// Looking a key up in the feed
// ---------------------------------------------------------------------------

// SLKWoo's own shape, as the live feed serves it: the key encrypted under
// open_key, everything around it in clear.
WWD_Test_HTTP::reset();
WWD_Test_HTTP::$raw = wp_json_encode(
	array(
		array(
			'product_id'   => 5461,
			'open_key'     => slkwoo_encrypt( 'DCAI-PRO-1111-AAAA', $passphrase ),
			'date_expiry'  => '2028-06-30',
			'expiry_stamp' => 1845000000,
		),
		array(
			'product_id'   => 5462,
			'open_key'     => slkwoo_encrypt( 'DCAI-PRO-2222-BBBB', $passphrase ),
			'date_expiry'  => '',
			'expiry_stamp' => 1845000000,
		),
	)
);

$hit = Shop_Probe::call( 'from_feed', array( 'DCAI-PRO-1111-AAAA' ) );

check( 'a key in the feed is found', is_array( $hit ), wp_json_encode( $hit ) );
check( 'and named as coming from the feed', 'slkwoo_feed' === $hit['where'] );
check( 'with the product it was sold as', '5461' === $hit['product_id'], wp_json_encode( $hit ) );
check( 'and the date it runs out', '2028-06-30' === $hit['expires'] );

$stamped = Shop_Probe::call( 'from_feed', array( 'DCAI-PRO-2222-BBBB' ) );

check( 'an entry with only a timestamp still gives a date', is_array( $stamped ) && '2028-06-19' === $stamped['expires'], wp_json_encode( $stamped ) );
check( 'a key that is not in the feed is not', null === Shop_Probe::call( 'from_feed', array( 'DCAI-PRO-9999-ZZZZ' ) ) );
check( 'and something too short to be a key is refused outright', null === Shop_Probe::call( 'from_feed', array( 'AAA' ) ) );

check( 'the feed is read once and then remembered', 1 === count( WWD_Test_HTTP::$requests ), count( WWD_Test_HTTP::$requests ) . ' requests' );
check( 'from the shop it is installed on', 'https://shop.example/wp-json/rf/slk-woo-open-key_api/token' === WWD_Test_HTTP::$last['url'], WWD_Test_HTTP::$last['url'] );

delete_transient( 'datachat_slkwoo_feed' );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status = 500;

check( 'a feed that is down yields no keys rather than an error', array() === Shop_Probe::call( 'feed_keys' ) );

delete_transient( 'datachat_slkwoo_feed' );

// ---------------------------------------------------------------------------
// The round trip: what the shop signs, the plugin accepts
// ---------------------------------------------------------------------------

$keys = Shop_Probe::call( 'keypair' );

check( 'the shop generates itself a signing pair', ! empty( $keys['private'] ) && ! empty( $keys['public'] ) );
check( 'and keeps the same one next time', $keys === Shop_Probe::call( 'keypair' ) );
check( 'whose public half is a readable PEM', false !== strpos( $keys['public'], 'BEGIN PUBLIC KEY' ) );

// The plugin, in a build that carries this shop's public key.
define( 'WWD_EDITION', 'pro' );
define( 'WWD_LICENSE_ENDPOINT', 'https://shop.example/wp-json/datachat/v1/license' );
define( 'WWD_LICENSE_PUBLIC_KEY', $keys['public'] );

require __DIR__ . '/../includes/class-wwd-license.php';

/**
 * The order behind a key, for the one path that needs WooCommerce.
 */
class Shop_Order {

	/**
	 * Status to report.
	 *
	 * @var string
	 */
	public static $status = 'completed';

	/**
	 * Order status.
	 *
	 * @return string
	 */
	public function get_status() {
		return self::$status;
	}

	/**
	 * Order id.
	 *
	 * @return int
	 */
	public function get_id() {
		return 4242;
	}

	/**
	 * Order meta.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	public function get_meta( $key ) {
		return '_license_expiry' === $key ? '2030-03-01' : '';
	}
}

/**
 * Order lookup.
 *
 * @param int $order_id Order id.
 * @return Shop_Order
 */
function wc_get_order( $order_id ) {
	return new Shop_Order();
}

$feed_body = wp_json_encode(
	array(
		array(
			'product_id'  => 5461,
			'open_key'    => slkwoo_encrypt( 'DCAI-PRO-1111-AAAA', $passphrase ),
			'date_expiry' => '2029-01-01',
		),
	)
);

/**
 * Put the shop behind the plugin: the plugin's POST is answered by the real
 * endpoint, and the endpoint's own read of the feed is answered by the feed.
 *
 * @return void
 */
function shop_is_listening() {
	global $feed_body;

	WWD_Test_HTTP::reset();

	WWD_Test_HTTP::$responder = static function ( array $request ) use ( $feed_body ) {
		if ( false !== strpos( (string) $request['url'], 'slk-woo-open-key_api' ) ) {
			return array( 'raw' => $feed_body );
		}

		$answer = DataChat_Licence_Endpoint::check(
			new WP_REST_Request(
				array(
					'license_key' => $request['body']['license_key'],
					'domain'      => $request['body']['domain'],
					'nonce'       => $request['body']['nonce'],
				)
			)
		);

		return array( 'response' => $answer->get_data() );
	};
}

delete_transient( 'datachat_slkwoo_feed' );
shop_is_listening();

$activated = WWD_License::activate( 'DCAI-PRO-1111-AAAA' );

check( 'a key in the feed, answered and signed by the shop, activates', ! is_wp_error( $activated ), is_wp_error( $activated ) ? $activated->get_error_message() : '' );
check( 'the licence is live', WWD_License::is_valid() );
check( 'with the expiry the feed carried', '2029-01-01' === WWD_License::state()['expires'], WWD_License::state()['expires'] );
check( 'the two files still agree on what a signature covers', WWD_License::SIGNED_FIELDS === DataChat_Licence_Endpoint::SIGNED_FIELDS );

// A key nobody has heard of: not in the feed, not in any order.
delete_transient( 'datachat_slkwoo_feed' );
shop_is_listening();

$unknown = WWD_License::activate( 'DCAI-PRO-0000-NOPE' );

check( 'a key the shop cannot place is refused', is_wp_error( $unknown ) && 'wwd_license_refused' === $unknown->get_error_code(), $unknown->get_error_code() );
check( 'and the refusal is the shop\'s own, signed', false !== strpos( (string) $unknown->get_error_message(), 'Unknown licence key' ), $unknown->get_error_message() );
check( 'so nothing is unlocked', ! WWD_License::is_valid() );

// A key that has left the feed - expired, or simply old - but is on an order.
$GLOBALS['wpdb']->rows = array(
	'woocommerce_order_itemmeta' => array( 'order_id' => 4242, 'meta_key' => '_slkwoo_key' ),
);

delete_transient( 'datachat_slkwoo_feed' );
shop_is_listening();

$from_order = WWD_License::activate( 'DCAI-PRO-3333-CCCC' );

check( 'a key no longer in the feed is found on its order', ! is_wp_error( $from_order ), is_wp_error( $from_order ) ? $from_order->get_error_message() : '' );
check( 'and takes the expiry the order records', '2030-03-01' === WWD_License::state()['expires'], WWD_License::state()['expires'] );

Shop_Order::$status = 'refunded';

delete_transient( 'datachat_slkwoo_feed' );
shop_is_listening();

$refunded = WWD_License::activate( 'DCAI-PRO-3333-CCCC' );

check( 'a refunded order stops being a licence', is_wp_error( $refunded ), is_wp_error( $refunded ) ? '' : 'accepted' );
check( 'and the shop says why', false !== strpos( (string) $refunded->get_error_message(), 'refunded' ), $refunded->get_error_message() );
check( 'and it locks', ! WWD_License::is_valid() );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

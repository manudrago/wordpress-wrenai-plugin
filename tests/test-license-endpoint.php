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
 * Filter registrar.
 *
 * @return void
 */
function add_filter() {}

/**
 * Route registrar.
 *
 * @return void
 */
function register_rest_route() {}

/**
 * In-process REST dispatch. Answers with whatever a test has staged.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function rest_do_request( $request ) {
	$GLOBALS['wwd_rest_calls'][] = $request->get_route();

	return new WP_REST_Response(
		isset( $GLOBALS['wwd_rest_answer'] ) ? $GLOBALS['wwd_rest_answer'] : array(),
		isset( $GLOBALS['wwd_rest_status'] ) ? (int) $GLOBALS['wwd_rest_status'] : 200
	);
}

$GLOBALS['wwd_rest_calls'] = array();

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

	/**
	 * Replace the body.
	 *
	 * @param mixed $data Body.
	 * @return void
	 */
	public function set_data( $data ) {
		$this->data = $data;
	}

	/**
	 * Whether this is an error response.
	 *
	 * @return bool
	 */
	public function is_error() {
		return $this->status >= 400;
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
	 * Route, when built the way WordPress builds one.
	 *
	 * @var string
	 */
	protected $route = '';

	/**
	 * Construct, either with parameters or as WordPress does, with a method
	 * and a route.
	 *
	 * @param array|string $params Parameters, or an HTTP method.
	 * @param string       $route  Route, when the first argument is a method.
	 */
	public function __construct( $params = array(), $route = '' ) {
		if ( is_string( $params ) ) {
			$this->params = array();
			$this->route  = $route;

			return;
		}

		$this->params = (array) $params;
	}

	/**
	 * Route.
	 *
	 * @return string
	 */
	public function get_route() {
		return $this->route;
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

$GLOBALS['wwd_rest_calls']  = array();
$GLOBALS['wwd_rest_answer'] = array(
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

check( 'the feed is read once and then remembered', 1 === count( $GLOBALS['wwd_rest_calls'] ), wp_json_encode( $GLOBALS['wwd_rest_calls'] ) );
check( 'from inside this WordPress, not over the network', '/rf/slk-woo-open-key_api/token' === $GLOBALS['wwd_rest_calls'][0], $GLOBALS['wwd_rest_calls'][0] );
check( 'so no HTTP request is made to ourselves', 0 === count( WWD_Test_HTTP::$requests ), count( WWD_Test_HTTP::$requests ) . ' requests' );

delete_transient( 'datachat_slkwoo_feed' );

$GLOBALS['wwd_rest_status'] = 500;

check( 'a feed that is down yields no keys rather than an error', array() === Shop_Probe::call( 'feed_keys' ) );

unset( $GLOBALS['wwd_rest_status'] );
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

$GLOBALS['wwd_rest_answer'] = array(
	array(
		'product_id'  => 5461,
		'open_key'    => slkwoo_encrypt( 'DCAI-PRO-1111-AAAA', $passphrase ),
		'date_expiry' => '2029-01-01',
	),
);

/**
 * Put the shop behind the plugin: the plugin's POST is answered by the real
 * endpoint, and the endpoint's own read of the feed is answered by the feed.
 *
 * @return void
 */
function shop_is_listening() {
	WWD_Test_HTTP::reset();

	WWD_Test_HTTP::$responder = static function ( array $request ) {
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

// ---------------------------------------------------------------------------
// One licence, one site
// ---------------------------------------------------------------------------

/**
 * Ask the endpoint about a key, as a site would.
 *
 * @param string $key    Licence key.
 * @param string $domain Domain asking.
 * @return array
 */
function ask_from( $key, $domain ) {
	return DataChat_Licence_Endpoint::check(
		new WP_REST_Request(
			array(
				'license_key' => $key,
				'domain'      => $domain,
				'nonce'       => 'nonce-' . md5( $domain . microtime() ),
			)
		)
	)->get_data();
}

// A key the shop can place, so only the seat count is under test.
add_test_filter( 'datachat_license_lookup', array( 'order_id' => 0, 'where' => 'slkwoo_feed', 'expires' => '' ) );

delete_option( DataChat_Licence_Endpoint::SITES );

$first = ask_from( 'DCAI-SEAT-0001', 'first-site.example' );

check( 'the first site gets the licence', 'valid' === $first['status'], wp_json_encode( $first ) );
check( 'and is told it is one site of one', 1 === $first['seats']['limit'] && 1 === $first['seats']['used'], wp_json_encode( $first['seats'] ) );

$again = ask_from( 'DCAI-SEAT-0001', 'first-site.example' );

check( 'the same site checking in again is still fine', 'valid' === $again['status'] );
check( 'and does not take a second seat', 1 === $again['seats']['used'] );

check( 'nor does the same site with a www', 'valid' === ask_from( 'DCAI-SEAT-0001', 'www.first-site.example' )['status'] );
check( 'nor spelled as a URL', 'valid' === ask_from( 'DCAI-SEAT-0001', 'https://first-site.example/' )['status'] );
check( 'nor in capitals', 'valid' === ask_from( 'DCAI-SEAT-0001', 'First-Site.Example' )['status'] );

$second = ask_from( 'DCAI-SEAT-0001', 'second-site.example' );

check( 'a second site is refused', 'invalid' === $second['status'], wp_json_encode( $second ) );
check( 'and told which site has it', false !== strpos( $second['message'], 'first-site.example' ), $second['message'] );
check( 'and told how to free it', false !== strpos( $second['message'], 'Remove it there first' ), $second['message'] );
check( 'a refusal carries no expiry to lean on', '' === $second['expires'] );
check( 'and the refusal is signed like any other answer', ! empty( $second['signature'] ) );

check( 'the first site still has it', 'valid' === ask_from( 'DCAI-SEAT-0001', 'first-site.example' )['status'] );

// Another key is another licence.
check( 'a different key is unaffected', 'valid' === ask_from( 'DCAI-SEAT-0002', 'second-site.example' )['status'] );

// ---------------------------------------------------------------------------
// Moving the licence
// ---------------------------------------------------------------------------

$released = DataChat_Licence_Endpoint::release(
	new WP_REST_Request( array( 'license_key' => 'DCAI-SEAT-0001', 'domain' => 'first-site.example' ) )
)->get_data();

check( 'the first site can give its seat back', ! empty( $released['released'] ) );
check( 'and the second site can then have it', 'valid' === ask_from( 'DCAI-SEAT-0001', 'second-site.example' )['status'] );
check( 'while the first now has to queue', 'invalid' === ask_from( 'DCAI-SEAT-0001', 'first-site.example' )['status'] );

$twice = DataChat_Licence_Endpoint::release(
	new WP_REST_Request( array( 'license_key' => 'DCAI-SEAT-0001', 'domain' => 'nobody.example' ) )
)->get_data();

check( 'releasing a site that holds nothing is not an error', ! empty( $twice['released'] ) );

// A site that stops checking in gives its seat back by itself.
$stored = get_option( DataChat_Licence_Endpoint::SITES, array() );
$slot   = hash( 'sha256', 'DCAI-SEAT-0003' );

$stored[ $slot ] = array(
	'abandoned.example' => array( 'first' => time() - ( 400 * DAY_IN_SECONDS ), 'last' => time() - ( 90 * DAY_IN_SECONDS ) ),
);

update_option( DataChat_Licence_Endpoint::SITES, $stored, false );

$reclaimed = ask_from( 'DCAI-SEAT-0003', 'new-home.example' );

check( 'a site unseen for months no longer holds the licence', 'valid' === $reclaimed['status'], wp_json_encode( $reclaimed ) );
check( 'and the abandoned one is forgotten', array( 'new-home.example' ) === $reclaimed['seats']['sites'], wp_json_encode( $reclaimed['seats'] ) );

// Keys are not stored in the clear.
$raw = wp_json_encode( get_option( DataChat_Licence_Endpoint::SITES, array() ) );

check( 'the record of sites holds no licence key', false === strpos( $raw, 'DCAI-SEAT-000' ), $raw );

// A shop that sells more than one seat can say so.
add_test_filter( 'datachat_license_seats', 3 );

delete_option( DataChat_Licence_Endpoint::SITES );

ask_from( 'DCAI-SEAT-0004', 'one.example' );
ask_from( 'DCAI-SEAT-0004', 'two.example' );

$third = ask_from( 'DCAI-SEAT-0004', 'three.example' );

check( 'a shop can sell a key for more sites', 'valid' === $third['status'] && 3 === $third['seats']['used'], wp_json_encode( $third['seats'] ) );
check( 'and the fourth still waits', 'invalid' === ask_from( 'DCAI-SEAT-0004', 'four.example' )['status'] );

// ---------------------------------------------------------------------------
// Keeping our own keys out of the public feed
// ---------------------------------------------------------------------------

/**
 * The feed as SLKWoo would serve it, two products in it.
 *
 * @return array
 */
function two_products() {
	return array(
		array( 'product_id' => 5461, 'open_key' => 'ours-encrypted', 'date_expiry' => '2029-01-01' ),
		array( 'product_id' => 9999, 'open_key' => 'theirs-encrypted', 'date_expiry' => '2029-01-01' ),
	);
}

$feed_route   = '/rf/slk-woo-open-key_api/token';
$outside      = new WP_REST_Request( 'GET', $feed_route );
$somewhere    = new WP_REST_Request( 'GET', '/wc/v3/orders' );

// Nothing configured: the feed is not touched at all.
add_test_filter( 'datachat_license_own_products', '' );

$untouched = DataChat_Licence_Endpoint::hide_own_keys( new WP_REST_Response( two_products() ), null, $outside )->get_data();

check( 'with no products named, the feed passes through whole', 2 === count( $untouched ) );

// Ours named: ours goes, theirs stays.
add_test_filter( 'datachat_license_own_products', '5461' );

$filtered = DataChat_Licence_Endpoint::hide_own_keys( new WP_REST_Response( two_products() ), null, $outside )->get_data();

check( 'our own product is not published', 1 === count( $filtered ), wp_json_encode( $filtered ) );
check( 'and what stays is the other plugin\'s', '9999' === (string) $filtered[0]['product_id'] );
check( 'whose key is untouched', 'theirs-encrypted' === $filtered[0]['open_key'] );
check( 'and the list is still a list, not a list with a hole', array_keys( $filtered ) === range( 0, count( $filtered ) - 1 ) );

// A feed that wraps its list is handled the same way.
$wrapped = DataChat_Licence_Endpoint::hide_own_keys( new WP_REST_Response( array( 'data' => two_products() ) ), null, $outside )->get_data();

check( 'a wrapped list is filtered inside its wrapper', 1 === count( $wrapped['data'] ), wp_json_encode( $wrapped ) );

// Every other route is none of this filter's business.
$other = DataChat_Licence_Endpoint::hide_own_keys( new WP_REST_Response( two_products() ), null, $somewhere )->get_data();

check( 'another route is left alone', 2 === count( $other ) );

// And the shop's own read still sees everything, because that is the only way
// it can check its own keys once they are hidden.
$GLOBALS['wwd_rest_answer'] = array(
	array( 'product_id' => 5461, 'open_key' => slkwoo_encrypt( 'DCAI-HIDDEN-0001', $passphrase ), 'date_expiry' => '2029-05-05' ),
);

delete_transient( 'datachat_slkwoo_feed' );
add_test_filter( 'datachat_license_lookup', null );

$still_visible = Shop_Probe::call( 'from_feed', array( 'DCAI-HIDDEN-0001' ) );

check( 'a hidden key is still visible to the shop itself', is_array( $still_visible ), wp_json_encode( $still_visible ) );
check( 'with its expiry', '2029-05-05' === $still_visible['expires'] );

// ---------------------------------------------------------------------------
// The report, which the Tools page and the REST route share
// ---------------------------------------------------------------------------

add_test_filter( 'datachat_license_own_products', '5461' );
add_test_filter( 'datachat_license_lookup', null );

$report = DataChat_Licence_Endpoint::report( '' );

check( 'the report says no key was given', false === $report['key_given'] );
check( 'and whether it can sign', true === $report['can_sign'] );
check( 'and whether a passphrase is set', true === $report['passphrase_set'] );
check( 'and what it is hiding from the feed', array( '5461' ) === $report['hidden_products'], wp_json_encode( $report['hidden_products'] ) );
check( 'and nothing about seats without a key', null === $report['seats'] );

$raw = wp_json_encode( $report );

check( 'the report prints no passphrase', false === strpos( $raw, $passphrase ), 'the passphrase is in the report' );
check( 'and no private key', false === strpos( $raw, 'PRIVATE KEY' ) );

// The route hands back exactly what the page prints.
$through_rest = DataChat_Licence_Endpoint::probe( new WP_REST_Request( array( 'key' => '' ) ) )->get_data();

check( 'the route and the page report the same thing', array_keys( $through_rest ) === array_keys( $report ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

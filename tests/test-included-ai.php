<?php
/**
 * The model that comes with Pro, from the customer's side: the plugin reaches
 * it on the shop, with the licence as the only credential, and shows the
 * shop's refusals as they are worded. Runs without WordPress:
 *
 *     php tests/test-included-ai.php
 *
 * @package DataChat_AI
 */

define( 'ABSPATH', __DIR__ );
define( 'WWD_EDITION', 'pro' );
define( 'WWD_LICENSE_ENDPOINT', 'https://shop.example/wp-json/datachat/v1/license' );

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-wwd-license.php';
require __DIR__ . '/../includes/class-wwd-settings.php';
require __DIR__ . '/../includes/class-wwd-model-client.php';

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

check( 'the model sits beside the licence check', 'https://shop.example/wp-json/datachat/v1/ai' === WWD_License::ai_base(), WWD_License::ai_base() );
check( 'Pro offers the included model', array_key_exists( 'included', WWD_Model_Client::providers() ) );
check( 'and offers it first', 'included' === array_keys( WWD_Model_Client::providers() )[0] );
check( 'and starts on it', 'included' === WWD_Settings::defaults()['model_provider'] );

delete_option( WWD_License::OPTION );

$client = new WWD_Model_Client( array( 'provider' => 'included' ) );
$none   = $client->complete( 'sys', 'user' );

check( 'without a licence it says to activate one', is_wp_error( $none ) && 'wwd_included_no_licence' === $none->get_error_code() );

update_option( WWD_License::OPTION, array( 'key' => 'DCAI-PRO-KEY-1', 'status' => 'valid', 'confirmed_at' => time() ) );

// Whatever was typed for another provider does not leak into this one.
$client = new WWD_Model_Client(
	array(
		'provider' => 'included',
		'api_key'  => 'sk-typed-for-openai',
		'base'     => 'https://api.openai.com/v1',
		'model'    => 'gpt-5',
	)
);

WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 200,
			'response' => array( 'choices' => array( array( 'message' => array( 'content' => '{"ok": true}' ) ) ) ),
		),
	)
);

$answer = $client->complete( 'You answer with JSON only.', 'Reply with {"ok": true}' );
$sent   = WWD_Test_HTTP::$last;

check( 'an answer comes back', is_array( $answer ) && true === $answer['ok'], wp_json_encode( $answer ) );
check( 'from the shop', 'https://shop.example/wp-json/datachat/v1/ai/chat/completions' === $sent['url'], $sent['url'] );
check( 'with the licence as the credential', 'Bearer DCAI-PRO-KEY-1' === $sent['headers']['Authorization'] );
check( 'and in a header of its own', 'DCAI-PRO-KEY-1' === $sent['headers']['X-DataChat-Licence'] );
check( 'naming the site', 'example.com' === $sent['headers']['X-DataChat-Site'] || '' !== $sent['headers']['X-DataChat-Site'], wp_json_encode( $sent['headers'] ) );
check( 'never the key typed for another provider', false === strpos( wp_json_encode( $sent ), 'sk-typed-for-openai' ) );
check( 'and asking for the included model', 'included' === $sent['body']['model'] );
check( 'every call names the question it belongs to', preg_match( '/^[A-Za-z0-9-]{8,64}$/', (string) $sent['headers']['X-DataChat-Question'] ) );

$first_question = $sent['headers']['X-DataChat-Question'];

WWD_License::use_question( 'question-under-way-1' );
WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 200,
			'response' => array( 'choices' => array( array( 'message' => array( 'content' => '{"ok": true}' ) ) ) ),
			'headers'  => array(
				'X-DataChat-AI-Used'      => '124',
				'X-DataChat-AI-Allowance' => '300',
				'X-DataChat-AI-Extra'     => '0',
				'X-DataChat-AI-Renews'    => '2099-01-01',
				'X-DataChat-AI-Topup'     => 'https://shop.example/?datachat_topup=abc',
			),
		),
	)
);
$client->complete( 'sys', 'user' );

check( 'a question under way keeps its id across polls', 'question-under-way-1' === WWD_Test_HTTP::$last['headers']['X-DataChat-Question'] && 'question-under-way-1' !== $first_question );

$usage = WWD_License::usage();
check( 'the count the shop sends back is kept', 124 === $usage['used'] && 300 === $usage['allowance'] && '2099-01-01' === $usage['renews'], wp_json_encode( $usage ) );
check( 'with the link to buy more', 'https://shop.example/?datachat_topup=abc' === $usage['topup_url'] );

WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 402,
			'response' => array( 'error' => array( 'message' => 'Your licence\'s 300 questions for this month are used up.', 'code' => 'quota_exceeded' ) ),
		),
	)
);

$spent = $client->complete( 'sys', 'user' );

check( 'a spent allowance is shown in the shop\'s words', is_wp_error( $spent ) && 0 === strpos( $spent->get_error_message(), 'Your licence' ), is_wp_error( $spent ) ? $spent->get_error_message() : '' );
check( 'and is not retried', is_wp_error( $spent ) && empty( $spent->get_error_data()['retry'] ) );

WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 403,
			'response' => array( 'error' => array( 'message' => 'This licence is not active on example.com.', 'code' => 'not_activated' ) ),
		),
	)
);

$refused = $client->complete( 'sys', 'user' );

check( 'a refusal is not dressed up as a bad API key', is_wp_error( $refused ) && false === strpos( $refused->get_error_message(), 'refused the API key' ), is_wp_error( $refused ) ? $refused->get_error_message() : '' );

check( 'listing models needs no call', array( 'included' ) === $client->models() );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

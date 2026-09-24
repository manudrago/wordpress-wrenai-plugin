<?php
/**
 * The engine that calls a model directly: what it sends, what it accepts back,
 * and what it does when the provider says no. Runs without WordPress:
 *
 *     php tests/test-direct-engine.php
 *
 * @package WP_Wren_Dashboards
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';

require __DIR__ . '/../includes/class-wwd-settings.php';
require __DIR__ . '/../includes/class-wwd-schema.php';
require __DIR__ . '/../includes/class-wwd-model-client.php';
require __DIR__ . '/../includes/class-wwd-engine.php';
require __DIR__ . '/../includes/class-wwd-engine-direct.php';

WWD_Settings::update(
	array(
		'engine'             => 'direct',
		'model_provider'     => 'google',
		'model_api_key'      => 'test-key',
		'model_name'         => '',
		'model_base'         => '',
		'allowed_tables'     => array( 'wp_posts', 'wp_postmeta', 'wp_comments' ),
		'blocked_columns'    => array( 'post_password', 'user_pass' ),
		'custom_instruction' => 'Orders are posts with post_type = "shop_order".',
	)
);

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
 * Answer the next call with this text, as the provider would wrap it.
 *
 * @param string $shape google or openai.
 * @param string $text  Model output.
 * @return void
 */
function answer_with( $shape, $text ) {
	WWD_Test_HTTP::reset();

	WWD_Test_HTTP::$response = 'google' === $shape
		? array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => $text ) ) ) ) ) )
		: array( 'choices' => array( array( 'message' => array( 'content' => $text ) ) ) );
}

/**
 * Error code of a result, or the empty string.
 *
 * @param mixed $result Result.
 * @return string
 */
function error_code( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}

// ---------------------------------------------------------------------------
// The schema the model is shown
// ---------------------------------------------------------------------------

$schema = WWD_Schema::prompt_text();

check( 'the schema is DDL the model can read', false !== strpos( $schema, 'CREATE TABLE `wp_posts` (' ) );
check( 'shared tables are in it', false !== strpos( $schema, '`wp_postmeta`' ) );
check( 'tables nobody shared are not', false === strpos( $schema, 'wp_users' ) );
check( 'blocked columns never appear', false === strpos( $schema, 'post_password' ) );
check( 'the joins WordPress never declares are spelled out', false !== strpos( $schema, 'wp_postmeta.post_id = wp_posts.ID' ) );
check( 'so is what a table means', false !== strpos( $schema, 'Custom fields for posts' ) );

// ---------------------------------------------------------------------------
// What goes out
// ---------------------------------------------------------------------------

$engine = new WWD_Engine_Direct();

answer_with( 'google', '{"sql":"SELECT 1 LIMIT 1","explanation":"one"}' );
$engine->start_sql( 'how many posts?', array() );
$sent = WWD_Test_HTTP::$last;

check( 'Google is called on generateContent', false !== strpos( $sent['url'], '/models/gemini-3.6-flash:generateContent' ), $sent['url'] );
check( 'the key travels in a header, not the URL', 'test-key' === $sent['headers']['x-goog-api-key'] && false === strpos( $sent['url'], 'test-key' ) );
check( 'JSON mode is asked for', 'application/json' === $sent['body']['generationConfig']['responseMimeType'] );
check( 'the model is told not to guess', 0 === $sent['body']['generationConfig']['temperature'] );

$prompt = $sent['body']['contents'][0]['parts'][0]['text'];

check( 'the question is sent', false !== strpos( $prompt, 'how many posts?' ) );
check( 'the schema is sent with it', false !== strpos( $prompt, 'CREATE TABLE `wp_posts`' ) );
check( 'so is the business context', false !== strpos( $prompt, 'shop_order' ) );
check( 'and today, so "this year" means something', false !== strpos( $prompt, gmdate( 'Y-m-d' ) ) );

$rules = $sent['body']['system_instruction']['parts'][0]['text'];

check( 'the rules demand a single read-only statement', false !== strpos( $rules, 'start with SELECT or WITH' ) );
check( 'the rules demand a LIMIT', false !== strpos( $rules, 'LIMIT' ) );
check( 'the rules name the dialect', false !== strpos( $rules, 'DATE_FORMAT' ) );

// Follow-up questions carry the thread.
answer_with( 'google', '{"sql":"SELECT 2 LIMIT 1"}' );
$engine->start_sql( 'and last year?', array( array( 'question' => 'how many posts?', 'sql' => 'SELECT 1' ) ) );
$prompt = WWD_Test_HTTP::$last['body']['contents'][0]['parts'][0]['text'];

check( 'earlier turns are sent for follow-ups', false !== strpos( $prompt, 'Q: how many posts?' ) );

// OpenAI-compatible providers get the other shape.
WWD_Settings::update( array( 'model_provider' => 'groq' ) );
answer_with( 'openai', '{"sql":"SELECT 3 LIMIT 1"}' );
$engine->start_sql( 'how many comments?', array() );
$sent = WWD_Test_HTTP::$last;

check( 'Groq is called on chat/completions', 'https://api.groq.com/openai/v1/chat/completions' === $sent['url'], $sent['url'] );
check( 'with a bearer token', 'Bearer test-key' === $sent['headers']['Authorization'] );
check( 'and its own JSON mode', 'json_object' === $sent['body']['response_format']['type'] );
check( 'the default model of the provider is used', 'llama-3.3-70b-versatile' === $sent['body']['model'] );

WWD_Settings::update( array( 'model_provider' => 'google' ) );

// ---------------------------------------------------------------------------
// What comes back
// ---------------------------------------------------------------------------

answer_with( 'google', '{"sql":"SELECT COUNT(*) AS posts FROM `wp_posts` LIMIT 1","explanation":"Conta i post."}' );
$result = $engine->start_sql( 'quanti post?', array() );

check( 'the statement is read out of the answer', 'SELECT COUNT(*) AS posts FROM `wp_posts` LIMIT 1' === $result['sql'] );
check( 'a synchronous engine is done in one step', true === $result['done'] && '' === $result['job'] );
check( 'the explanation is kept', 'Conta i post.' === $result['reasoning'] );

// Models wrap JSON in prose or fences often enough to handle it.
answer_with( 'google', "Here you go:\n```json\n{\"sql\": \"SELECT 1 LIMIT 1\"}\n```" );
$result = $engine->start_sql( 'x', array() );

check( 'a fenced answer is still read', 'SELECT 1 LIMIT 1' === $result['sql'] );

answer_with( 'google', 'Sure! {"sql": "SELECT 2 LIMIT 1"} Hope that helps.' );
$result = $engine->start_sql( 'x', array() );

check( 'so is one buried in a sentence', 'SELECT 2 LIMIT 1' === $result['sql'] );

// A model that cannot answer must say so, not invent.
answer_with( 'google', '{"sql":"","error":"Non ci sono dati sul meteo in questo database."}' );
$result = $engine->start_sql( 'che tempo fa?', array() );

check( 'an unanswerable question is an error', is_wp_error( $result ) && 'wwd_no_sql' === $result->get_error_code() );
check( 'and the reason reaches the person', false !== strpos( $result->get_error_message(), 'meteo' ) );

answer_with( 'google', 'no json at all' );
$result = $engine->start_sql( 'x', array() );

check( 'a non-JSON answer fails cleanly', 'wwd_model_not_json' === error_code( $result ) );

// ---------------------------------------------------------------------------
// Charts
// ---------------------------------------------------------------------------

answer_with( 'google', '{"spec":{"mark":"bar","encoding":{"x":{"field":"month","type":"nominal"},"y":{"field":"posts","type":"quantitative"}}},"reason":"Confronto per mese."}' );
$chart = $engine->start_chart( 'post per mese', 'SELECT 1', array( 'month', 'posts' ), array( array( '2026-01', 3 ) ) );

check( 'a chart specification comes back', 'bar' === $chart['chart_type'] && isset( $chart['chart']['encoding']['x'] ) );
check( 'the chart step is done in one call', true === $chart['done'] );
check( 'the reason is kept', 'Confronto per mese.' === $chart['note'] );

$sent = WWD_Test_HTTP::$last['body']['contents'][0]['parts'][0]['text'];

check( 'the model sees the column names', false !== strpos( $sent, 'Columns: month, posts' ) );
check( 'and a sample of the rows', false !== strpos( $sent, '2026-01' ) );

$rules = WWD_Test_HTTP::$last['body']['system_instruction']['parts'][0]['text'];

check( 'the chart rules only allow what the renderer draws', false !== strpos( $rules, 'bar, line, area, point, arc' ) );

answer_with( 'google', '{"spec":null,"reason":"Meglio una tabella."}' );
$chart = $engine->start_chart( 'elenco', 'SELECT 1', array( 'a', 'b' ), array( array( 1, 2 ) ) );

check( 'no chart is a finished answer, not a failure', true === $chart['done'] && null === $chart['chart'] );
check( 'with the reason shown', 'Meglio una tabella.' === $chart['note'] );

// ---------------------------------------------------------------------------
// When the provider says no
// ---------------------------------------------------------------------------

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status   = 401;
WWD_Test_HTTP::$response = array( 'error' => array( 'message' => 'API key not valid' ) );
$result = $engine->start_sql( 'x', array() );

check( 'a refused key is named as such', 'wwd_model_unauthorised' === error_code( $result ) );
check( 'and the provider message is passed on', false !== strpos( $result->get_error_message(), 'API key not valid' ) );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status   = 429;
WWD_Test_HTTP::$response = array( 'error' => array( 'message' => 'Quota exceeded' ) );

check( 'rate limiting is its own error', 'wwd_model_rate_limited' === error_code( $engine->start_sql( 'x', array() ) ) );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status   = 404;
WWD_Test_HTTP::$response = array( 'error' => array( 'message' => 'models/nope is not found' ) );
$result = $engine->start_sql( 'x', array() );

check( 'an unknown model is its own error', 'wwd_model_unknown' === error_code( $result ) );

WWD_Test_HTTP::reset();

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

check( 'a configured model is ready', true === $engine->ready() );

WWD_Settings::update( array( 'model_api_key' => '' ) );

check( 'without a key it is not configured', ! $engine->is_configured() );
check( 'and says what is missing', 'wwd_model_unconfigured' === error_code( $engine->ready() ) );

WWD_Settings::update( array( 'model_api_key' => 'test-key', 'allowed_tables' => array() ) );

check( 'without shared tables it is not ready either', 'wwd_no_tables' === error_code( $engine->ready() ) );

WWD_Settings::update( array( 'allowed_tables' => array( 'wp_posts' ) ) );

// A local OpenAI-compatible server needs no key at all.
WWD_Settings::update(
	array(
		'model_provider' => 'custom',
		'model_api_key'  => '',
		'model_base'     => 'http://localhost:11434/v1',
		'model_name'     => 'qwen2.5-coder:7b',
	)
);

$local = new WWD_Model_Client();

check( 'a local model without a key is still usable', $local->is_ready() );

answer_with( 'openai', '{"sql":"SELECT 1 LIMIT 1"}' );
$engine->start_sql( 'x', array() );

check( 'and is called at its own address', 'http://localhost:11434/v1/chat/completions' === WWD_Test_HTTP::$last['url'], WWD_Test_HTTP::$last['url'] );
check( 'with no Authorization header', ! isset( WWD_Test_HTTP::$last['headers']['Authorization'] ) );

// ---------------------------------------------------------------------------
// When the provider's JSON mode refuses the model's answer
// ---------------------------------------------------------------------------

WWD_Settings::update(
	array(
		'model_provider' => 'groq',
		'model_api_key'  => 'test-key',
		'model_base'     => '',
		'model_name'     => 'some-reasoning-model',
	)
);

$engine = new WWD_Engine_Direct();

// Groq hands back what the model actually wrote. Usually it is fine.
WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 400,
			'response' => array(
				'error' => array(
					'message'           => 'Failed to generate JSON. Please adjust your prompt.',
					'failed_generation' => '{"sql": "SELECT COUNT(*) AS posts FROM `wp_posts` LIMIT 100"}',
				),
			),
		),
	)
);

$result = $engine->start_sql( 'quanti post?', array() );

check( 'a rejected answer is read out of the rejection', ! is_wp_error( $result ) && false !== strpos( $result['sql'], 'SELECT COUNT(*)' ) );
check( 'and costs no second call', 1 === count( WWD_Test_HTTP::$requests ) );

// Reasoning models narrate first; the narration is not the answer.
WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 400,
			'response' => array(
				'error' => array(
					'message'           => 'Failed to generate JSON.',
					'failed_generation' => "<think>The user wants a count. wp_posts has post_status.</think>\n{\"sql\": \"SELECT 1 LIMIT 1\"}",
				),
			),
		),
	)
);

$result = $engine->start_sql( 'x', array() );

check( 'thinking out loud is stripped before reading', ! is_wp_error( $result ) && 'SELECT 1 LIMIT 1' === $result['sql'] );

// Nothing usable in the rejection: ask again without the JSON straitjacket.
WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 400,
			'response' => array( 'error' => array( 'message' => 'Failed to generate JSON. Please adjust your prompt.' ) ),
		),
		array(
			'response' => array(
				'choices' => array( array( 'message' => array( 'content' => 'Sure: {"sql": "SELECT 2 LIMIT 1"}' ) ) ),
			),
		),
	)
);

$result = $engine->start_sql( 'x', array() );

check( 'an unusable rejection buys a second, plainer attempt', 2 === count( WWD_Test_HTTP::$requests ) );
check( 'the retry drops the JSON mode that just failed', ! isset( WWD_Test_HTTP::$requests[1]['body']['response_format'] ) );
check( 'and its answer is read leniently', ! is_wp_error( $result ) && 'SELECT 2 LIMIT 1' === $result['sql'] );

// A 400 about something else is a real error, not a JSON tantrum.
WWD_Test_HTTP::queue(
	array(
		array(
			'status'   => 400,
			'response' => array( 'error' => array( 'message' => 'Invalid value for temperature' ) ),
		),
	)
);

$result = $engine->start_sql( 'x', array() );

check( 'an unrelated 400 fails at once', is_wp_error( $result ) && 'wwd_model_http_error' === $result->get_error_code() );
check( 'without a pointless second call', 1 === count( WWD_Test_HTTP::$requests ) );

// Both attempts unreadable: give up, but say so plainly.
WWD_Test_HTTP::queue(
	array(
		array( 'response' => array( 'choices' => array( array( 'message' => array( 'content' => 'no json here' ) ) ) ) ),
		array( 'response' => array( 'choices' => array( array( 'message' => array( 'content' => 'still none' ) ) ) ) ),
	)
);

$result = $engine->start_sql( 'x', array() );

check( 'an unreadable answer is tried twice', 2 === count( WWD_Test_HTTP::$requests ) );
check( 'then reported as a format failure', 'wwd_model_not_json' === error_code( $result ) );

WWD_Test_HTTP::reset();
WWD_Settings::update( array( 'model_name' => '' ) );

// ---------------------------------------------------------------------------
// Asking the provider what it has
// ---------------------------------------------------------------------------

WWD_Settings::update(
	array(
		'model_provider' => 'google',
		'model_api_key'  => 'test-key',
		'model_base'     => '',
		'model_name'     => '',
	)
);

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$response = array(
	'models' => array(
		array( 'name' => 'models/gemini-3.6-flash', 'supportedGenerationMethods' => array( 'generateContent' ) ),
		array( 'name' => 'models/gemini-3.6-pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
		array( 'name' => 'models/text-embedding-004', 'supportedGenerationMethods' => array( 'embedContent' ) ),
	),
);

$client = new WWD_Model_Client();
$models = $client->models();

check( 'Google is asked for its catalogue', false !== strpos( WWD_Test_HTTP::$last['url'], '/models' ), WWD_Test_HTTP::$last['url'] );
check( 'with the key in a header', 'test-key' === WWD_Test_HTTP::$last['headers']['x-goog-api-key'] );
check( 'the models/ prefix is stripped', array( 'gemini-3.6-flash', 'gemini-3.6-pro' ) === $models, wp_json_encode( $models ) );
check( 'an embedding model is not offered as a brain', ! in_array( 'text-embedding-004', (array) $models, true ) );

WWD_Settings::update( array( 'model_provider' => 'groq' ) );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$response = array(
	'data' => array(
		array( 'id' => 'llama-4-scout-17b' ),
		array( 'id' => 'whisper-large-v3' ),
		array( 'id' => 'llama-guard-4-12b' ),
		array( 'id' => 'qwen3-32b' ),
	),
);

$client = new WWD_Model_Client();
$models = $client->models();

check( 'an OpenAI-compatible provider is asked the same way', 'https://api.groq.com/openai/v1/models' === WWD_Test_HTTP::$last['url'], WWD_Test_HTTP::$last['url'] );
check( 'with a bearer token', 'Bearer test-key' === WWD_Test_HTTP::$last['headers']['Authorization'] );
check( 'speech and moderation models are left out', array( 'llama-4-scout-17b', 'qwen3-32b' ) === $models, wp_json_encode( $models ) );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$response = array( 'data' => array() );

check( 'an empty catalogue is an error, not an empty picker', 'wwd_model_list_empty' === error_code( ( new WWD_Model_Client() )->models() ) );

WWD_Test_HTTP::reset();
WWD_Test_HTTP::$status   = 401;
WWD_Test_HTTP::$response = array( 'error' => array( 'message' => 'Invalid API Key' ) );

check( 'a refused key is reported as such here too', 'wwd_model_unauthorised' === error_code( ( new WWD_Model_Client() )->models() ) );

WWD_Test_HTTP::reset();

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );

<?php
/**
 * Talks to a language model directly, without any service in between.
 *
 * A WordPress database is a dozen tables: its whole schema fits in a prompt,
 * which is why the plugin can ask a model for SQL itself instead of running a
 * semantic layer. Two shapes cover every provider worth using - Google AI
 * Studio's own, and the OpenAI chat-completions shape that OpenAI, Groq,
 * OpenRouter, Ollama and LM Studio all speak.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * One model, two request shapes, JSON answers.
 */
class WWD_Model_Client {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	protected $provider;

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Model name as the provider spells it.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	protected $base;

	/**
	 * Seconds to wait for an answer.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Smallest answer worth asking for when a model's context is tight.
	 */
	const MIN_BUDGET = 700;

	/**
	 * Providers offered in the settings screen.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'google' => array(
				'label' => __( 'Google AI Studio (free tier)', 'datachat-ai' ),
				'base'  => 'https://generativelanguage.googleapis.com/v1beta',
				'model' => 'gemini-3.6-flash',
				'keys'  => 'https://aistudio.google.com/apikey',
				'shape' => 'google',
			),
			'groq'   => array(
				'label' => __( 'Groq (free tier)', 'datachat-ai' ),
				'base'  => 'https://api.groq.com/openai/v1',
				'model' => 'llama-3.3-70b-versatile',
				'keys'  => 'https://console.groq.com/keys',
				'shape' => 'openai',
			),
			'openai' => array(
				'label' => __( 'OpenAI', 'datachat-ai' ),
				'base'  => 'https://api.openai.com/v1',
				'model' => 'gpt-4.1-mini',
				'keys'  => 'https://platform.openai.com/api-keys',
				'shape' => 'openai',
			),
			'custom' => array(
				'label' => __( 'Anything OpenAI-compatible (Ollama, LM Studio, OpenRouter…)', 'datachat-ai' ),
				'base'  => '',
				'model' => '',
				'keys'  => '',
				'shape' => 'openai',
			),
		);
	}

	/**
	 * Settings of one provider, falling back to Google.
	 *
	 * @param string $provider Provider id.
	 * @return array
	 */
	public static function provider( $provider ) {
		$all = self::providers();

		return isset( $all[ $provider ] ) ? $all[ $provider ] : $all['google'];
	}

	/**
	 * Constructor.
	 *
	 * @param array $overrides Optional provider/api_key/model/base/timeout.
	 */
	public function __construct( array $overrides = array() ) {
		$this->provider = isset( $overrides['provider'] )
			? (string) $overrides['provider']
			: (string) WWD_Settings::get( 'model_provider', 'google' );

		$defaults = self::provider( $this->provider );

		$this->api_key = isset( $overrides['api_key'] )
			? (string) $overrides['api_key']
			: (string) WWD_Settings::get( 'model_api_key' );

		$model = isset( $overrides['model'] ) ? (string) $overrides['model'] : (string) WWD_Settings::get( 'model_name' );
		$base  = isset( $overrides['base'] ) ? (string) $overrides['base'] : (string) WWD_Settings::get( 'model_base' );

		$this->model = '' !== trim( $model ) ? trim( $model ) : $defaults['model'];
		$this->base  = untrailingslashit( '' !== trim( $base ) ? trim( $base ) : $defaults['base'] );

		$this->timeout = isset( $overrides['timeout'] )
			? (int) $overrides['timeout']
			: (int) WWD_Settings::get( 'request_timeout', 30 );
	}

	/**
	 * Whether this client has everything it needs.
	 *
	 * @return bool
	 */
	public function is_ready() {
		if ( '' === $this->base || '' === $this->model ) {
			return false;
		}

		// A local model does not need a key; a hosted one always does.
		return '' !== $this->api_key || 'custom' === $this->provider;
	}

	/**
	 * The model this client will call, for display.
	 *
	 * @return string
	 */
	public function model() {
		return $this->model;
	}

	/**
	 * Ask the model for a JSON object.
	 *
	 * @param string $system   Instructions.
	 * @param string $user     The actual request.
	 * @param int    $max_out  Output token budget.
	 * @return array|WP_Error Decoded JSON object.
	 */
	public function complete( $system, $user, $max_out = 2048 ) {
		if ( ! $this->is_ready() ) {
			return new WP_Error(
				'wwd_model_unconfigured',
				__( 'No model is configured yet. Add an API key under DataChat → Settings.', 'datachat-ai' )
			);
		}

		$shape = self::provider( $this->provider );
		$shape = $shape['shape'];

		/*
		 * Enforced JSON is the better first attempt: it is what keeps a chatty
		 * model from wrapping the answer in pleasantries. But a provider that
		 * validates the output rejects a model whose reasoning runs long or
		 * whose JSON is a little off, and the answer inside that rejection is
		 * usually perfectly usable - so a later attempt asks plainly and reads
		 * the result leniently. A model whose context window cannot hold the
		 * schema plus the room we reserved for an answer gets another go with
		 * less room reserved.
		 */
		$strict  = true;
		$budget  = (int) $max_out;
		$attempt = 0;

		// Newer OpenAI models renamed max_tokens and refuse a temperature they
		// did not choose. Which ones is not knowable from here, so the request
		// adapts to whatever the provider objects to.
		$dialect = array(
			'budget_param' => 'max_tokens',
			'temperature'  => true,
		);

		while ( $attempt < 6 ) {
			$attempt++;

			$request = 'google' === $shape
				? $this->google_request( $system, $user, $budget, $strict )
				: $this->openai_request( $system, $user, $budget, $strict, $dialect );

			$response = wp_remote_post(
				$request['url'],
				array(
					'timeout' => max( 10, $this->timeout ),
					'headers' => $request['headers'],
					'body'    => wp_json_encode( $request['body'] ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wwd_model_unreachable',
					sprintf(
						/* translators: %s: transport error message. */
						__( 'Could not reach the model: %s', 'datachat-ai' ),
						$response->get_error_message()
					),
					array( 'retry' => true )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			$data = is_array( $data ) ? $data : array();

			if ( $code >= 200 && $code < 300 ) {
				$text    = 'google' === $shape ? $this->google_text( $data ) : $this->openai_text( $data );
				$decoded = self::decode_json( $text );

				if ( null !== $decoded ) {
					return $decoded;
				}

				if ( ! $strict ) {
					return new WP_Error(
						'wwd_model_not_json',
						'' === $text
							? __( 'The model returned an empty answer. Try rephrasing the question, or pick another model.', 'datachat-ai' )
							: __( 'The model did not answer in the expected format. Try again, or pick a stronger model.', 'datachat-ai' )
					);
				}

				// Strict mode produced something unreadable: ask again plainly.
				$strict = false;

				continue;
			}

			if ( $strict && $this->rejected_json( $code, $data ) ) {
				// Providers hand back what the model actually wrote, and it is
				// often a good answer with a stray token around it.
				$salvaged = self::decode_json( self::failed_generation( $data ) );

				if ( null !== $salvaged ) {
					return $salvaged;
				}

				$strict = false;

				continue;
			}

			$unsupported = 'google' === $shape ? '' : $this->unsupported_parameter( $code, $data );

			if ( '' !== $unsupported ) {
				if ( 'max_tokens' === $unsupported && 'max_tokens' === $dialect['budget_param'] ) {
					$dialect['budget_param'] = 'max_completion_tokens';

					continue;
				}

				if ( 'temperature' === $unsupported && $dialect['temperature'] ) {
					$dialect['temperature'] = false;

					continue;
				}
			}

			if ( $this->too_long( $code, $data ) ) {
				// The schema plus the room reserved for an answer does not fit
				// this model. Reserve less and try once more; if even a short
				// answer does not fit, the schema itself is too big for it.
				if ( $budget > self::MIN_BUDGET ) {
					$budget = max( self::MIN_BUDGET, (int) floor( $budget / 3 ) );

					continue;
				}

				return new WP_Error(
					'wwd_model_too_long',
					sprintf(
						/* translators: 1: model name, 2: provider message. */
						__( 'The schema of this site does not fit in the context window of "%1$s" (%2$s). Share fewer tables under DataChat → Data & schema, or pick a model with a larger context window.', 'datachat-ai' ),
						$this->model,
						rtrim( self::message( $data ), '.' )
					)
				);
			}

			return $this->http_error( $code, $data, $body );
		}

		return new WP_Error(
			'wwd_model_not_json',
			__( 'The model did not answer in the expected format. Try again, or pick a stronger model.', 'datachat-ai' )
		);
	}

	/**
	 * The parameter a provider says this model will not take.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Decoded body.
	 * @return string Parameter name, or empty when the complaint is elsewhere.
	 */
	protected function unsupported_parameter( $code, array $data ) {
		if ( 400 !== $code && 422 !== $code ) {
			return '';
		}

		$named = isset( $data['error']['param'] ) ? (string) $data['error']['param'] : '';

		if ( '' !== $named ) {
			return $named;
		}

		$message = self::message( $data );

		if ( false === stripos( $message, 'unsupported' ) && false === stripos( $message, 'not supported' ) ) {
			return '';
		}

		// "Unsupported parameter: 'max_tokens' is not supported with this model."
		if ( preg_match( "/'([a-z_]+)'/i", $message, $found ) ) {
			return $found[1];
		}

		return '';
	}

	/**
	 * Whether a rejection means the request did not fit the model.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Decoded body.
	 * @return bool
	 */
	protected function too_long( $code, array $data ) {
		if ( 400 !== $code && 413 !== $code ) {
			return false;
		}

		$message = self::message( $data );
		$code_id = isset( $data['error']['code'] ) ? (string) $data['error']['code'] : '';

		if ( 'context_length_exceeded' === $code_id ) {
			return true;
		}

		return (bool) preg_match(
			'/(reduce the length|context length|context window|too many tokens|maximum.{0,20}tokens|token limit)/i',
			$message
		);
	}

	/**
	 * The provider's own wording of what went wrong.
	 *
	 * @param array $data Decoded body.
	 * @return string
	 */
	protected static function message( array $data ) {
		foreach ( array( array( 'error', 'message' ), array( 'message' ), array( 'error' ) ) as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				$value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null;
			}

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Whether a rejection is the provider complaining about JSON rather than
	 * about the request itself.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Decoded body.
	 * @return bool
	 */
	protected function rejected_json( $code, array $data ) {
		if ( 400 !== $code ) {
			return false;
		}

		if ( '' !== self::failed_generation( $data ) ) {
			return true;
		}

		$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';

		return false !== stripos( $message, 'json' );
	}

	/**
	 * What the model wrote, as returned inside a JSON-mode rejection.
	 *
	 * @param array $data Decoded body.
	 * @return string
	 */
	protected static function failed_generation( array $data ) {
		foreach ( array( array( 'error', 'failed_generation' ), array( 'failed_generation' ) ) as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				$value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null;
			}

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * What this provider currently offers.
	 *
	 * Providers retire models on their own schedule, so any list written into
	 * a release is wrong eventually - as both of this plugin's defaults were,
	 * within a week of each other. Asking is the only thing that stays true.
	 *
	 * @return array|WP_Error Model ids, newest naming first as the provider
	 *                        returns them.
	 */
	public function models() {
		if ( '' === $this->base ) {
			return new WP_Error(
				'wwd_model_unconfigured',
				__( 'Set an API base URL first.', 'datachat-ai' )
			);
		}

		$shape   = self::provider( $this->provider );
		$shape   = $shape['shape'];
		$headers = array( 'Content-Type' => 'application/json' );

		if ( 'google' === $shape ) {
			$url                     = $this->base . '/models?pageSize=200';
			$headers['x-goog-api-key'] = $this->api_key;
		} else {
			$url = $this->base . '/models';

			if ( '' !== $this->api_key ) {
				$headers['Authorization'] = 'Bearer ' . $this->api_key;
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => max( 10, min( 30, $this->timeout ) ),
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wwd_model_unreachable',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach the model: %s', 'datachat-ai' ),
					$response->get_error_message()
				),
				array( 'retry' => true )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			return $this->http_error( $code, is_array( $data ) ? $data : array(), $body );
		}

		$models = 'google' === $shape ? self::google_models( $data ) : self::openai_models( $data );

		if ( empty( $models ) ) {
			return new WP_Error(
				'wwd_model_list_empty',
				__( 'The provider did not list any usable model for this key.', 'datachat-ai' )
			);
		}

		return $models;
	}

	/**
	 * Chat models out of a Google listing.
	 *
	 * @param mixed $data Decoded body.
	 * @return array
	 */
	protected static function google_models( $data ) {
		if ( ! is_array( $data ) || empty( $data['models'] ) ) {
			return array();
		}

		$models = array();

		foreach ( $data['models'] as $model ) {
			if ( empty( $model['name'] ) ) {
				continue;
			}

			// Embedding and image models live in the same list; only the ones
			// that answer generateContent are any use here.
			$methods = isset( $model['supportedGenerationMethods'] )
				? (array) $model['supportedGenerationMethods']
				: array();

			if ( $methods && ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}

			$models[] = preg_replace( '#^models/#', '', (string) $model['name'] );
		}

		return array_values( array_unique( $models ) );
	}

	/**
	 * Chat models out of an OpenAI-compatible listing.
	 *
	 * @param mixed $data Decoded body.
	 * @return array
	 */
	protected static function openai_models( $data ) {
		if ( ! is_array( $data ) || empty( $data['data'] ) ) {
			return array();
		}

		$models = array();

		foreach ( $data['data'] as $model ) {
			if ( empty( $model['id'] ) ) {
				continue;
			}

			$id = (string) $model['id'];

			// These listings mix in speech, embedding and moderation models,
			// which cannot answer a question. Matching on the name is a
			// heuristic, but the alternative is offering models that fail.
			if ( preg_match( '/(whisper|tts|embed|moderation|guard|stable-diffusion|dall-e|realtime|transcribe|audio|image|video|sora|speech)/i', $id ) ) {
				continue;
			}

			$models[] = $id;
		}

		return array_values( array_unique( $models ) );
	}

	/**
	 * Cheap call that proves the key and model work.
	 *
	 * @return array|WP_Error
	 */
	public function ping() {
		$result = $this->complete(
			'You answer with JSON only.',
			'Reply with exactly {"ok": true}',
			32
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'       => true,
			'provider' => $this->provider,
			'model'    => $this->model,
		);
	}

	/**
	 * Google AI Studio request.
	 *
	 * @param string $system  Instructions.
	 * @param string $user    Request.
	 * @param int    $max_out Output tokens.
	 * @return array
	 */
	protected function google_request( $system, $user, $max_out, $strict = true ) {
		$generation = array(
			'temperature'     => 0,
			'maxOutputTokens' => (int) $max_out,
		);

		if ( $strict ) {
			$generation['responseMimeType'] = 'application/json';
		}

		return array(
			// The key goes in a header, not the query string: URLs end up in
			// logs and proxies, and this one would carry the secret.
			'url'     => $this->base . '/models/' . rawurlencode( $this->model ) . ':generateContent',
			'headers' => array(
				'Content-Type'     => 'application/json',
				'x-goog-api-key'   => $this->api_key,
			),
			'body'    => array(
				'system_instruction' => array(
					'parts' => array( array( 'text' => $system ) ),
				),
				'contents'           => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $user ) ),
					),
				),
				'generationConfig'   => $generation,
			),
		);
	}

	/**
	 * OpenAI-compatible request.
	 *
	 * @param string $system  Instructions.
	 * @param string $user    Request.
	 * @param int    $max_out Output tokens.
	 * @return array
	 */
	protected function openai_request( $system, $user, $max_out, $strict = true, $dialect = array() ) {
		$dialect = array_merge(
			array(
				'budget_param' => 'max_tokens',
				'temperature'  => true,
			),
			$dialect
		);

		$headers = array( 'Content-Type' => 'application/json' );

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$body = array( 'model' => $this->model );

		if ( $dialect['temperature'] ) {
			$body['temperature'] = 0;
		}

		$body[ $dialect['budget_param'] ] = (int) $max_out;

		if ( $strict ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		return array(
			'url'     => $this->base . '/chat/completions',
			'headers' => $headers,
			'body'    => $body + array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
			),
		);
	}


	/**
	 * Text out of a Google response.
	 *
	 * @param mixed $data Decoded body.
	 * @return string
	 */
	protected function google_text( $data ) {
		if ( ! is_array( $data ) || empty( $data['candidates'][0]['content']['parts'] ) ) {
			return '';
		}

		$text = '';

		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}

		return $text;
	}

	/**
	 * Text out of an OpenAI-compatible response.
	 *
	 * @param mixed $data Decoded body.
	 * @return string
	 */
	protected function openai_text( $data ) {
		return isset( $data['choices'][0]['message']['content'] )
			? (string) $data['choices'][0]['message']['content']
			: '';
	}

	/**
	 * Turn an HTTP failure into something an administrator can act on.
	 *
	 * @param int    $code Status code.
	 * @param array  $data Decoded body.
	 * @param string $body Raw body.
	 * @return WP_Error
	 */
	protected function http_error( $code, array $data, $body ) {
		$detail = self::message( $data );

		if ( '' === $detail ) {
			$detail = trim( wp_strip_all_tags( substr( $body, 0, 200 ) ) );
		}

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'wwd_model_unauthorised',
				sprintf(
					/* translators: %s: provider message. */
					__( 'The model provider refused the API key: %s', 'datachat-ai' ),
					$detail
				)
			);
		}

		if ( 429 === $code ) {
			return new WP_Error(
				'wwd_model_rate_limited',
				sprintf(
					/* translators: %s: provider message. */
					__( 'The model provider is rate limiting this key: %s', 'datachat-ai' ),
					$detail
				),
				array( 'retry' => true )
			);
		}

		if ( 404 === $code ) {
			// Providers retire models on their own schedule, so the default
			// shipped with any release eventually goes stale. The message
			// usually names the replacement: say where to put it.
			return new WP_Error(
				'wwd_model_unknown',
				sprintf(
					/* translators: 1: model name, 2: provider message. */
					__( 'The provider does not know the model "%1$s": %2$s Under DataChat → Settings, press "List what this key can use" and pick one.', 'datachat-ai' ),
					$this->model,
					rtrim( $detail, '.' ) . '.'
				)
			);
		}

		return new WP_Error(
			'wwd_model_http_error',
			sprintf(
				/* translators: 1: HTTP status, 2: provider message. */
				__( 'The model provider answered HTTP %1$d: %2$s', 'datachat-ai' ),
				$code,
				$detail
			),
			// A busy or broken provider (503 "high demand" on a free tier is
			// the common one) is not a reason to throw the question away.
			array( 'retry' => $code >= 500 )
		);
	}

	/**
	 * Whether an error is worth trying again in a moment.
	 *
	 * @param mixed $error Anything; only a WP_Error can be retryable.
	 * @return bool
	 */
	public static function is_retryable( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();

		return is_array( $data ) && ! empty( $data['retry'] );
	}

	/**
	 * Decode a JSON object, tolerating the wrappers models add.
	 *
	 * Even in JSON mode a model will occasionally wrap the object in a fenced
	 * code block or put a sentence in front of it, and throwing away a good
	 * answer over that would be silly.
	 *
	 * @param string $text Model output.
	 * @return array|null
	 */
	public static function decode_json( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return null;
		}

		// Reasoning models narrate before answering, inside <think> tags that
		// are not part of the answer. An unclosed one means the budget ran out
		// mid-thought: there is nothing usable after it either way.
		$text = preg_replace( '#<think>.*?</think>#is', '', $text );
		$text = preg_replace( '#<think>.*$#is', '', $text );
		$text = trim( (string) $text );

		$decoded = json_decode( $text, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $text, $fenced ) ) {
			$decoded = json_decode( trim( $fenced[1] ), true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}
}

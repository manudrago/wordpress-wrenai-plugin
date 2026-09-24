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
	 * Providers offered in the settings screen.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'google' => array(
				'label' => __( 'Google AI Studio (free tier)', 'wp-wren-dashboards' ),
				'base'  => 'https://generativelanguage.googleapis.com/v1beta',
				'model' => 'gemini-3.6-flash',
				'keys'  => 'https://aistudio.google.com/apikey',
				'shape' => 'google',
			),
			'groq'   => array(
				'label' => __( 'Groq (free tier)', 'wp-wren-dashboards' ),
				'base'  => 'https://api.groq.com/openai/v1',
				'model' => 'llama-3.3-70b-versatile',
				'keys'  => 'https://console.groq.com/keys',
				'shape' => 'openai',
			),
			'openai' => array(
				'label' => __( 'OpenAI', 'wp-wren-dashboards' ),
				'base'  => 'https://api.openai.com/v1',
				'model' => 'gpt-4.1-mini',
				'keys'  => 'https://platform.openai.com/api-keys',
				'shape' => 'openai',
			),
			'custom' => array(
				'label' => __( 'Anything OpenAI-compatible (Ollama, LM Studio, OpenRouter…)', 'wp-wren-dashboards' ),
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
				__( 'No model is configured yet. Add an API key under Wren AI → Settings.', 'wp-wren-dashboards' )
			);
		}

		$shape = self::provider( $this->provider );
		$shape = $shape['shape'];

		$request = 'google' === $shape
			? $this->google_request( $system, $user, $max_out )
			: $this->openai_request( $system, $user, $max_out );

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
					__( 'Could not reach the model: %s', 'wp-wren-dashboards' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			return $this->http_error( $code, is_array( $data ) ? $data : array(), $body );
		}

		$text = 'google' === $shape ? $this->google_text( $data ) : $this->openai_text( $data );

		if ( '' === $text ) {
			return new WP_Error(
				'wwd_model_empty',
				__( 'The model returned an empty answer. Try rephrasing the question.', 'wp-wren-dashboards' )
			);
		}

		$decoded = self::decode_json( $text );

		if ( null === $decoded ) {
			return new WP_Error(
				'wwd_model_not_json',
				__( 'The model did not answer in the expected format. Try again, or pick a stronger model.', 'wp-wren-dashboards' )
			);
		}

		return $decoded;
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
	protected function google_request( $system, $user, $max_out ) {
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
				'generationConfig'   => array(
					'temperature'      => 0,
					'responseMimeType' => 'application/json',
					'maxOutputTokens'  => (int) $max_out,
				),
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
	protected function openai_request( $system, $user, $max_out ) {
		$headers = array( 'Content-Type' => 'application/json' );

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		return array(
			'url'     => $this->base . '/chat/completions',
			'headers' => $headers,
			'body'    => array(
				'model'           => $this->model,
				'temperature'     => 0,
				'max_tokens'      => (int) $max_out,
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array(
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
		$detail = '';

		foreach ( array( array( 'error', 'message' ), array( 'message' ), array( 'error' ) ) as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				$value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null;
			}

			if ( is_string( $value ) && '' !== $value ) {
				$detail = $value;

				break;
			}
		}

		if ( '' === $detail ) {
			$detail = trim( wp_strip_all_tags( substr( $body, 0, 200 ) ) );
		}

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'wwd_model_unauthorised',
				sprintf(
					/* translators: %s: provider message. */
					__( 'The model provider refused the API key: %s', 'wp-wren-dashboards' ),
					$detail
				)
			);
		}

		if ( 429 === $code ) {
			return new WP_Error(
				'wwd_model_rate_limited',
				sprintf(
					/* translators: %s: provider message. */
					__( 'The model provider is rate limiting this key: %s', 'wp-wren-dashboards' ),
					$detail
				)
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
					__( 'The provider does not know the model "%1$s": %2$s Put a current model name in the Model field under Wren AI → Settings.', 'wp-wren-dashboards' ),
					$this->model,
					rtrim( $detail, '.' ) . '.'
				)
			);
		}

		return new WP_Error(
			'wwd_model_http_error',
			sprintf(
				/* translators: 1: HTTP status, 2: provider message. */
				__( 'The model provider answered HTTP %1$d: %2$s', 'wp-wren-dashboards' ),
				$code,
				$detail
			)
		);
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

<?php
/**
 * HTTP client for the Wren AI service REST API.
 *
 * Endpoints follow wren-ai-service (Wren AI self-hosted / Wren GenBI):
 *   POST   /v1/semantics-preparations
 *   GET    /v1/semantics-preparations/{mdl_hash}/status
 *   POST   /v1/asks
 *   GET    /v1/asks/{query_id}/result
 *   PATCH  /v1/asks/{query_id}
 *   POST   /v1/charts
 *   GET    /v1/charts/{query_id}
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Wren AI.
 */
class WWD_Wren_Client {

	/**
	 * Base URL of the Wren AI service.
	 *
	 * @var string
	 */
	protected $endpoint;

	/**
	 * Path prefix of the versioned API.
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * Optional bearer token.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->endpoint = untrailingslashit( (string) WWD_Settings::get( 'endpoint' ) );
		$this->prefix   = '/' . trim( (string) WWD_Settings::get( 'api_prefix', '/v1' ), '/' );
		$this->api_key  = (string) WWD_Settings::get( 'api_key' );
		$this->timeout  = (int) WWD_Settings::get( 'request_timeout', 20 );
	}

	/**
	 * Perform a request against the Wren AI API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path below the API prefix, e.g. "/asks".
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error Decoded response.
	 */
	public function request( $method, $path, $body = null ) {
		if ( '' === $this->endpoint ) {
			return new WP_Error( 'wwd_no_endpoint', __( 'The Wren AI endpoint is not configured.', 'wp-wren-dashboards' ) );
		}

		$url = $this->endpoint . $this->prefix . $path;

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);

		if ( '' !== $this->api_key ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
		}

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		/**
		 * Filters the arguments of every Wren AI request.
		 *
		 * @param array  $args   wp_remote_request arguments.
		 * @param string $url    Full URL.
		 * @param string $method HTTP method.
		 */
		$args = apply_filters( 'wwd_request_args', $args, $url, $method );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wwd_http_error',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach Wren AI: %s', 'wp-wren-dashboards' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';

			if ( is_array( $data ) ) {
				if ( isset( $data['detail'] ) ) {
					$detail = is_scalar( $data['detail'] ) ? (string) $data['detail'] : wp_json_encode( $data['detail'] );
				} elseif ( isset( $data['message'] ) ) {
					$detail = (string) $data['message'];
				}
			}

			if ( '' === $detail ) {
				$detail = substr( wp_strip_all_tags( (string) $raw ), 0, 300 );
			}

			return new WP_Error(
				'wwd_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail. */
					__( 'Wren AI returned HTTP %1$d: %2$s', 'wp-wren-dashboards' ),
					$code,
					$detail
				),
				array( 'status' => $code )
			);
		}

		if ( null === $data && '' !== trim( (string) $raw ) ) {
			return new WP_Error( 'wwd_bad_json', __( 'Wren AI returned a response that could not be decoded.', 'wp-wren-dashboards' ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Ping the service.
	 *
	 * @return array|WP_Error
	 */
	public function health() {
		$url = $this->endpoint . '/health';

		$args = array(
			'timeout' => min( 10, $this->timeout ),
			'headers' => array( 'Accept' => 'application/json' ),
		);

		if ( '' !== $this->api_key ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wwd_http_error', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wwd_api_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Wren AI health check failed with HTTP %d.', 'wp-wren-dashboards' ),
					$code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array( 'status' => 'ok' );
	}

	/**
	 * Fields every POST body carries.
	 *
	 * @return array
	 */
	protected function base_payload() {
		$project_id = (string) WWD_Settings::get( 'project_id' );

		$payload = array(
			'request_from'   => 'api',
			'configurations' => array(
				'language' => WWD_Settings::language(),
				'timezone' => array( 'name' => wp_timezone_string() ),
			),
		);

		if ( '' !== $project_id ) {
			$payload['project_id'] = $project_id;
		}

		return $payload;
	}

	/**
	 * Index a semantic model so questions can be answered against it.
	 *
	 * @param array  $mdl      MDL document.
	 * @param string $mdl_hash Hash identifying the model.
	 * @return array|WP_Error
	 */
	public function deploy_semantics( array $mdl, $mdl_hash ) {
		$payload = array_merge(
			$this->base_payload(),
			array(
				'mdl'      => wp_json_encode( $mdl ),
				'mdl_hash' => $mdl_hash,
			)
		);

		return $this->request( 'POST', '/semantics-preparations', $payload );
	}

	/**
	 * Indexing status of a semantic model.
	 *
	 * @param string $mdl_hash Hash identifying the model.
	 * @return array|WP_Error
	 */
	public function semantics_status( $mdl_hash ) {
		return $this->request( 'GET', '/semantics-preparations/' . rawurlencode( $mdl_hash ) . '/status' );
	}

	/**
	 * Start a text-to-SQL job.
	 *
	 * @param string $question  Natural language question.
	 * @param array  $histories Previous {question, sql} pairs for follow-ups.
	 * @return array|WP_Error Response containing query_id.
	 */
	public function ask( $question, array $histories = array() ) {
		$instruction = trim( (string) WWD_Settings::get( 'custom_instruction' ) );

		$payload = array_merge(
			$this->base_payload(),
			array(
				'query'     => $question,
				'mdl_hash'  => (string) WWD_Settings::get( 'mdl_hash' ),
				'histories' => array_values( $histories ),
			)
		);

		if ( '' !== $instruction ) {
			$payload['custom_instruction'] = $instruction;
		}

		return $this->request( 'POST', '/asks', $payload );
	}

	/**
	 * Result of a text-to-SQL job.
	 *
	 * @param string $query_id Job id.
	 * @return array|WP_Error
	 */
	public function ask_result( $query_id ) {
		return $this->request( 'GET', '/asks/' . rawurlencode( $query_id ) . '/result' );
	}

	/**
	 * Ask Wren AI to stop working on a question.
	 *
	 * @param string $query_id Job id.
	 * @return array|WP_Error
	 */
	public function stop_ask( $query_id ) {
		return $this->request( 'PATCH', '/asks/' . rawurlencode( $query_id ), array( 'status' => 'stopped' ) );
	}

	/**
	 * Start a chart generation job for a result set.
	 *
	 * @param string $question Original question.
	 * @param string $sql      Statement that produced the data.
	 * @param array  $columns  Column names.
	 * @param array  $rows     Rows as positional arrays.
	 * @return array|WP_Error Response containing query_id.
	 */
	public function chart( $question, $sql, array $columns, array $rows ) {
		$instruction = trim( (string) WWD_Settings::get( 'custom_instruction' ) );
		$sample      = array_slice( $rows, 0, (int) apply_filters( 'wwd_chart_sample_rows', 200 ) );

		$payload = array_merge(
			$this->base_payload(),
			array(
				'query'                        => $question,
				'sql'                          => $sql,
				'data'                         => array(
					'columns' => array_values( $columns ),
					'data'    => $sample,
				),
				'remove_data_from_chart_schema' => true,
			)
		);

		if ( '' !== $instruction ) {
			$payload['custom_instruction'] = $instruction;
		}

		return $this->request( 'POST', '/charts', $payload );
	}

	/**
	 * Result of a chart generation job.
	 *
	 * @param string $query_id Job id.
	 * @return array|WP_Error
	 */
	public function chart_result( $query_id ) {
		return $this->request( 'GET', '/charts/' . rawurlencode( $query_id ) );
	}
}

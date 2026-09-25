<?php
/**
 * The engine that talks to a Wren AI service.
 *
 * Worth running when the schema is large, already modelled in Wren, or shared
 * with other tools. It answers asynchronously: every call hands back a job id
 * the ask session polls.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Text-to-SQL and charts through wren-ai-service.
 */
class WWD_Engine_Wren extends WWD_Engine {

	/**
	 * Name for the settings screen.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Wren AI service', 'datachat-ai' );
	}

	/**
	 * Whether an endpoint and a deployed model exist.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) WWD_Settings::get( 'endpoint' ) )
			&& '' !== (string) WWD_Settings::get( 'mdl_hash' );
	}

	/**
	 * Make sure the deployed model finished indexing.
	 *
	 * Deploying only hands the model to Wren AI; the indexing that follows can
	 * fail on its own (an unreachable embedder, most often), and a question
	 * asked against a half-built index simply hangs while it searches.
	 *
	 * @return true|WP_Error
	 */
	public function ready() {
		if ( '' === (string) WWD_Settings::get( 'mdl_hash' ) ) {
			return new WP_Error(
				'wwd_not_synced',
				__( 'The database schema has not been shared with Wren AI yet. An administrator has to run a schema sync first.', 'datachat-ai' )
			);
		}

		if ( WWD_Settings::get( 'mdl_ready' ) ) {
			return true;
		}

		$client = new WWD_Wren_Client();
		$status = $client->semantics_status( (string) WWD_Settings::get( 'mdl_hash' ) );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$state = isset( $status['status'] ) ? $status['status'] : '';

		if ( 'finished' === $state ) {
			WWD_Settings::update( array( 'mdl_ready' => 1 ) );

			return true;
		}

		if ( 'indexing' === $state ) {
			return new WP_Error( 'wwd_indexing', __( 'Wren AI is still indexing the database schema. Try again in a minute.', 'datachat-ai' ) );
		}

		$detail = isset( $status['error']['message'] ) ? (string) $status['error']['message'] : '';

		return new WP_Error(
			'wwd_index_failed',
			sprintf(
				/* translators: %s: error detail from Wren AI. */
				__( 'Wren AI could not index the database schema, so questions cannot be answered yet. Deploy the schema again from Data & schema. %s', 'datachat-ai' ),
				$detail
			)
		);
	}

	/**
	 * Connectivity check.
	 *
	 * @return array|WP_Error
	 */
	public function health() {
		$client = new WWD_Wren_Client();
		$result = $client->health();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'service' => $result,
		);
	}

	/**
	 * Start a text-to-SQL job.
	 *
	 * @param string $question Natural language question.
	 * @param array  $thread   Previous turns.
	 * @return array|WP_Error
	 */
	public function start_sql( $question, array $thread ) {
		$client   = new WWD_Wren_Client();
		$response = $client->ask( $question, $thread );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['query_id'] ) ) {
			return new WP_Error( 'wwd_no_query_id', __( 'Wren AI did not start the query.', 'datachat-ai' ) );
		}

		$result          = $this->sql_result();
		$result['job']   = (string) $response['query_id'];
		$result['stage'] = __( 'Understanding the question…', 'datachat-ai' );

		return $result;
	}

	/**
	 * Advance a text-to-SQL job.
	 *
	 * @param string $job Job id.
	 * @return array|WP_Error
	 */
	public function poll_sql( $job ) {
		$client = new WWD_Wren_Client();
		$answer = $client->ask_result( (string) $job );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$status = isset( $answer['status'] ) ? $answer['status'] : '';
		$result = $this->sql_result();

		$stages = array(
			'understanding' => __( 'Understanding the question…', 'datachat-ai' ),
			'searching'     => __( 'Looking through your tables…', 'datachat-ai' ),
			'planning'      => __( 'Planning the query…', 'datachat-ai' ),
			'generating'    => __( 'Writing SQL…', 'datachat-ai' ),
			'correcting'    => __( 'Checking the SQL…', 'datachat-ai' ),
		);

		if ( isset( $stages[ $status ] ) ) {
			$result['stage'] = $stages[ $status ];

			return $result;
		}

		if ( 'failed' === $status || 'stopped' === $status ) {
			$message = __( 'Wren AI could not answer this question.', 'datachat-ai' );

			if ( ! empty( $answer['error']['message'] ) ) {
				$message = (string) $answer['error']['message'];
			}

			return new WP_Error( 'wwd_ask_failed', $message );
		}

		if ( 'finished' !== $status ) {
			return $result;
		}

		$sql = '';

		if ( ! empty( $answer['response'][0]['sql'] ) ) {
			$sql = (string) $answer['response'][0]['sql'];
		}

		if ( '' === $sql ) {
			$message = __( 'Wren AI answered without a query, so there is nothing to chart. Try rephrasing the question in terms of your data.', 'datachat-ai' );

			if ( ! empty( $answer['error']['message'] ) ) {
				$message = (string) $answer['error']['message'];
			}

			return new WP_Error( 'wwd_no_sql', $message );
		}

		$result['done'] = true;
		$result['sql']  = $sql;

		if ( ! empty( $answer['sql_generation_reasoning'] ) ) {
			$result['reasoning'] = (string) $answer['sql_generation_reasoning'];
		}

		return $result;
	}

	/**
	 * Abandon a running question.
	 *
	 * @param string $job Job id.
	 * @return void
	 */
	public function stop_sql( $job ) {
		if ( '' === (string) $job ) {
			return;
		}

		$client = new WWD_Wren_Client();
		$client->stop_ask( (string) $job );
	}

	/**
	 * Start a chart job.
	 *
	 * @param string $question Question that produced the data.
	 * @param string $sql      Statement that ran.
	 * @param array  $columns  Column names.
	 * @param array  $rows     Result rows.
	 * @return array|WP_Error
	 */
	public function start_chart( $question, $sql, array $columns, array $rows ) {
		$client = new WWD_Wren_Client();
		$chart  = $client->chart( $question, $sql, $columns, $rows );

		if ( is_wp_error( $chart ) ) {
			return $chart;
		}

		if ( empty( $chart['query_id'] ) ) {
			return new WP_Error( 'wwd_no_chart_id', __( 'Wren AI did not start the chart.', 'datachat-ai' ) );
		}

		$result        = $this->chart_result();
		$result['job'] = (string) $chart['query_id'];

		return $result;
	}

	/**
	 * Advance a chart job.
	 *
	 * @param string $job Job id.
	 * @return array|WP_Error
	 */
	public function poll_chart( $job ) {
		$client = new WWD_Wren_Client();
		$answer = $client->chart_result( (string) $job );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$status = isset( $answer['status'] ) ? $answer['status'] : '';
		$result = $this->chart_result();

		if ( in_array( $status, array( 'fetching', 'generating' ), true ) ) {
			return $result;
		}

		$result['done'] = true;

		if ( 'finished' === $status && ! empty( $answer['response']['chart_schema'] ) ) {
			$result['chart']      = $answer['response']['chart_schema'];
			$result['chart_type'] = isset( $answer['response']['chart_type'] ) ? (string) $answer['response']['chart_type'] : '';
			$result['note']       = isset( $answer['response']['reasoning'] ) ? (string) $answer['response']['reasoning'] : '';
		} elseif ( ! empty( $answer['error']['message'] ) ) {
			$result['note'] = (string) $answer['error']['message'];
		}

		return $result;
	}
}

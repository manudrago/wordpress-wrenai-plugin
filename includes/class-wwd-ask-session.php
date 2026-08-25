<?php
/**
 * The lifecycle of a single question: text-to-SQL, execution, chart.
 *
 * Wren AI answers asynchronously, so the work is modelled as a small state
 * machine kept in a transient. Every poll from the browser advances it by one
 * step, which keeps each PHP request short instead of blocking for a minute.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives one question from text to dashboard panel.
 */
class WWD_Ask_Session {

	const TRANSIENT_PREFIX = 'wwd_ask_';
	const THREAD_PREFIX    = 'wwd_thread_';
	const TTL              = 1800;
	const MAX_STEPS        = 180;

	/**
	 * Session state.
	 *
	 * @var array
	 */
	protected $state;

	/**
	 * Constructor.
	 *
	 * @param array $state Session state.
	 */
	protected function __construct( array $state ) {
		$this->state = $state;
	}

	/**
	 * Start a new question.
	 *
	 * @param string $question    Natural language question.
	 * @param bool   $keep_thread Whether to send previous turns as context.
	 * @return WWD_Ask_Session|WP_Error
	 */
	public static function start( $question, $keep_thread = true ) {
		$question = trim( wp_strip_all_tags( (string) $question ) );

		if ( '' === $question ) {
			return new WP_Error( 'wwd_empty_question', __( 'Please type a question first.', 'wp-wren-dashboards' ) );
		}

		if ( mb_strlen( $question ) > 1000 ) {
			return new WP_Error( 'wwd_long_question', __( 'That question is too long. Please shorten it.', 'wp-wren-dashboards' ) );
		}

		if ( '' === (string) WWD_Settings::get( 'mdl_hash' ) ) {
			return new WP_Error( 'wwd_not_synced', __( 'The database schema has not been shared with Wren AI yet. An administrator has to run a schema sync first.', 'wp-wren-dashboards' ) );
		}

		$client   = new WWD_Wren_Client();
		$response = $client->ask( $question, $keep_thread ? self::thread() : array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['query_id'] ) ) {
			return new WP_Error( 'wwd_no_query_id', __( 'Wren AI did not start the query.', 'wp-wren-dashboards' ) );
		}

		$state = array(
			'id'             => wp_generate_uuid4(),
			'user_id'        => get_current_user_id(),
			'question'       => $question,
			'wren_query_id'  => (string) $response['query_id'],
			'chart_query_id' => '',
			'status'         => 'generating_sql',
			'stage'          => __( 'Understanding the question…', 'wp-wren-dashboards' ),
			'sql'            => '',
			'reasoning'      => '',
			'columns'        => array(),
			'rows'           => array(),
			'row_count'      => 0,
			'truncated'      => false,
			'duration'       => 0,
			'chart'          => null,
			'chart_type'     => '',
			'chart_note'     => '',
			'tables'         => array(),
			'error'          => '',
			'steps'          => 0,
			'started'        => time(),
		);

		$session = new self( $state );
		$session->save();

		return $session;
	}

	/**
	 * Load a session, enforcing ownership.
	 *
	 * @param string $id Session id.
	 * @return WWD_Ask_Session|WP_Error
	 */
	public static function load( $id ) {
		$id = preg_replace( '/[^a-z0-9\-]/i', '', (string) $id );

		if ( '' === $id ) {
			return new WP_Error( 'wwd_bad_session', __( 'Unknown question.', 'wp-wren-dashboards' ) );
		}

		$state = get_transient( self::TRANSIENT_PREFIX . $id );

		if ( ! is_array( $state ) ) {
			return new WP_Error( 'wwd_session_expired', __( 'This question has expired. Please ask it again.', 'wp-wren-dashboards' ) );
		}

		if ( (int) $state['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'wwd_forbidden_session', __( 'This question belongs to somebody else.', 'wp-wren-dashboards' ) );
		}

		return new self( $state );
	}

	/**
	 * Persist the session.
	 *
	 * @return void
	 */
	protected function save() {
		set_transient( self::TRANSIENT_PREFIX . $this->state['id'], $this->state, self::TTL );
	}

	/**
	 * Session id.
	 *
	 * @return string
	 */
	public function id() {
		return $this->state['id'];
	}

	/**
	 * The validated statement, regardless of whether the UI displays SQL.
	 *
	 * @return string
	 */
	public function sql() {
		return (string) $this->state['sql'];
	}

	/**
	 * Advance the state machine by one step.
	 *
	 * @return array The payload for the browser.
	 */
	public function advance() {
		$this->state['steps']++;

		if ( $this->state['steps'] > self::MAX_STEPS ) {
			return $this->fail( __( 'Wren AI took too long to answer. Please try a simpler question.', 'wp-wren-dashboards' ) );
		}

		switch ( $this->state['status'] ) {
			case 'generating_sql':
				$this->poll_sql();
				break;

			case 'running_query':
				$this->run_query();
				break;

			case 'generating_chart':
				$this->poll_chart();
				break;
		}

		$this->save();

		return $this->to_array();
	}

	/**
	 * Poll the text-to-SQL job.
	 *
	 * @return void
	 */
	protected function poll_sql() {
		$client = new WWD_Wren_Client();
		$result = $client->ask_result( $this->state['wren_query_id'] );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );

			return;
		}

		$status = isset( $result['status'] ) ? $result['status'] : '';

		$stages = array(
			'understanding' => __( 'Understanding the question…', 'wp-wren-dashboards' ),
			'searching'     => __( 'Looking through your tables…', 'wp-wren-dashboards' ),
			'planning'      => __( 'Planning the query…', 'wp-wren-dashboards' ),
			'generating'    => __( 'Writing SQL…', 'wp-wren-dashboards' ),
			'correcting'    => __( 'Checking the SQL…', 'wp-wren-dashboards' ),
		);

		if ( isset( $stages[ $status ] ) ) {
			$this->state['stage'] = $stages[ $status ];

			return;
		}

		if ( 'failed' === $status || 'stopped' === $status ) {
			$message = __( 'Wren AI could not answer this question.', 'wp-wren-dashboards' );

			if ( ! empty( $result['error']['message'] ) ) {
				$message = (string) $result['error']['message'];
			}

			$this->fail( $message );

			return;
		}

		if ( 'finished' !== $status ) {
			return;
		}

		if ( ! empty( $result['sql_generation_reasoning'] ) ) {
			$this->state['reasoning'] = (string) $result['sql_generation_reasoning'];
		}

		$sql = '';

		if ( ! empty( $result['response'][0]['sql'] ) ) {
			$sql = (string) $result['response'][0]['sql'];
		}

		if ( '' === $sql ) {
			$message = __( 'Wren AI answered without a query, so there is nothing to chart. Try rephrasing the question in terms of your data.', 'wp-wren-dashboards' );

			if ( ! empty( $result['error']['message'] ) ) {
				$message = (string) $result['error']['message'];
			}

			$this->fail( $message );

			return;
		}

		$this->state['sql']    = $sql;
		$this->state['status'] = 'running_query';
		$this->state['stage']  = __( 'Running the query…', 'wp-wren-dashboards' );
	}

	/**
	 * Validate and execute the generated statement.
	 *
	 * @return void
	 */
	protected function run_query() {
		$started = microtime( true );
		$result  = WWD_Query_Runner::run( $this->state['sql'] );

		if ( is_wp_error( $result ) ) {
			WWD_Logger::log(
				array(
					'question' => $this->state['question'],
					'sql'      => $this->state['sql'],
					'status'   => 'rejected',
					'error'    => $result->get_error_message(),
				)
			);

			$this->fail( $result->get_error_message() );

			return;
		}

		$this->state['sql']       = $result['sql'];
		$this->state['columns']   = $result['columns'];
		$this->state['rows']      = $result['rows'];
		$this->state['row_count'] = $result['row_count'];
		$this->state['truncated'] = $result['truncated'];
		$this->state['duration']  = $result['duration'];
		$this->state['tables']    = WWD_SQL_Guard::referenced_tables( $result['sql'] );

		WWD_Logger::log(
			array(
				'question' => $this->state['question'],
				'sql'      => $result['sql'],
				'status'   => 'ok',
				'rows'     => $result['row_count'],
				'duration' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			)
		);

		self::remember( $this->state['question'], $result['sql'] );

		if ( empty( $result['rows'] ) ) {
			$this->state['status'] = 'done';
			$this->state['stage']  = __( 'No rows matched that question.', 'wp-wren-dashboards' );

			return;
		}

		$client = new WWD_Wren_Client();
		$chart  = $client->chart( $this->state['question'], $result['sql'], $result['columns'], $result['rows'] );

		if ( is_wp_error( $chart ) || empty( $chart['query_id'] ) ) {
			// The data is already there; a missing chart is not a failed answer.
			$this->state['status']     = 'done';
			$this->state['stage']      = __( 'Done.', 'wp-wren-dashboards' );
			$this->state['chart_note'] = is_wp_error( $chart ) ? $chart->get_error_message() : '';

			return;
		}

		$this->state['chart_query_id'] = (string) $chart['query_id'];
		$this->state['status']         = 'generating_chart';
		$this->state['stage']          = __( 'Designing the chart…', 'wp-wren-dashboards' );
	}

	/**
	 * Poll the chart generation job.
	 *
	 * @return void
	 */
	protected function poll_chart() {
		$client = new WWD_Wren_Client();
		$result = $client->chart_result( $this->state['chart_query_id'] );

		if ( is_wp_error( $result ) ) {
			$this->state['status']     = 'done';
			$this->state['stage']      = __( 'Done.', 'wp-wren-dashboards' );
			$this->state['chart_note'] = $result->get_error_message();

			return;
		}

		$status = isset( $result['status'] ) ? $result['status'] : '';

		if ( in_array( $status, array( 'fetching', 'generating' ), true ) ) {
			return;
		}

		if ( 'finished' === $status && ! empty( $result['response']['chart_schema'] ) ) {
			$this->state['chart']      = $result['response']['chart_schema'];
			$this->state['chart_type'] = isset( $result['response']['chart_type'] ) ? (string) $result['response']['chart_type'] : '';
			$this->state['chart_note'] = isset( $result['response']['reasoning'] ) ? (string) $result['response']['reasoning'] : '';
		} elseif ( ! empty( $result['error']['message'] ) ) {
			$this->state['chart_note'] = (string) $result['error']['message'];
		}

		$this->state['status'] = 'done';
		$this->state['stage']  = __( 'Done.', 'wp-wren-dashboards' );
	}

	/**
	 * Mark the session as failed.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	protected function fail( $message ) {
		$this->state['status'] = 'failed';
		$this->state['stage']  = __( 'Failed.', 'wp-wren-dashboards' );
		$this->state['error']  = $message;

		$this->save();

		return $this->to_array();
	}

	/**
	 * Stop a running question.
	 *
	 * @return array
	 */
	public function stop() {
		if ( 'generating_sql' === $this->state['status'] && $this->state['wren_query_id'] ) {
			$client = new WWD_Wren_Client();
			$client->stop_ask( $this->state['wren_query_id'] );
		}

		return $this->fail( __( 'Stopped.', 'wp-wren-dashboards' ) );
	}

	/**
	 * Payload for the browser.
	 *
	 * @return array
	 */
	public function to_array() {
		$show_sql = (bool) WWD_Settings::get( 'show_sql', 1 ) || current_user_can( 'manage_options' );

		return array(
			'id'         => $this->state['id'],
			'status'     => $this->state['status'],
			'stage'      => $this->state['stage'],
			'question'   => $this->state['question'],
			'sql'        => $show_sql ? $this->state['sql'] : '',
			'reasoning'  => $this->state['reasoning'],
			'columns'    => $this->state['columns'],
			'rows'       => $this->state['rows'],
			'row_count'  => $this->state['row_count'],
			'truncated'  => $this->state['truncated'],
			'duration'   => $this->state['duration'],
			'chart'      => $this->state['chart'],
			'chart_type' => $this->state['chart_type'],
			'chart_note' => $this->state['chart_note'],
			'tables'     => $this->state['tables'],
			'error'      => $this->state['error'],
		);
	}

	/**
	 * Conversation history for the current user, used for follow-up questions.
	 *
	 * @return array
	 */
	public static function thread() {
		$stored = get_transient( self::THREAD_PREFIX . get_current_user_id() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Add a turn to the conversation history.
	 *
	 * @param string $question Question asked.
	 * @param string $sql      Statement that answered it.
	 * @return void
	 */
	public static function remember( $question, $sql ) {
		$thread   = self::thread();
		$thread[] = array(
			'question' => $question,
			'sql'      => $sql,
		);

		$max = (int) apply_filters( 'wwd_thread_length', 5 );

		if ( count( $thread ) > $max ) {
			$thread = array_slice( $thread, -$max );
		}

		set_transient( self::THREAD_PREFIX . get_current_user_id(), $thread, HOUR_IN_SECONDS );
	}

	/**
	 * Forget the conversation history of the current user.
	 *
	 * @return void
	 */
	public static function forget_thread() {
		delete_transient( self::THREAD_PREFIX . get_current_user_id() );
	}
}

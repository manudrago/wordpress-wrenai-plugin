<?php
/**
 * The lifecycle of a single question: text-to-SQL, execution, chart.
 *
 * The work is a small state machine kept in a transient, advanced one step per
 * poll from the browser, so no PHP request ever blocks for a minute. An engine
 * that answers immediately simply moves two states in one poll; one that hands
 * back a job to wait on stays in the same state until it is done.
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
	const MAX_STEPS        = 600;

	/**
	 * How many times a busy or unreachable provider is forgiven before the
	 * question is given up on.
	 */
	const MAX_RETRIES = 8;

	/**
	 * Seconds to wait before each retry. The last one repeats.
	 */
	const BACKOFF = array( 2, 4, 8, 15, 30 );

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

		$engine = WWD_Engine::make();
		$ready  = $engine->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$thread  = $keep_thread ? self::thread() : array();
		$started = $engine->start_sql( $question, $thread );

		// A provider that is merely busy has not answered no: the question is
		// kept and the next poll asks again.
		$busy = is_wp_error( $started ) && WWD_Model_Client::is_retryable( $started );

		if ( is_wp_error( $started ) && ! $busy ) {
			return $started;
		}

		$state = array(
			'id'         => wp_generate_uuid4(),
			'user_id'    => get_current_user_id(),
			'question'   => $question,
			'thread'     => $thread,
			'retries'    => 0,
			'retry_at'   => 0,
			'job'        => $busy ? '' : (string) $started['job'],
			'chart_job'  => '',
			'status'     => 'generating_sql',
			'stage'      => __( 'Understanding the question…', 'wp-wren-dashboards' ),
			'sql'        => '',
			'reasoning'  => '',
			'columns'    => array(),
			'rows'       => array(),
			'row_count'  => 0,
			'truncated'  => false,
			'duration'   => 0,
			'chart'      => null,
			'chart_type' => '',
			'chart_note' => '',
			'tables'     => array(),
			'error'      => '',
			'steps'      => 0,
			'started'    => time(),
		);

		if ( $busy ) {
			$state['retries']  = 1;
			$state['retry_at'] = time() + self::BACKOFF[0];
			$state['stage']    = self::busy_stage();
		} else {
			if ( '' !== $started['stage'] ) {
				$state['stage'] = $started['stage'];
			}

			$state['reasoning'] = (string) $started['reasoning'];

			// An engine that answered outright leaves nothing to poll for: the
			// next step is already running the statement.
			if ( ! empty( $started['done'] ) ) {
				$state['sql']    = (string) $started['sql'];
				$state['status'] = 'running_query';
				$state['stage']  = __( 'Running the query…', 'wp-wren-dashboards' );
			}
		}

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

		$state += array(
			'thread'   => array(),
			'retries'  => 0,
			'retry_at' => 0,
		);

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

		/**
		 * Filters how many polls a single question may take before it is given
		 * up on. The default allows several minutes, which a local model on CPU
		 * (Ollama on a small VM) can easily need.
		 *
		 * @param int $steps Maximum polls.
		 */
		$max_steps = (int) apply_filters( 'wwd_max_poll_steps', self::MAX_STEPS );

		if ( $this->state['steps'] > $max_steps ) {
			return $this->fail( __( 'This question took too long to answer. Please try a simpler one.', 'wp-wren-dashboards' ) );
		}

		switch ( $this->state['status'] ) {
			case 'generating_sql':
				// No job to poll means the engine answers in one go and the
				// last attempt found the provider busy.
				if ( '' === $this->state['job'] ) {
					$this->retry_sql();
				} else {
					$this->poll_sql();
				}

				break;

			case 'running_query':
				$this->run_query();
				break;

			case 'generating_chart':
				if ( '' === $this->state['chart_job'] ) {
					$this->retry_chart();
				} else {
					$this->poll_chart();
				}

				break;
		}

		$this->save();

		return $this->to_array();
	}

	/**
	 * What the card says while the provider catches its breath.
	 *
	 * @return string
	 */
	protected static function busy_stage() {
		return __( 'The model is busy right now. Trying again in a moment…', 'wp-wren-dashboards' );
	}

	/**
	 * Wait longer before the next attempt.
	 *
	 * @return void
	 */
	protected function back_off() {
		$backoff = self::BACKOFF;
		$index   = min( $this->state['retries'], count( $backoff ) - 1 );

		$this->state['retries']++;
		$this->state['retry_at'] = time() + $backoff[ $index ];
		$this->state['stage']    = self::busy_stage();
	}

	/**
	 * Whether another attempt is allowed, and due.
	 *
	 * @param WP_Error $error What the last attempt returned.
	 * @return bool
	 */
	protected function may_retry( $error ) {
		/**
		 * Filters how many times a busy provider is forgiven within one
		 * question.
		 *
		 * @param int $retries Attempts.
		 */
		$max = (int) apply_filters( 'wwd_max_provider_retries', self::MAX_RETRIES );

		return WWD_Model_Client::is_retryable( $error ) && $this->state['retries'] < $max;
	}

	/**
	 * Ask a synchronous engine for the statement again.
	 *
	 * @return void
	 */
	protected function retry_sql() {
		if ( time() < (int) $this->state['retry_at'] ) {
			return;
		}

		$result = WWD_Engine::make()->start_sql( $this->state['question'], (array) $this->state['thread'] );

		if ( is_wp_error( $result ) ) {
			if ( $this->may_retry( $result ) ) {
				$this->back_off();

				return;
			}

			$this->fail( $result->get_error_message() );

			return;
		}

		$this->state['retries']  = 0;
		$this->state['retry_at'] = 0;

		if ( '' !== $result['reasoning'] ) {
			$this->state['reasoning'] = $result['reasoning'];
		}

		if ( empty( $result['done'] ) ) {
			$this->state['job']   = (string) $result['job'];
			$this->state['stage'] = '' !== $result['stage'] ? $result['stage'] : __( 'Understanding the question…', 'wp-wren-dashboards' );

			return;
		}

		$this->state['sql']    = $result['sql'];
		$this->state['status'] = 'running_query';
		$this->state['stage']  = __( 'Running the query…', 'wp-wren-dashboards' );
	}

	/**
	 * Ask for the chart again. The data is already on screen's doorstep, so
	 * running out of patience here means a table, not a failure.
	 *
	 * @return void
	 */
	protected function retry_chart() {
		if ( time() < (int) $this->state['retry_at'] ) {
			return;
		}

		$result = WWD_Engine::make()->start_chart(
			$this->state['question'],
			$this->state['sql'],
			$this->state['columns'],
			$this->state['rows']
		);

		if ( is_wp_error( $result ) ) {
			if ( $this->may_retry( $result ) ) {
				$this->back_off();

				return;
			}

			$this->finish_chart( null, '', $result->get_error_message() );

			return;
		}

		if ( ! empty( $result['done'] ) ) {
			$this->finish_chart( $result['chart'], $result['chart_type'], $result['note'] );

			return;
		}

		$this->state['chart_job'] = (string) $result['job'];
		$this->state['stage']     = __( 'Designing the chart…', 'wp-wren-dashboards' );
	}

	/**
	 * Advance the text-to-SQL job.
	 *
	 * @return void
	 */
	protected function poll_sql() {
		$result = WWD_Engine::make()->poll_sql( $this->state['job'] );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );

			return;
		}

		if ( '' !== $result['stage'] ) {
			$this->state['stage'] = $result['stage'];
		}

		if ( empty( $result['done'] ) ) {
			return;
		}

		if ( '' !== $result['reasoning'] ) {
			$this->state['reasoning'] = $result['reasoning'];
		}

		$this->state['sql']    = $result['sql'];
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

		$chart = WWD_Engine::make()->start_chart(
			$this->state['question'],
			$result['sql'],
			$result['columns'],
			$result['rows']
		);

		if ( is_wp_error( $chart ) ) {
			if ( $this->may_retry( $chart ) ) {
				$this->state['retries']   = 0;
				$this->state['chart_job'] = '';
				$this->state['status']    = 'generating_chart';

				$this->back_off();

				return;
			}

			// The data is already there; a missing chart is not a failed answer.
			$this->finish_chart( null, '', $chart->get_error_message() );

			return;
		}

		if ( ! empty( $chart['done'] ) ) {
			$this->finish_chart( $chart['chart'], $chart['chart_type'], $chart['note'] );

			return;
		}

		$this->state['chart_job'] = (string) $chart['job'];
		$this->state['status']    = 'generating_chart';
		$this->state['stage']     = __( 'Designing the chart…', 'wp-wren-dashboards' );
	}

	/**
	 * Advance the chart job.
	 *
	 * @return void
	 */
	protected function poll_chart() {
		$result = WWD_Engine::make()->poll_chart( $this->state['chart_job'] );

		if ( is_wp_error( $result ) ) {
			$this->finish_chart( null, '', $result->get_error_message() );

			return;
		}

		if ( empty( $result['done'] ) ) {
			return;
		}

		$this->finish_chart( $result['chart'], $result['chart_type'], $result['note'] );
	}

	/**
	 * Store whatever chart came back - including none - and finish.
	 *
	 * @param mixed  $chart Chart specification or null.
	 * @param string $type  Chart type, when the engine names one.
	 * @param string $note  Explanation, or the reason there is no chart.
	 * @return void
	 */
	protected function finish_chart( $chart, $type, $note ) {
		$this->state['chart']      = $chart;
		$this->state['chart_type'] = (string) $type;
		$this->state['chart_note'] = (string) $note;
		$this->state['status']     = 'done';
		$this->state['stage']      = __( 'Done.', 'wp-wren-dashboards' );
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
		if ( 'generating_sql' === $this->state['status'] && $this->state['job'] ) {
			WWD_Engine::make()->stop_sql( $this->state['job'] );
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

<?php
/**
 * What turns a question into SQL and a chart.
 *
 * There are two of them, and the difference is where the thinking happens:
 * the model directly (nothing to install), or a Wren AI service (a semantic
 * layer worth running when the schema is big or already modelled there).
 *
 * The ask session talks to this interface only, so the state machine does not
 * care which one is configured - or whether a step answers immediately or
 * hands back a job to poll.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class and factory for the two engines.
 */
abstract class WWD_Engine {

	/**
	 * The configured engine.
	 *
	 * @return WWD_Engine
	 */
	public static function make() {
		$engine = 'wren' === WWD_Settings::get( 'engine', 'direct' ) ? 'wren' : 'direct';

		/**
		 * Filters which engine answers questions.
		 *
		 * @param string $engine "direct" or "wren".
		 */
		$engine = apply_filters( 'wwd_engine', $engine );

		return 'wren' === $engine ? new WWD_Engine_Wren() : new WWD_Engine_Direct();
	}

	/**
	 * Name for the settings screen.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether the engine has what it needs to be called at all.
	 *
	 * @return bool
	 */
	abstract public function is_configured();

	/**
	 * Whether a question can be asked right now.
	 *
	 * @return true|WP_Error
	 */
	abstract public function ready();

	/**
	 * Prove the engine answers.
	 *
	 * @return array|WP_Error
	 */
	abstract public function health();

	/**
	 * Begin turning a question into SQL.
	 *
	 * @param string $question Natural language question.
	 * @param array  $thread   Previous turns, oldest first.
	 * @return array|WP_Error {done, job, sql, reasoning}
	 */
	abstract public function start_sql( $question, array $thread );

	/**
	 * Begin designing a chart for a result set.
	 *
	 * @param string $question Question that produced the data.
	 * @param string $sql      Statement that ran.
	 * @param array  $columns  Column names.
	 * @param array  $rows     Result rows.
	 * @return array|WP_Error {done, job, chart, chart_type, note}
	 */
	abstract public function start_chart( $question, $sql, array $columns, array $rows );

	/**
	 * Advance a text-to-SQL job. Only asynchronous engines implement this.
	 *
	 * @param string $job Job id.
	 * @return array|WP_Error {done, stage, sql, reasoning}
	 */
	public function poll_sql( $job ) {
		unset( $job );

		return new WP_Error( 'wwd_engine_sync', __( 'This engine answers in one step.', 'wp-wren-dashboards' ) );
	}

	/**
	 * Advance a chart job. Only asynchronous engines implement this.
	 *
	 * @param string $job Job id.
	 * @return array|WP_Error {done, chart, chart_type, note}
	 */
	public function poll_chart( $job ) {
		unset( $job );

		return new WP_Error( 'wwd_engine_sync', __( 'This engine answers in one step.', 'wp-wren-dashboards' ) );
	}

	/**
	 * Abandon a running job, where that means anything.
	 *
	 * @param string $job Job id.
	 * @return void
	 */
	public function stop_sql( $job ) {
		unset( $job );
	}

	/**
	 * An empty text-to-SQL result, for subclasses to fill in.
	 *
	 * @return array
	 */
	protected function sql_result() {
		return array(
			'done'      => false,
			'job'       => '',
			'stage'     => '',
			'sql'       => '',
			'reasoning' => '',
		);
	}

	/**
	 * An empty chart result, for subclasses to fill in.
	 *
	 * @return array
	 */
	protected function chart_result() {
		return array(
			'done'       => false,
			'job'        => '',
			'chart'      => null,
			'chart_type' => '',
			'note'       => '',
		);
	}
}

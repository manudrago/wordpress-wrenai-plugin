<?php
/**
 * The engine that needs nothing installed anywhere.
 *
 * The whole schema of a WordPress site fits in a prompt, so a question costs
 * one model call for the SQL and one for the chart. No service, no vector
 * store, no indexing step, and nothing to keep running between questions.
 *
 * The model is treated exactly as Wren AI was: an untrusted source of SQL that
 * WWD_SQL_Guard has to approve before anything runs.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Text-to-SQL and chart design straight from a language model.
 */
class WWD_Engine_Direct extends WWD_Engine {

	/**
	 * Rows shown to the model when it designs a chart.
	 */
	const CHART_SAMPLE = 30;

	/**
	 * Name for the settings screen.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Model only (no server)', 'datachat-ai' );
	}

	/**
	 * Whether a model is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$client = new WWD_Model_Client();

		return $client->is_ready();
	}

	/**
	 * Whether a question can be asked right now.
	 *
	 * @return true|WP_Error
	 */
	public function ready() {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wwd_model_unconfigured',
				__( 'No model is configured yet. An administrator has to add an API key under DataChat → Settings.', 'datachat-ai' )
			);
		}

		$tables = (array) WWD_Settings::get( 'allowed_tables', array() );

		if ( empty( $tables ) ) {
			return new WP_Error(
				'wwd_no_tables',
				__( 'No tables have been shared yet. An administrator has to pick them under DataChat → Data & schema.', 'datachat-ai' )
			);
		}

		return true;
	}

	/**
	 * Prove the key and model work.
	 *
	 * @return array|WP_Error
	 */
	public function health() {
		$client = new WWD_Model_Client();

		return $client->ping();
	}

	/**
	 * Ask the model for a statement.
	 *
	 * @param string $question Natural language question.
	 * @param array  $thread   Previous turns, oldest first.
	 * @return array|WP_Error
	 */
	public function start_sql( $question, array $thread ) {
		$client = new WWD_Model_Client();
		$answer = $client->complete( $this->sql_instructions(), $this->sql_request( $question, $thread ), 2000 );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$sql = isset( $answer['sql'] ) ? trim( (string) $answer['sql'] ) : '';

		// A model that cannot answer should say so rather than invent a query,
		// and the prompt asks it to use this field for that.
		if ( '' === $sql ) {
			$refusal = isset( $answer['error'] ) ? trim( (string) $answer['error'] ) : '';

			return new WP_Error(
				'wwd_no_sql',
				'' !== $refusal
					? $refusal
					: __( 'The model could not turn that into a query. Try naming the data you want more directly.', 'datachat-ai' )
			);
		}

		$result              = $this->sql_result();
		$result['done']      = true;
		$result['sql']       = $sql;
		$result['reasoning'] = isset( $answer['explanation'] ) ? (string) $answer['explanation'] : '';

		return $result;
	}

	/**
	 * Ask the model for a chart specification.
	 *
	 * @param string $question Question that produced the data.
	 * @param string $sql      Statement that ran.
	 * @param array  $columns  Column names.
	 * @param array  $rows     Result rows.
	 * @return array|WP_Error
	 */
	public function start_chart( $question, $sql, array $columns, array $rows ) {
		$client = new WWD_Model_Client();
		$answer = $client->complete(
			$this->chart_instructions(),
			$this->chart_request( $question, $columns, $rows ),
			1500
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$result         = $this->chart_result();
		$result['done'] = true;

		$spec = isset( $answer['spec'] ) && is_array( $answer['spec'] ) ? $answer['spec'] : null;

		if ( null === $spec || empty( $spec['encoding'] ) ) {
			// A table is a perfectly good answer; the renderer falls back to
			// one, and saying why helps whoever asked.
			$result['note'] = isset( $answer['reason'] )
				? (string) $answer['reason']
				: __( 'The data did not suggest a chart, so here it is as a table.', 'datachat-ai' );

			return $result;
		}

		$result['chart']      = $spec;
		$result['chart_type'] = isset( $spec['mark'] ) && is_string( $spec['mark'] ) ? $spec['mark'] : '';
		$result['note']       = isset( $answer['reason'] ) ? (string) $answer['reason'] : '';

		return $result;
	}

	/**
	 * System prompt for text-to-SQL.
	 *
	 * @return string
	 */
	protected function sql_instructions() {
		$instructions = "You write a single read-only SQL statement for a MySQL 8 or MariaDB database, and nothing else.\n\n" .
			"Rules, all of them enforced after you answer - a statement that breaks one is rejected and the person gets an error instead of data:\n" .
			"- Exactly one statement. It must start with SELECT or WITH. No INSERT, UPDATE, DELETE, DDL, no second statement, no trailing semicolon, no SQL comments.\n" .
			"- Only the tables listed in the schema. Never information_schema, never mysql.*, never system variables.\n" .
			"- Always end with a LIMIT.\n" .
			"- Quote identifiers with backticks. Give every computed column a readable alias, because those aliases become the chart labels and the CSV header.\n" .
			"- MySQL dialect: DATE_FORMAT() not DATE_TRUNC(), CAST(x AS SIGNED) not BIGINT, CONCAT() not ||, no FILTER clause, no window function unless the question truly needs one.\n" .
			"- Aggregate rather than dump rows: a question about data wants counts, sums and averages grouped by something.\n" .
			"- One period, one column. For anything over time, group by a single sortable period - DATE_FORMAT(d, '%Y-%m') for months, '%Y-%m-%d' for days, '%Y' for years - and never split the year into one column and the month into another. Two columns cannot say that October 2025 comes before January 2026, so a chart built on them puts the months in the wrong order.\n" .
			"- Order the rows the way they should be read: time ascending, oldest first; rankings by the measure, largest first.\n\n" .
			"Answer with JSON only: {\"sql\": \"…\", \"explanation\": \"one sentence, in the language of the question\", \"error\": \"\"}.\n" .
			"If the question cannot be answered from this schema, or is not about the data at all, leave sql empty and put a short, friendly reason in error.";

		/**
		 * Filters the system prompt used for text-to-SQL.
		 *
		 * @param string $instructions Prompt.
		 */
		return apply_filters( 'wwd_sql_instructions', $instructions );
	}

	/**
	 * User prompt for text-to-SQL: schema, context, history, question.
	 *
	 * @param string $question Question.
	 * @param array  $thread   Previous turns.
	 * @return string
	 */
	protected function sql_request( $question, array $thread ) {
		$parts = array(
			"Schema:\n\n" . WWD_Schema::prompt_text(),
		);

		$context = trim( (string) WWD_Settings::get( 'custom_instruction' ) );

		if ( '' !== $context ) {
			$parts[] = "What matters on this site:\n" . $context;
		}

		$parts[] = sprintf(
			"Today is %s (%s). The database stores local time in the *_date columns and UTC in the *_date_gmt ones.",
			gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			wp_timezone_string()
		);

		if ( ! empty( $thread ) ) {
			$history = array();

			foreach ( $thread as $turn ) {
				if ( empty( $turn['question'] ) || empty( $turn['sql'] ) ) {
					continue;
				}

				$history[] = sprintf( "Q: %s\nSQL: %s", $turn['question'], $turn['sql'] );
			}

			if ( ! empty( $history ) ) {
				$parts[] = "Earlier in this conversation, for follow-up questions like \"and last year?\":\n\n" . implode( "\n\n", $history );
			}
		}

		$parts[] = 'Question: ' . $question;

		return implode( "\n\n", $parts );
	}

	/**
	 * System prompt for chart design.
	 *
	 * The renderer understands a deliberate subset of Vega-Lite and nothing
	 * else, so the prompt describes exactly that subset: anything outside it
	 * would be silently dropped and the person would get a table.
	 *
	 * @return string
	 */
	protected function chart_instructions() {
		$instructions = "You choose how to chart a result set, and answer with a small Vega-Lite specification.\n\n" .
			"Only these parts are understood:\n" .
			"- mark: one of bar, line, area, point, arc.\n" .
			"- encoding.x and encoding.y: {\"field\": \"<exact column name>\", \"type\": \"nominal\"|\"quantitative\"|\"temporal\"}.\n" .
			"- encoding.color: a second dimension, drawn as one series per value. Omit it when there is only one series.\n" .
			"- encoding.xOffset: same field as color, to put the bars side by side instead of stacked.\n" .
			"- encoding.theta with mark arc: the slice size, with encoding.color as the slice label.\n" .
			"- title: a short caption.\n" .
			"Nothing else is read: no scales, axes, colours, tooltips, widths, facets or layers.\n\n" .
			"How to choose:\n" .
			"- A time column on x and a number on y: line.\n" .
			"- A category on x and a number on y: bar.\n" .
			"- Parts of a whole, at most 8 slices: arc.\n" .
			"- One row and one column: leave spec null, it is shown as a single big number.\n" .
			"- More than two dimensions, no numbers, or raw rows people need to read: leave spec null and let it stay a table.\n\n" .
			"Field names must match the columns exactly, character for character.\n\n" .
			"Answer with JSON only: {\"spec\": {…} or null, \"reason\": \"one short sentence in the language of the question\"}.";

		/**
		 * Filters the system prompt used for chart design.
		 *
		 * @param string $instructions Prompt.
		 */
		return apply_filters( 'wwd_chart_instructions', $instructions );
	}

	/**
	 * User prompt for chart design: the question and a sample of the data.
	 *
	 * @param string $question Question.
	 * @param array  $columns  Column names.
	 * @param array  $rows     Result rows.
	 * @return string
	 */
	protected function chart_request( $question, array $columns, array $rows ) {
		/**
		 * Filters how many rows the model sees when designing a chart. It only
		 * needs enough to recognise the shape of the data.
		 *
		 * @param int $rows Row count.
		 */
		$limit  = (int) apply_filters( 'wwd_chart_sample_rows', self::CHART_SAMPLE );
		$sample = array_slice( $rows, 0, max( 1, $limit ) );

		return sprintf(
			"Question: %s\n\nColumns: %s\n\nFirst %d of %d rows, as arrays in column order:\n%s",
			$question,
			implode( ', ', $columns ),
			count( $sample ),
			count( $rows ),
			wp_json_encode( $sample )
		);
	}
}

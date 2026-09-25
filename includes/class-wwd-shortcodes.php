<?php
/**
 * Front-end shortcodes.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the ask form and saved dashboards.
 */
class WWD_Shortcodes {

	/**
	 * Whether the page needs the plugin assets.
	 *
	 * @var bool
	 */
	protected $needs_assets = false;

	/**
	 * Register shortcodes.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( 'wren_ai_dashboard', array( $this, 'render_ask' ) );
		add_shortcode( 'wren_ask', array( $this, 'render_ask' ) );
		add_shortcode( 'wren_dashboard', array( $this, 'render_dashboard' ) );

		// The plugin was called Wren AI Dashboards until 2.0. Pages out there
		// still carry those shortcodes, and breaking somebody's published page
		// over a rename would be indefensible.
		add_shortcode( 'datachat', array( $this, 'render_ask' ) );
		add_shortcode( 'datachat_dashboard', array( $this, 'render_dashboard' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// The same app runs inside wp-admin, where it is the main way to use
		// the plugin rather than an embed.
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (but do not enqueue) the front-end assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'wwd-app', WWD_PLUGIN_URL . 'assets/css/wwd-app.css', array(), WWD_VERSION );
		wp_register_script( 'wwd-chart', WWD_PLUGIN_URL . 'assets/js/wwd-chart.js', array(), WWD_VERSION, true );
		wp_register_script( 'wwd-app', WWD_PLUGIN_URL . 'assets/js/wwd-app.js', array( 'wwd-chart' ), WWD_VERSION, true );
	}

	/**
	 * Enqueue the assets and hand the browser its configuration.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( $this->needs_assets ) {
			return;
		}

		$this->needs_assets = true;

		wp_enqueue_style( 'wwd-app' );
		wp_enqueue_script( 'wwd-app' );

		wp_localize_script(
			'wwd-app',
			'WWD_CONFIG',
			array(
				'root'     => esc_url_raw( rest_url( WWD_REST::NAMESPACE_V1 ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'canSave'  => current_user_can( (string) WWD_Settings::get( 'save_capability', 'edit_posts' ) ),
				'showSql'  => (bool) WWD_Settings::get( 'show_sql', 1 ),
				'pollMs'   => (int) apply_filters( 'wwd_poll_interval_ms', 1200 ),
				'locale'   => str_replace( '_', '-', get_locale() ),
				'i18n'     => array(
					'thinking'    => __( 'Working on it…', 'datachat-ai' ),
					'error'       => __( 'Something went wrong.', 'datachat-ai' ),
					'rows'        => __( 'rows', 'datachat-ai' ),
					'noData'      => __( 'No rows matched that question.', 'datachat-ai' ),
					'showSql'     => __( 'Show SQL', 'datachat-ai' ),
					'hideSql'     => __( 'Hide SQL', 'datachat-ai' ),
					'showTable'   => __( 'Table', 'datachat-ai' ),
					'showChart'   => __( 'Chart', 'datachat-ai' ),
					'save'        => __( 'Save to dashboard', 'datachat-ai' ),
					'saving'      => __( 'Saving…', 'datachat-ai' ),
					'saved'       => __( 'Saved to %s', 'datachat-ai' ),
					'csv'         => __( 'Download CSV', 'datachat-ai' ),
					'stop'        => __( 'Stop', 'datachat-ai' ),
					'truncated'   => __( 'Showing the first %d rows.', 'datachat-ai' ),
					'cached'      => __( 'cached', 'datachat-ai' ),
					'refresh'     => __( 'Refresh', 'datachat-ai' ),
					'chooseBoard' => __( 'Choose a dashboard', 'datachat-ai' ),
					'noBoards'    => __( 'No dashboards yet — create one under DataChat → Dashboards.', 'datachat-ai' ),
					'panelTitle'  => __( 'Panel title', 'datachat-ai' ),
					'width'       => __( 'Width', 'datachat-ai' ),
					'widthHalf'   => __( 'Half', 'datachat-ai' ),
					'widthFull'   => __( 'Full', 'datachat-ai' ),
					'view_column' => __( 'Columns', 'datachat-ai' ),
					'view_bar'    => __( 'Bars', 'datachat-ai' ),
					'view_line'   => __( 'Line', 'datachat-ai' ),
					'view_area'   => __( 'Area', 'datachat-ai' ),
					'view_pie'    => __( 'Pie', 'datachat-ai' ),
					'otherBar'    => __( 'Everything else', 'datachat-ai' ),
					/* translators: %d: how many categories were grouped into one bar. */
					'otherNote'   => __( 'The %d smallest are grouped together; the table lists them all.', 'datachat-ai' ),
					'widthThird'  => __( 'Third', 'datachat-ai' ),
					'cancel'      => __( 'Cancel', 'datachat-ai' ),
				),
			)
		);
	}

	/**
	 * Suggested questions shown under the form.
	 *
	 * @param string $raw Comma or pipe separated list from the shortcode.
	 * @return array
	 */
	protected function examples( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' !== $raw ) {
			$parts = preg_split( '/\s*\|\s*/', $raw );

			return array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
		}

		$defaults = array(
			__( 'How many posts were published each month this year?', 'datachat-ai' ),
			__( 'Top 10 posts by number of comments', 'datachat-ai' ),
			__( 'Published posts by category', 'datachat-ai' ),
			__( 'How many comments are waiting for moderation?', 'datachat-ai' ),
		);

		/**
		 * Filters the example questions shown under the ask form.
		 *
		 * @param array $defaults Example questions.
		 */
		return apply_filters( 'wwd_example_questions', $defaults );
	}

	/**
	 * The ask form: [wren_ai_dashboard]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_ask( $atts ) {
		$atts = shortcode_atts(
			array(
				'dashboard'   => '',
				'title'       => '',
				'placeholder' => __( 'Ask anything about your data…', 'datachat-ai' ),
				'examples'    => '',
				'height'      => '340',
			),
			$atts,
			'wren_ai_dashboard'
		);

		$rest = new WWD_REST();
		$can  = $rest->can_ask();

		if ( is_wp_error( $can ) ) {
			return $this->notice( $can->get_error_message() );
		}

		if ( ! WWD_Settings::is_configured() ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->notice( __( 'Data questions are not available yet.', 'datachat-ai' ) );
			}

			if ( 'wren' !== WWD_Settings::get( 'engine', 'direct' ) ) {
				return $this->notice(
					sprintf(
						/* translators: %s: settings URL. */
						__( 'No model is configured yet. <a href="%s">Add an API key</a> and this form starts working.', 'datachat-ai' ),
						esc_url( admin_url( 'admin.php?page=wwd-settings' ) )
					)
				);
			}

			// Two different things are missing at two different stages; saying
			// which one saves a hunt through the admin.
			if ( '' === trim( (string) WWD_Settings::get( 'endpoint' ) ) ) {
				return $this->notice(
					sprintf(
						/* translators: %s: settings URL. */
						__( 'Wren AI is not connected yet. <a href="%s">Set the endpoint</a> first.', 'datachat-ai' ),
						esc_url( admin_url( 'admin.php?page=wwd-settings' ) )
					)
				);
			}

			return $this->notice(
				sprintf(
					/* translators: %s: schema screen URL. */
					__( 'Wren AI is connected, but the database schema has not been deployed yet. Open <a href="%s">Data &amp; schema</a>, then press "Build &amp; deploy schema" and wait for it to finish.', 'datachat-ai' ),
					esc_url( admin_url( 'admin.php?page=wwd-schema' ) )
				)
			);
		}

		$this->enqueue();

		$examples = $this->examples( $atts['examples'] );
		$uid      = 'wwd-' . wp_generate_password( 6, false, false );

		ob_start();
		?>
		<div class="wwd-app" id="<?php echo esc_attr( $uid ); ?>"
			data-dashboard="<?php echo esc_attr( (string) (int) $atts['dashboard'] ); ?>"
			data-height="<?php echo esc_attr( (string) (int) $atts['height'] ); ?>">

			<?php if ( '' !== $atts['title'] ) : ?>
				<h2 class="wwd-app__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

			<form class="wwd-ask" autocomplete="off">
				<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-q">
					<?php esc_html_e( 'Your question', 'datachat-ai' ); ?>
				</label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-q" class="wwd-ask__input" rows="2"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"></textarea>
				<div class="wwd-ask__actions">
					<button type="submit" class="wwd-btn wwd-btn--primary">
						<?php esc_html_e( 'Ask', 'datachat-ai' ); ?>
					</button>
					<button type="button" class="wwd-btn wwd-btn--ghost wwd-ask__reset">
						<?php esc_html_e( 'New topic', 'datachat-ai' ); ?>
					</button>
				</div>
			</form>

			<?php if ( ! empty( $examples ) ) : ?>
				<ul class="wwd-examples">
					<?php foreach ( $examples as $example ) : ?>
						<li><button type="button" class="wwd-chip"><?php echo esc_html( $example ); ?></button></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="wwd-answers" aria-live="polite"></div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * A saved dashboard: [wren_dashboard id="12"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_dashboard( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'title'   => '',
				'refresh' => '0',
			),
			$atts,
			'wren_dashboard'
		);

		$dashboard_id = (int) $atts['id'];

		if ( ! $dashboard_id || WWD_Dashboards::POST_TYPE !== get_post_type( $dashboard_id ) ) {
			return $this->notice( __( 'That dashboard does not exist.', 'datachat-ai' ) );
		}

		$rest = new WWD_REST();
		$can  = $rest->can_ask();

		if ( is_wp_error( $can ) ) {
			return $this->notice( $can->get_error_message() );
		}

		$panels = WWD_Dashboards::panels( $dashboard_id );

		if ( empty( $panels ) ) {
			return $this->notice( __( 'This dashboard has no panels yet.', 'datachat-ai' ) );
		}

		$this->enqueue();

		$title = '' !== $atts['title'] ? $atts['title'] : get_the_title( $dashboard_id );

		ob_start();
		?>
		<div class="wwd-board" data-dashboard="<?php echo esc_attr( $dashboard_id ); ?>"
			data-refresh="<?php echo esc_attr( (string) (int) $atts['refresh'] ); ?>">
			<?php if ( '' !== $title ) : ?>
				<h2 class="wwd-board__title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
			<div class="wwd-board__grid">
				<?php foreach ( $panels as $panel ) : ?>
					<div class="wwd-panel wwd-panel--<?php echo esc_attr( $panel['width'] ); ?>"
						data-panel="<?php echo esc_attr( $panel['id'] ); ?>">
						<div class="wwd-panel__head">
							<h3 class="wwd-panel__title"><?php echo esc_html( $panel['title'] ); ?></h3>
							<button type="button" class="wwd-icon-btn wwd-panel__refresh"
								title="<?php esc_attr_e( 'Refresh', 'datachat-ai' ); ?>" aria-label="<?php esc_attr_e( 'Refresh', 'datachat-ai' ); ?>">&#8635;</button>
						</div>
						<div class="wwd-panel__body">
							<div class="wwd-skeleton"></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * A small inline notice.
	 *
	 * @param string $message Message, may contain a link.
	 * @return string
	 */
	protected function notice( $message ) {
		wp_enqueue_style( 'wwd-app' );

		return '<div class="wwd-notice">' . wp_kses( $message, array( 'a' => array( 'href' => array() ), 'code' => array() ) ) . '</div>';
	}
}

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

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
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
	protected function enqueue() {
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
					'thinking'    => __( 'Working on it…', 'wp-wren-dashboards' ),
					'error'       => __( 'Something went wrong.', 'wp-wren-dashboards' ),
					'rows'        => __( 'rows', 'wp-wren-dashboards' ),
					'noData'      => __( 'No rows matched that question.', 'wp-wren-dashboards' ),
					'showSql'     => __( 'Show SQL', 'wp-wren-dashboards' ),
					'hideSql'     => __( 'Hide SQL', 'wp-wren-dashboards' ),
					'showTable'   => __( 'Table', 'wp-wren-dashboards' ),
					'showChart'   => __( 'Chart', 'wp-wren-dashboards' ),
					'save'        => __( 'Save to dashboard', 'wp-wren-dashboards' ),
					'saving'      => __( 'Saving…', 'wp-wren-dashboards' ),
					'saved'       => __( 'Saved to %s', 'wp-wren-dashboards' ),
					'csv'         => __( 'Download CSV', 'wp-wren-dashboards' ),
					'stop'        => __( 'Stop', 'wp-wren-dashboards' ),
					'truncated'   => __( 'Showing the first %d rows.', 'wp-wren-dashboards' ),
					'cached'      => __( 'cached', 'wp-wren-dashboards' ),
					'refresh'     => __( 'Refresh', 'wp-wren-dashboards' ),
					'chooseBoard' => __( 'Choose a dashboard', 'wp-wren-dashboards' ),
					'noBoards'    => __( 'No dashboards yet — create one under Wren AI → Dashboards.', 'wp-wren-dashboards' ),
					'panelTitle'  => __( 'Panel title', 'wp-wren-dashboards' ),
					'width'       => __( 'Width', 'wp-wren-dashboards' ),
					'widthHalf'   => __( 'Half', 'wp-wren-dashboards' ),
					'widthFull'   => __( 'Full', 'wp-wren-dashboards' ),
					'widthThird'  => __( 'Third', 'wp-wren-dashboards' ),
					'cancel'      => __( 'Cancel', 'wp-wren-dashboards' ),
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
			__( 'How many posts were published each month this year?', 'wp-wren-dashboards' ),
			__( 'Top 10 posts by number of comments', 'wp-wren-dashboards' ),
			__( 'Published posts by category', 'wp-wren-dashboards' ),
			__( 'How many comments are waiting for moderation?', 'wp-wren-dashboards' ),
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
				'placeholder' => __( 'Ask anything about your data…', 'wp-wren-dashboards' ),
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
				return $this->notice( __( 'Data questions are not available yet.', 'wp-wren-dashboards' ) );
			}

			// Two different things are missing at two different stages; saying
			// which one saves a hunt through the admin.
			if ( '' === trim( (string) WWD_Settings::get( 'endpoint' ) ) ) {
				return $this->notice(
					sprintf(
						/* translators: %s: settings URL. */
						__( 'Wren AI is not connected yet. <a href="%s">Set the endpoint</a> first.', 'wp-wren-dashboards' ),
						esc_url( admin_url( 'admin.php?page=wwd' ) )
					)
				);
			}

			return $this->notice(
				sprintf(
					/* translators: %s: schema screen URL. */
					__( 'Wren AI is connected, but the database schema has not been deployed yet. Open <a href="%s">Data &amp; schema</a>, then press "Build &amp; deploy schema" and wait for it to finish.', 'wp-wren-dashboards' ),
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
					<?php esc_html_e( 'Your question', 'wp-wren-dashboards' ); ?>
				</label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-q" class="wwd-ask__input" rows="2"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"></textarea>
				<div class="wwd-ask__actions">
					<button type="submit" class="wwd-btn wwd-btn--primary">
						<?php esc_html_e( 'Ask', 'wp-wren-dashboards' ); ?>
					</button>
					<button type="button" class="wwd-btn wwd-btn--ghost wwd-ask__reset">
						<?php esc_html_e( 'New topic', 'wp-wren-dashboards' ); ?>
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
			return $this->notice( __( 'That dashboard does not exist.', 'wp-wren-dashboards' ) );
		}

		$rest = new WWD_REST();
		$can  = $rest->can_ask();

		if ( is_wp_error( $can ) ) {
			return $this->notice( $can->get_error_message() );
		}

		$panels = WWD_Dashboards::panels( $dashboard_id );

		if ( empty( $panels ) ) {
			return $this->notice( __( 'This dashboard has no panels yet.', 'wp-wren-dashboards' ) );
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
								title="<?php esc_attr_e( 'Refresh', 'wp-wren-dashboards' ); ?>" aria-label="<?php esc_attr_e( 'Refresh', 'wp-wren-dashboards' ); ?>">&#8635;</button>
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

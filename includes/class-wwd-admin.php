<?php
/**
 * Admin screens: connection, semantic model, dashboards, query log.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the wp-admin experience.
 */
class WWD_Admin {

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_wwd_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_wwd_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_post_wwd_flush_cache', array( $this, 'flush_cache' ) );
		add_action( 'admin_post_wwd_panel_action', array( $this, 'panel_action' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WWD_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Menu entries.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'Wren AI Dashboards', 'wp-wren-dashboards' ),
			__( 'Wren AI', 'wp-wren-dashboards' ),
			'manage_options',
			'wwd',
			array( $this, 'render_settings' ),
			'dashicons-chart-area',
			58
		);

		add_submenu_page( 'wwd', __( 'Settings', 'wp-wren-dashboards' ), __( 'Settings', 'wp-wren-dashboards' ), 'manage_options', 'wwd', array( $this, 'render_settings' ) );
		add_submenu_page( 'wwd', __( 'Data & schema', 'wp-wren-dashboards' ), __( 'Data & schema', 'wp-wren-dashboards' ), 'manage_options', 'wwd-schema', array( $this, 'render_schema' ) );
		add_submenu_page( 'wwd', __( 'Query log', 'wp-wren-dashboards' ), __( 'Query log', 'wp-wren-dashboards' ), 'manage_options', 'wwd-log', array( $this, 'render_log' ) );
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'wwd' ) && WWD_Dashboards::POST_TYPE !== get_post_type() ) {
			return;
		}

		wp_enqueue_style( 'wwd-admin', WWD_PLUGIN_URL . 'assets/css/wwd-admin.css', array(), WWD_VERSION );
		wp_enqueue_script( 'wwd-admin', WWD_PLUGIN_URL . 'assets/js/wwd-admin.js', array(), WWD_VERSION, true );

		wp_localize_script(
			'wwd-admin',
			'WWD_ADMIN',
			array(
				'root'  => esc_url_raw( rest_url( WWD_REST::NAMESPACE_V1 ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'checking'  => __( 'Checking…', 'wp-wren-dashboards' ),
					'syncing'   => __( 'Sending the schema to Wren AI…', 'wp-wren-dashboards' ),
					'indexing'  => __( 'Wren AI is indexing the schema…', 'wp-wren-dashboards' ),
					'synced'    => __( 'Schema deployed. You can start asking questions.', 'wp-wren-dashboards' ),
					'failed'    => __( 'Failed', 'wp-wren-dashboards' ),
					'connected' => __( 'Connected to Wren AI', 'wp-wren-dashboards' ),
				),
			)
		);
	}

	/**
	 * Quick links on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=wwd' ) ) . '">' . esc_html__( 'Settings', 'wp-wren-dashboards' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Persist the settings form.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access required.', 'wp-wren-dashboards' ) );
		}

		check_admin_referer( 'wwd_save_settings' );

		$raw   = isset( $_POST['wwd'] ) ? wp_unslash( $_POST['wwd'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean = WWD_Settings::sanitize( (array) $raw );

		$before = WWD_Settings::all();

		WWD_Settings::update( $clean );

		$redirect = isset( $_POST['wwd_redirect'] ) ? sanitize_text_field( wp_unslash( $_POST['wwd_redirect'] ) ) : 'wwd';

		// Changing the exposed tables or columns invalidates the deployed model.
		$model_changed = isset( $clean['allowed_tables'] ) && $clean['allowed_tables'] !== $before['allowed_tables'];
		$model_changed = $model_changed || ( isset( $clean['blocked_columns'] ) && $clean['blocked_columns'] !== $before['blocked_columns'] );

		if ( $model_changed ) {
			WWD_Query_Runner::flush_cache();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => $redirect,
					'updated' => $model_changed ? 'resync' : '1',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Empty the query log.
	 *
	 * @return void
	 */
	public function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access required.', 'wp-wren-dashboards' ) );
		}

		check_admin_referer( 'wwd_clear_log' );

		WWD_Logger::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=wwd-log&updated=1' ) );

		exit;
	}

	/**
	 * Drop cached query results.
	 *
	 * @return void
	 */
	public function flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access required.', 'wp-wren-dashboards' ) );
		}

		check_admin_referer( 'wwd_flush_cache' );

		WWD_Query_Runner::flush_cache();

		wp_safe_redirect( admin_url( 'admin.php?page=wwd&updated=cache' ) );

		exit;
	}

	/**
	 * Delete or reorder a panel from the dashboard editor.
	 *
	 * @return void
	 */
	public function panel_action() {
		$dashboard_id = isset( $_REQUEST['dashboard'] ) ? (int) $_REQUEST['dashboard'] : 0;
		$panel_id     = isset( $_REQUEST['panel'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['panel'] ) ) : '';
		$action       = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';

		check_admin_referer( 'wwd_panel_' . $dashboard_id . '_' . $panel_id );

		if ( ! current_user_can( 'edit_post', $dashboard_id ) ) {
			wp_die( esc_html__( 'You cannot edit this dashboard.', 'wp-wren-dashboards' ) );
		}

		if ( 'delete' === $action ) {
			WWD_Dashboards::delete_panel( $dashboard_id, $panel_id );
		} elseif ( 'up' === $action ) {
			WWD_Dashboards::move_panel( $dashboard_id, $panel_id, -1 );
		} elseif ( 'down' === $action ) {
			WWD_Dashboards::move_panel( $dashboard_id, $panel_id, 1 );
		}

		wp_safe_redirect( get_edit_post_link( $dashboard_id, 'raw' ) );

		exit;
	}

	/**
	 * Panel list on the dashboard editor.
	 *
	 * @return void
	 */
	public function meta_boxes() {
		add_meta_box(
			'wwd-panels',
			__( 'Panels', 'wp-wren-dashboards' ),
			array( $this, 'render_panels_box' ),
			WWD_Dashboards::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wwd-shortcode',
			__( 'Publish this dashboard', 'wp-wren-dashboards' ),
			array( $this, 'render_shortcode_box' ),
			WWD_Dashboards::POST_TYPE,
			'side'
		);
	}

	/**
	 * Panels metabox.
	 *
	 * @param WP_Post $post Dashboard post.
	 * @return void
	 */
	public function render_panels_box( $post ) {
		$panels = WWD_Dashboards::panels( $post->ID );

		if ( empty( $panels ) ) {
			echo '<p>' . esc_html__( 'No panels yet. Open the page with the [wren_ai_dashboard] shortcode, ask a question and choose "Save to dashboard".', 'wp-wren-dashboards' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped wwd-panels"><thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'wp-wren-dashboards' ) . '</th>';
		echo '<th>' . esc_html__( 'Question', 'wp-wren-dashboards' ) . '</th>';
		echo '<th>' . esc_html__( 'Chart', 'wp-wren-dashboards' ) . '</th>';
		echo '<th>' . esc_html__( 'Width', 'wp-wren-dashboards' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $panels as $panel ) {
			$base = add_query_arg(
				array(
					'action'    => 'wwd_panel_action',
					'dashboard' => $post->ID,
					'panel'     => rawurlencode( $panel['id'] ),
				),
				admin_url( 'admin-post.php' )
			);

			$nonce = 'wwd_panel_' . $post->ID . '_' . $panel['id'];

			echo '<tr>';
			echo '<td><strong>' . esc_html( $panel['title'] ) . '</strong><br><code class="wwd-sql">' . esc_html( $panel['sql'] ) . '</code></td>';
			echo '<td>' . esc_html( $panel['question'] ) . '</td>';
			echo '<td>' . esc_html( $panel['chart_type'] ? $panel['chart_type'] : __( 'table', 'wp-wren-dashboards' ) ) . '</td>';
			echo '<td>' . esc_html( $panel['width'] ) . '</td>';
			echo '<td class="wwd-panels__actions">';
			echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'do', 'up', $base ), $nonce ) ) . '">&uarr;</a> ';
			echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'do', 'down', $base ), $nonce ) ) . '">&darr;</a> ';
			echo '<a class="wwd-danger" href="' . esc_url( wp_nonce_url( add_query_arg( 'do', 'delete', $base ), $nonce ) ) . '">' . esc_html__( 'Delete', 'wp-wren-dashboards' ) . '</a>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Shortcode metabox.
	 *
	 * @param WP_Post $post Dashboard post.
	 * @return void
	 */
	public function render_shortcode_box( $post ) {
		echo '<p>' . esc_html__( 'Paste this shortcode into any page:', 'wp-wren-dashboards' ) . '</p>';
		echo '<input type="text" class="widefat wwd-copy" readonly value="' . esc_attr( '[wren_dashboard id="' . $post->ID . '"]' ) . '">';
		echo '<p class="description">' . esc_html__( 'Add refresh="60" to reload the panels every 60 seconds.', 'wp-wren-dashboards' ) . '</p>';
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$settings = WWD_Settings::all();
		$updated  = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		include WWD_PLUGIN_DIR . 'includes/views/settings.php';
	}

	/**
	 * Schema screen.
	 *
	 * @return void
	 */
	public function render_schema() {
		$settings = WWD_Settings::all();
		$tables   = WWD_Schema::list_tables();
		$updated  = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		include WWD_PLUGIN_DIR . 'includes/views/schema.php';
	}

	/**
	 * Query log screen.
	 *
	 * @return void
	 */
	public function render_log() {
		$entries = WWD_Logger::recent( 100 );

		include WWD_PLUGIN_DIR . 'includes/views/log.php';
	}
}

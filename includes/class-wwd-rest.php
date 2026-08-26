<?php
/**
 * REST API used by the front-end form and the admin screens.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin routes under /wp-json/wren-ai/v1.
 */
class WWD_REST {

	const NAMESPACE_V1 = 'wren-ai/v1';

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/ask',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ask' ),
				'permission_callback' => array( $this, 'can_ask' ),
				'args'                => array(
					'question' => array(
						'required' => true,
						'type'     => 'string',
					),
					'reset'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/ask/(?P<id>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => array( $this, 'can_ask' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/ask/(?P<id>[a-zA-Z0-9\-]+)/stop',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stop' ),
				'permission_callback' => array( $this, 'can_ask' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/dashboards',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'dashboards' ),
				'permission_callback' => array( $this, 'can_save' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/dashboards/(?P<id>\d+)/panels',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_panel' ),
				'permission_callback' => array( $this, 'can_save' ),
				'args'                => array(
					'session_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'title'      => array( 'type' => 'string' ),
					'width'      => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/dashboards/(?P<id>\d+)/panels/(?P<panel>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_panel' ),
				'permission_callback' => array( $this, 'can_save' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/dashboards/(?P<id>\d+)/panels/(?P<panel>[a-zA-Z0-9\-]+)/data',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'panel_data' ),
				'permission_callback' => array( $this, 'can_ask' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/schema/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_schema' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/schema/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'schema_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'health' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Whether the current request may ask questions.
	 *
	 * @return bool|WP_Error
	 */
	public function can_ask() {
		if ( WWD_Settings::get( 'allow_public', 0 ) ) {
			return true;
		}

		$capability = (string) WWD_Settings::get( 'ask_capability', 'edit_posts' );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wwd_not_logged_in',
				__( 'You need to be logged in to ask questions about this site\'s data.', 'wp-wren-dashboards' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'wwd_forbidden',
				__( 'Your account is not allowed to query this data.', 'wp-wren-dashboards' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Whether the current user may save panels.
	 *
	 * @return bool|WP_Error
	 */
	public function can_save() {
		$capability = (string) WWD_Settings::get( 'save_capability', 'edit_posts' );

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'wwd_forbidden',
				__( 'Your account is not allowed to change dashboards.', 'wp-wren-dashboards' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Whether the current user administers the plugin.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wwd_forbidden', __( 'Administrator access required.', 'wp-wren-dashboards' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Start a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ask( WP_REST_Request $request ) {
		if ( WWD_Logger::is_rate_limited() ) {
			return new WP_Error(
				'wwd_rate_limited',
				__( 'Too many questions in a short time. Please wait a moment.', 'wp-wren-dashboards' ),
				array( 'status' => 429 )
			);
		}

		if ( $request->get_param( 'reset' ) ) {
			WWD_Ask_Session::forget_thread();
		}

		$session = WWD_Ask_Session::start( (string) $request->get_param( 'question' ) );

		if ( is_wp_error( $session ) ) {
			return $this->error( $session );
		}

		return rest_ensure_response( $session->to_array() );
	}

	/**
	 * Advance a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function poll( WP_REST_Request $request ) {
		$session = WWD_Ask_Session::load( (string) $request['id'] );

		if ( is_wp_error( $session ) ) {
			return $this->error( $session );
		}

		return rest_ensure_response( $session->advance() );
	}

	/**
	 * Stop a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function stop( WP_REST_Request $request ) {
		$session = WWD_Ask_Session::load( (string) $request['id'] );

		if ( is_wp_error( $session ) ) {
			return $this->error( $session );
		}

		return rest_ensure_response( $session->stop() );
	}

	/**
	 * Dashboards the current user can save into.
	 *
	 * @return WP_REST_Response
	 */
	public function dashboards() {
		$items = array();

		foreach ( WWD_Dashboards::all() as $dashboard ) {
			$items[] = array(
				'id'     => $dashboard->ID,
				'title'  => $dashboard->post_title,
				'panels' => count( WWD_Dashboards::panels( $dashboard->ID ) ),
			);
		}

		return rest_ensure_response( array( 'dashboards' => $items ) );
	}

	/**
	 * Save the current answer as a dashboard panel.
	 *
	 * The SQL comes from the server side session, never from the browser, so a
	 * saved panel can only ever contain a statement Wren AI produced and the
	 * guard already approved.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_panel( WP_REST_Request $request ) {
		$session = WWD_Ask_Session::load( (string) $request->get_param( 'session_id' ) );

		if ( is_wp_error( $session ) ) {
			return $this->error( $session );
		}

		$answer = $session->to_array();

		if ( empty( $answer['sql'] ) ) {
			$answer['sql'] = $session->sql();
		}

		if ( 'done' !== $answer['status'] ) {
			return new WP_Error( 'wwd_not_ready', __( 'Wait for the answer to finish before saving it.', 'wp-wren-dashboards' ), array( 'status' => 400 ) );
		}

		$dashboard_id = (int) $request['id'];
		$title        = (string) $request->get_param( 'title' );
		$width        = (string) $request->get_param( 'width' );

		$panel = WWD_Dashboards::add_panel(
			$dashboard_id,
			array(
				'title'      => '' !== $title ? $title : $answer['question'],
				'question'   => $answer['question'],
				'sql'        => $answer['sql'],
				'chart'      => $answer['chart'],
				'chart_type' => $answer['chart_type'],
				'width'      => $width,
			)
		);

		if ( is_wp_error( $panel ) ) {
			return $this->error( $panel );
		}

		return rest_ensure_response(
			array(
				'panel'     => $panel,
				'dashboard' => array(
					'id'    => $dashboard_id,
					'title' => get_the_title( $dashboard_id ),
					'edit'  => get_edit_post_link( $dashboard_id, 'raw' ),
				),
			)
		);
	}

	/**
	 * Delete a panel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_panel( WP_REST_Request $request ) {
		$deleted = WWD_Dashboards::delete_panel( (int) $request['id'], (string) $request['panel'] );

		return rest_ensure_response( array( 'deleted' => (bool) $deleted ) );
	}

	/**
	 * Refresh one saved panel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function panel_data( WP_REST_Request $request ) {
		$data = WWD_Dashboards::panel_data( (int) $request['id'], (string) $request['panel'] );

		if ( is_wp_error( $data ) ) {
			return $this->error( $data );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Build the MDL from the database and deploy it to Wren AI.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_schema() {
		$result = WWD_Plugin::sync_schema();

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Indexing status of the deployed model.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function schema_status() {
		$hash = (string) WWD_Settings::get( 'mdl_hash' );

		if ( '' === $hash ) {
			return rest_ensure_response(
				array(
					'status'  => 'none',
					'message' => __( 'No schema has been deployed yet.', 'wp-wren-dashboards' ),
				)
			);
		}

		$client = new WWD_Wren_Client();
		$result = $client->semantics_status( $hash );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		$result['mdl_hash'] = $hash;

		// Remember a finished indexing run: questions asked later must not have
		// to guess whether the model behind the hash is usable.
		if ( isset( $result['status'] ) ) {
			WWD_Settings::update( array( 'mdl_ready' => 'finished' === $result['status'] ? 1 : 0 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Connectivity check.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function health() {
		$client = new WWD_Wren_Client();
		$result = $client->health();

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}

		return rest_ensure_response( array( 'ok' => true, 'service' => $result ) );
	}

	/**
	 * Give every error a sensible HTTP status.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	protected function error( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$error->add_data( array( 'status' => 400 ), $error->get_error_code() );
		}

		return $error;
	}
}

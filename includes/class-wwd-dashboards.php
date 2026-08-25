<?php
/**
 * Saved dashboards: a post type whose content is a list of panels.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and renders saved panels.
 */
class WWD_Dashboards {

	const POST_TYPE = 'wwd_dashboard';
	const META_KEY  = '_wwd_panels';

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Wren Dashboards', 'wp-wren-dashboards' ),
					'singular_name' => __( 'Dashboard', 'wp-wren-dashboards' ),
					'add_new_item'  => __( 'Add dashboard', 'wp-wren-dashboards' ),
					'edit_item'     => __( 'Edit dashboard', 'wp-wren-dashboards' ),
					'search_items'  => __( 'Search dashboards', 'wp-wren-dashboards' ),
					'not_found'     => __( 'No dashboards yet. Ask a question and save the answer as a panel.', 'wp-wren-dashboards' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'wwd',
				'supports'     => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap' => true,
			)
		);
	}

	/**
	 * All dashboards, newest first.
	 *
	 * @return WP_Post[]
	 */
	public static function all() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Panels of a dashboard.
	 *
	 * @param int $dashboard_id Dashboard post id.
	 * @return array
	 */
	public static function panels( $dashboard_id ) {
		$panels = get_post_meta( (int) $dashboard_id, self::META_KEY, true );

		if ( ! is_array( $panels ) ) {
			return array();
		}

		return array_values( $panels );
	}

	/**
	 * Replace the panels of a dashboard.
	 *
	 * @param int   $dashboard_id Dashboard post id.
	 * @param array $panels       Panels.
	 * @return void
	 */
	protected static function save_panels( $dashboard_id, array $panels ) {
		update_post_meta( (int) $dashboard_id, self::META_KEY, array_values( $panels ) );

		delete_transient( 'wwd_dash_' . (int) $dashboard_id );
	}

	/**
	 * Append a panel, re-validating its SQL before it is stored.
	 *
	 * @param int   $dashboard_id Dashboard post id.
	 * @param array $panel        Panel data.
	 * @return array|WP_Error The stored panel.
	 */
	public static function add_panel( $dashboard_id, array $panel ) {
		$dashboard_id = (int) $dashboard_id;

		if ( self::POST_TYPE !== get_post_type( $dashboard_id ) ) {
			return new WP_Error( 'wwd_no_dashboard', __( 'That dashboard does not exist.', 'wp-wren-dashboards' ) );
		}

		$prepared = WWD_SQL_Guard::prepare( isset( $panel['sql'] ) ? $panel['sql'] : '' );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$stored = array(
			'id'         => wp_generate_uuid4(),
			'title'      => sanitize_text_field( isset( $panel['title'] ) ? $panel['title'] : '' ),
			'question'   => sanitize_text_field( isset( $panel['question'] ) ? $panel['question'] : '' ),
			'sql'        => $prepared['sql'],
			'chart'      => isset( $panel['chart'] ) && is_array( $panel['chart'] ) ? self::sanitize_chart( $panel['chart'] ) : null,
			'chart_type' => sanitize_text_field( isset( $panel['chart_type'] ) ? $panel['chart_type'] : '' ),
			'width'      => in_array( isset( $panel['width'] ) ? $panel['width'] : '', array( 'full', 'half', 'third' ), true ) ? $panel['width'] : 'half',
			'created_at' => current_time( 'mysql' ),
			'created_by' => get_current_user_id(),
		);

		if ( '' === $stored['title'] ) {
			$stored['title'] = $stored['question'];
		}

		$panels   = self::panels( $dashboard_id );
		$panels[] = $stored;

		self::save_panels( $dashboard_id, $panels );

		return $stored;
	}

	/**
	 * Remove a panel.
	 *
	 * @param int    $dashboard_id Dashboard post id.
	 * @param string $panel_id     Panel id.
	 * @return bool
	 */
	public static function delete_panel( $dashboard_id, $panel_id ) {
		$panels = self::panels( $dashboard_id );
		$kept   = array();
		$found  = false;

		foreach ( $panels as $panel ) {
			if ( $panel['id'] === $panel_id ) {
				$found = true;

				continue;
			}

			$kept[] = $panel;
		}

		if ( $found ) {
			self::save_panels( $dashboard_id, $kept );
		}

		return $found;
	}

	/**
	 * Move a panel up or down.
	 *
	 * @param int    $dashboard_id Dashboard post id.
	 * @param string $panel_id     Panel id.
	 * @param int    $offset       -1 or 1.
	 * @return bool
	 */
	public static function move_panel( $dashboard_id, $panel_id, $offset ) {
		$panels = self::panels( $dashboard_id );
		$index  = null;

		foreach ( $panels as $i => $panel ) {
			if ( $panel['id'] === $panel_id ) {
				$index = $i;

				break;
			}
		}

		if ( null === $index ) {
			return false;
		}

		$target = $index + (int) $offset;

		if ( $target < 0 || $target >= count( $panels ) ) {
			return false;
		}

		$moved            = $panels[ $index ];
		$panels[ $index ] = $panels[ $target ];
		$panels[ $target ] = $moved;

		self::save_panels( $dashboard_id, $panels );

		return true;
	}

	/**
	 * A single panel.
	 *
	 * @param int    $dashboard_id Dashboard post id.
	 * @param string $panel_id     Panel id.
	 * @return array|null
	 */
	public static function panel( $dashboard_id, $panel_id ) {
		foreach ( self::panels( $dashboard_id ) as $panel ) {
			if ( $panel['id'] === $panel_id ) {
				return $panel;
			}
		}

		return null;
	}

	/**
	 * Execute a panel and return its data.
	 *
	 * @param int    $dashboard_id Dashboard post id.
	 * @param string $panel_id     Panel id.
	 * @return array|WP_Error
	 */
	public static function panel_data( $dashboard_id, $panel_id ) {
		$panel = self::panel( $dashboard_id, $panel_id );

		if ( ! $panel ) {
			return new WP_Error( 'wwd_no_panel', __( 'That panel no longer exists.', 'wp-wren-dashboards' ) );
		}

		$result = WWD_Query_Runner::run( $panel['sql'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'         => $panel['id'],
			'title'      => $panel['title'],
			'question'   => $panel['question'],
			'sql'        => WWD_Settings::get( 'show_sql', 1 ) ? $panel['sql'] : '',
			'chart'      => $panel['chart'],
			'chart_type' => $panel['chart_type'],
			'width'      => $panel['width'],
			'columns'    => $result['columns'],
			'rows'       => $result['rows'],
			'row_count'  => $result['row_count'],
			'cached'     => $result['cached'],
			'duration'   => $result['duration'],
		);
	}

	/**
	 * Keep only the parts of a Vega-Lite schema the renderer understands, so a
	 * model generated spec can never smuggle markup into the page.
	 *
	 * @param array $chart Chart schema.
	 * @param int   $depth Recursion guard.
	 * @return array
	 */
	public static function sanitize_chart( array $chart, $depth = 0 ) {
		if ( $depth > 8 ) {
			return array();
		}

		$clean = array();

		foreach ( $chart as $key => $value ) {
			$key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_chart( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = sanitize_text_field( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			}
		}

		// Data values are injected at render time from the live query.
		unset( $clean['data'] );

		return $clean;
	}
}

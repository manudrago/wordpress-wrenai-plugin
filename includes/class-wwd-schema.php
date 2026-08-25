<?php
/**
 * Database introspection and MDL (Modeling Definition Language) generation.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the WordPress database into a Wren AI semantic model.
 */
class WWD_Schema {

	/**
	 * All table names in the WordPress database.
	 *
	 * @return array
	 */
	public static function list_tables() {
		global $wpdb;

		$cached = wp_cache_get( 'wwd_tables', 'wwd' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$tables = is_array( $tables ) ? $tables : array();

		sort( $tables );

		wp_cache_set( 'wwd_tables', $tables, 'wwd', MINUTE_IN_SECONDS );

		return $tables;
	}

	/**
	 * Column metadata for a table.
	 *
	 * @param string $table Table name.
	 * @return array List of arrays with name, type, nullable, key, comment.
	 */
	public static function columns( $table ) {
		global $wpdb;

		if ( ! in_array( $table, self::list_tables(), true ) ) {
			return array();
		}

		// Table names cannot be bound as parameters; the value is validated above.
		$rows = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();

		foreach ( $rows as $row ) {
			$columns[] = array(
				'name'     => $row['Field'],
				'type'     => self::map_type( $row['Type'] ),
				'raw_type' => $row['Type'],
				'nullable' => 'YES' === $row['Null'],
				'key'      => $row['Key'],
				'comment'  => isset( $row['Comment'] ) ? $row['Comment'] : '',
			);
		}

		return $columns;
	}

	/**
	 * Map a MySQL column type onto a Wren MDL type.
	 *
	 * @param string $mysql_type Raw MySQL type, e.g. "bigint(20) unsigned".
	 * @return string
	 */
	public static function map_type( $mysql_type ) {
		$type = strtolower( preg_replace( '/\(.*$/', '', (string) $mysql_type ) );
		$type = trim( str_replace( array( 'unsigned', 'zerofill' ), '', $type ) );

		$map = array(
			'tinyint'    => 'TINYINT',
			'smallint'   => 'SMALLINT',
			'mediumint'  => 'INTEGER',
			'int'        => 'INTEGER',
			'integer'    => 'INTEGER',
			'bigint'     => 'BIGINT',
			'decimal'    => 'DECIMAL',
			'numeric'    => 'DECIMAL',
			'float'      => 'REAL',
			'double'     => 'DOUBLE',
			'bit'        => 'BOOLEAN',
			'bool'       => 'BOOLEAN',
			'boolean'    => 'BOOLEAN',
			'date'       => 'DATE',
			'datetime'   => 'TIMESTAMP',
			'timestamp'  => 'TIMESTAMP',
			'time'       => 'VARCHAR',
			'year'       => 'INTEGER',
			'char'       => 'VARCHAR',
			'varchar'    => 'VARCHAR',
			'tinytext'   => 'VARCHAR',
			'text'       => 'VARCHAR',
			'mediumtext' => 'VARCHAR',
			'longtext'   => 'VARCHAR',
			'json'       => 'JSON',
			'enum'       => 'VARCHAR',
			'set'        => 'VARCHAR',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : 'VARCHAR';
	}

	/**
	 * Human descriptions for well known WordPress tables. These are what make
	 * text-to-SQL accurate: the LLM cannot guess that `post_type` holds
	 * 'product' or that meta values are strings.
	 *
	 * @return array Map of unprefixed table name => description.
	 */
	public static function table_descriptions() {
		return array(
			'posts'              => 'Every piece of content: blog posts, pages, attachments, menu items, revisions and custom post types (including WooCommerce orders and products on older schemas). Filter on post_type and post_status. Real, visible content is post_status = "publish" and post_type NOT IN ("revision","nav_menu_item","attachment"). post_date is local site time, post_date_gmt is UTC. post_author joins users.ID.',
			'postmeta'           => 'Custom fields for posts, one key/value row per field. Join postmeta.post_id = posts.ID. meta_value is stored as text, so cast before doing maths: CAST(meta_value AS DECIMAL(18,4)). Keys starting with an underscore are private/internal.',
			'users'              => 'Registered accounts. user_registered is the signup timestamp (UTC). Never expose passwords or activation keys.',
			'usermeta'           => 'Per-user custom fields, one key/value row per field. Join usermeta.user_id = users.ID. Capabilities and roles are stored serialized in the meta key ending with "capabilities".',
			'comments'           => 'Comments and reviews on posts. Join comments.comment_post_ID = posts.ID. comment_approved = "1" means visible, "0" pending, "spam" spam. comment_type is "comment", "review" or a pingback type.',
			'commentmeta'        => 'Custom fields for comments, joined on commentmeta.comment_id = comments.comment_ID.',
			'terms'              => 'Taxonomy term names and slugs (categories, tags, product categories).',
			'term_taxonomy'      => 'Ties a term to a taxonomy (category, post_tag, product_cat) and holds the count. Join term_taxonomy.term_id = terms.term_id.',
			'term_relationships' => 'Many-to-many link between posts and taxonomy terms. Join term_relationships.object_id = posts.ID and term_relationships.term_taxonomy_id = term_taxonomy.term_taxonomy_id.',
			'termmeta'           => 'Custom fields for taxonomy terms, joined on termmeta.term_id = terms.term_id.',
			'options'            => 'Site-wide settings as key/value rows. Rarely useful for analytics.',
			'links'              => 'Legacy blogroll links.',
		);
	}

	/**
	 * Relationships the WordPress schema implies but never declares, plus any
	 * real foreign keys found in information_schema.
	 *
	 * @param array $tables Allowed table names.
	 * @return array MDL relationship definitions.
	 */
	public static function relationships( array $tables ) {
		global $wpdb;

		$prefix   = $wpdb->prefix;
		$known    = array(
			array( 'postmeta', 'post_id', 'posts', 'ID', 'MANY_TO_ONE' ),
			array( 'comments', 'comment_post_ID', 'posts', 'ID', 'MANY_TO_ONE' ),
			array( 'commentmeta', 'comment_id', 'comments', 'comment_ID', 'MANY_TO_ONE' ),
			array( 'usermeta', 'user_id', 'users', 'ID', 'MANY_TO_ONE' ),
			array( 'posts', 'post_author', 'users', 'ID', 'MANY_TO_ONE' ),
			array( 'term_relationships', 'term_taxonomy_id', 'term_taxonomy', 'term_taxonomy_id', 'MANY_TO_ONE' ),
			array( 'term_taxonomy', 'term_id', 'terms', 'term_id', 'MANY_TO_ONE' ),
			array( 'termmeta', 'term_id', 'terms', 'term_id', 'MANY_TO_ONE' ),
			array( 'term_relationships', 'object_id', 'posts', 'ID', 'MANY_TO_ONE' ),
		);

		$relationships = array();
		$seen          = array();

		foreach ( $known as $rel ) {
			list( $left, $left_col, $right, $right_col, $join ) = $rel;

			$left_table  = $prefix . $left;
			$right_table = $prefix . $right;

			if ( ! in_array( $left_table, $tables, true ) || ! in_array( $right_table, $tables, true ) ) {
				continue;
			}

			$name = $left_table . '_' . $left_col . '_' . $right_table;

			$relationships[] = array(
				'name'      => $name,
				'models'    => array( $left_table, $right_table ),
				'joinType'  => $join,
				'condition' => sprintf( '%s.%s = %s.%s', $left_table, $left_col, $right_table, $right_col ),
			);

			$seen[ $name ] = true;
		}

		// Real foreign keys (plugins such as WooCommerce HPOS declare some).
		$foreign_keys = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
				 FROM information_schema.KEY_COLUMN_USAGE
				 WHERE CONSTRAINT_SCHEMA = %s AND REFERENCED_TABLE_NAME IS NOT NULL',
				$wpdb->dbname
			),
			ARRAY_A
		);

		if ( is_array( $foreign_keys ) ) {
			foreach ( $foreign_keys as $key ) {
				if ( ! in_array( $key['TABLE_NAME'], $tables, true ) || ! in_array( $key['REFERENCED_TABLE_NAME'], $tables, true ) ) {
					continue;
				}

				$name = $key['TABLE_NAME'] . '_' . $key['COLUMN_NAME'] . '_' . $key['REFERENCED_TABLE_NAME'];

				if ( isset( $seen[ $name ] ) ) {
					continue;
				}

				$relationships[] = array(
					'name'      => $name,
					'models'    => array( $key['TABLE_NAME'], $key['REFERENCED_TABLE_NAME'] ),
					'joinType'  => 'MANY_TO_ONE',
					'condition' => sprintf(
						'%s.%s = %s.%s',
						$key['TABLE_NAME'],
						$key['COLUMN_NAME'],
						$key['REFERENCED_TABLE_NAME'],
						$key['REFERENCED_COLUMN_NAME']
					),
				);

				$seen[ $name ] = true;
			}
		}

		/**
		 * Filters the relationships sent to Wren AI.
		 *
		 * @param array $relationships MDL relationships.
		 * @param array $tables        Allowed tables.
		 */
		return apply_filters( 'wwd_mdl_relationships', $relationships, $tables );
	}

	/**
	 * Build the full MDL document for the allowed tables.
	 *
	 * @return array
	 */
	public static function build_mdl() {
		global $wpdb;

		$settings = WWD_Settings::all();
		$tables   = array_values( array_intersect( self::list_tables(), (array) $settings['allowed_tables'] ) );
		$blocked  = array_map( 'strtolower', (array) $settings['blocked_columns'] );
		$prefix   = $wpdb->prefix;
		$descs    = self::table_descriptions();

		$models = array();

		foreach ( $tables as $table ) {
			$columns     = self::columns( $table );
			$mdl_columns = array();
			$primary_key = '';

			foreach ( $columns as $column ) {
				if ( in_array( strtolower( $column['name'] ), $blocked, true ) ) {
					continue;
				}

				$properties = array();

				if ( ! empty( $column['comment'] ) ) {
					$properties['description'] = $column['comment'];
				}

				$mdl_columns[] = array(
					'name'         => $column['name'],
					'type'         => $column['type'],
					'isCalculated' => false,
					'notNull'      => ! $column['nullable'],
					'properties'   => (object) $properties,
				);

				if ( 'PRI' === $column['key'] && '' === $primary_key ) {
					$primary_key = $column['name'];
				}
			}

			if ( empty( $mdl_columns ) ) {
				continue;
			}

			$short       = 0 === strpos( $table, $prefix ) ? substr( $table, strlen( $prefix ) ) : $table;
			$description = isset( $descs[ $short ] ) ? $descs[ $short ] : sprintf( 'Table %s of the WordPress database.', $table );

			$models[] = array(
				'name'           => $table,
				'properties'     => (object) array( 'description' => $description ),
				'tableReference' => array(
					'catalog' => '',
					'schema'  => $wpdb->dbname,
					'table'   => $table,
				),
				'columns'        => $mdl_columns,
				'primaryKey'     => $primary_key,
				'cached'         => false,
			);
		}

		$mdl = array(
			'catalog'       => 'wordpress',
			'schema'        => 'public',
			'models'        => $models,
			'relationships' => self::relationships( $tables ),
			'views'         => array(),
			'dataSource'    => 'mysql',
		);

		/**
		 * Filters the MDL document before it is deployed to Wren AI.
		 *
		 * @param array $mdl MDL document.
		 */
		return apply_filters( 'wwd_mdl', $mdl );
	}

	/**
	 * Hash identifying the current semantic model inside Wren AI.
	 *
	 * @param array $mdl MDL document.
	 * @return string
	 */
	public static function mdl_hash( array $mdl ) {
		return substr( hash( 'sha256', wp_json_encode( $mdl ) ), 0, 32 );
	}

	/**
	 * Columns that must never leave the database, as a flat list.
	 *
	 * @return array
	 */
	public static function blocked_columns() {
		return array_map( 'strtolower', (array) WWD_Settings::get( 'blocked_columns', array() ) );
	}
}

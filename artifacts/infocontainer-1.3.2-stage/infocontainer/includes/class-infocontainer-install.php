<?php
/** Installation and versioned, additive schema updates. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_Install {
	const DB_VERSION = '1.2.0';
	const VERSION_OPTION = 'infocontainer_db_version';
	const CAPABILITY = 'manage_infocontainer';
	const OPERATOR_ROLE = 'infocontainer_operator';

	/** @var WP_Error|null Installation failure for the current request. */
	private static $error = null;

	/** Run on existing active installations as well as initial activation. */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
			return self::install();
		}
		return self::register_roles();
	}

	/**
	 * Ensure permissions independently of the data schema, including active updates.
	 *
	 * Never assign users or overwrite an incompatible pre-existing role. Only
	 * missing grants are written, so an unchanged installation performs no writes.
	 *
	 * @return true|WP_Error
	 */
	public static function register_roles() {
		$capabilities = array( 'read' => true, self::CAPABILITY => true );
		$operator = get_role( self::OPERATOR_ROLE );
		if ( $operator && array_diff_key( $operator->capabilities, $capabilities ) ) {
			self::$error = new WP_Error(
				'infocontainer_operator_role_conflict',
				'El rol infocontainer_operator ya contiene otras capacidades. Un administrador debe revisar el conflicto sin reasignar usuarios automáticamente.',
				array( 'status' => 503 )
			);
			return self::$error;
		}
		$administrators = get_role( 'administrator' );
		if ( ! $administrators ) {
			return self::failure();
		}
		if ( ! $operator ) {
			$operator = add_role( self::OPERATOR_ROLE, 'Operador de InfoContainer', $capabilities );
		}
		if ( ! $operator ) {
			return self::failure();
		}
		foreach ( $capabilities as $capability => $grant ) {
			if ( ! isset( $operator->capabilities[ $capability ] ) || true !== $operator->capabilities[ $capability ] ) {
				$operator->add_cap( $capability, $grant );
			}
		}
		if ( empty( $administrators->capabilities[ self::CAPABILITY ] ) ) {
			$administrators->add_cap( self::CAPABILITY );
		}
		return true;
	}

	/** @return WP_Error|null */
	public static function get_error() {
		return self::$error;
	}

	/** @return true|WP_Error */
	public static function install() {
		global $wpdb;
		self::$error = null;
		$previous = $wpdb->suppress_errors( true );
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$containers = $wpdb->prefix . 'infocontainer_containers';
			$inspections = $wpdb->prefix . 'infocontainer_inspections';
			$photos = $wpdb->prefix . 'infocontainer_photos';
			$ai_runs = $wpdb->prefix . 'infocontainer_ai_runs';
			$ai_reviews = $wpdb->prefix . 'infocontainer_ai_reviews';
			$collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $containers (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				identifier varchar(11) NOT NULL,
				iso_code varchar(4) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL,
				created_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY identifier (identifier)
			) $collate;";
			dbDelta( $sql );
			if ( $wpdb->last_error ) {
				return self::failure();
			}

			$sql = "CREATE TABLE $ai_runs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				operation varchar(32) NOT NULL,
				inspection_id bigint(20) unsigned DEFAULT NULL,
				client_request_id varchar(36) NOT NULL,
				created_by bigint(20) unsigned NOT NULL,
				created_at_utc datetime NOT NULL,
				updated_at_utc datetime NOT NULL,
				status varchar(24) NOT NULL,
				processing_expires_at_utc datetime DEFAULT NULL,
				model_configured varchar(100) NOT NULL,
				model_used varchar(100) NOT NULL DEFAULT '',
				prompt_version varchar(32) NOT NULL,
				input_fingerprint char(64) NOT NULL,
				input_snapshot longtext NOT NULL,
				source_fingerprint char(64) NOT NULL,
				proposal longtext DEFAULT NULL,
				usage_json text DEFAULT NULL,
				provider_response_id varchar(190) NOT NULL DEFAULT '',
				error_code varchar(64) NOT NULL DEFAULT '',
				error_message varchar(255) NOT NULL DEFAULT '',
				completed_at_utc datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY operation_request (operation,client_request_id),
				KEY inspection_created (inspection_id,created_at_utc),
				KEY author_created (created_by,created_at_utc),
				KEY status_expires (status,processing_expires_at_utc)
			) ENGINE=InnoDB $collate;";
			dbDelta( $sql );
			if ( $wpdb->last_error ) {
				return self::failure();
			}

			$sql = "CREATE TABLE $ai_reviews (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				client_review_id varchar(36) NOT NULL,
				reviewed_by bigint(20) unsigned NOT NULL,
				action varchar(16) NOT NULL,
				reviewed_content longtext DEFAULT NULL,
				source_fingerprint char(64) NOT NULL,
				created_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_review (run_id,client_review_id),
				KEY run_created (run_id,created_at_utc)
			) ENGINE=InnoDB $collate;";
			dbDelta( $sql );
			if ( $wpdb->last_error ) {
				return self::failure();
			}

			$sql = "CREATE TABLE $photos (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				inspection_id bigint(20) unsigned NOT NULL,
				client_photo_id varchar(36) NOT NULL,
				category varchar(32) NOT NULL,
				description text NOT NULL,
				storage_key varchar(190) NOT NULL,
				original_filename varchar(255) NOT NULL,
				mime_type varchar(32) NOT NULL,
				byte_size bigint(20) unsigned NOT NULL,
				width int(10) unsigned NOT NULL,
				height int(10) unsigned NOT NULL,
				sha256 char(64) NOT NULL,
				captured_at_utc datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL,
				created_at_utc datetime NOT NULL,
				updated_by bigint(20) unsigned NOT NULL,
				updated_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY inspection_id (inspection_id),
				UNIQUE KEY inspection_client (inspection_id,client_photo_id)
			) ENGINE=InnoDB $collate;";
			dbDelta( $sql );
			if ( $wpdb->last_error ) {
				return self::failure();
			}

			$sql = "CREATE TABLE $inspections (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				container_id bigint(20) unsigned NOT NULL,
				identifier_snapshot varchar(11) NOT NULL,
				iso_code_snapshot varchar(4) NOT NULL DEFAULT '',
				inspection_type varchar(20) NOT NULL,
				inspected_at_utc datetime NOT NULL,
				observations longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				created_by bigint(20) unsigned NOT NULL,
				created_at_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY container_id (container_id)
			) $collate;";
			dbDelta( $sql );
			if ( $wpdb->last_error ) {
				return self::failure();
			}
			if ( ! self::verify_table( $photos, array( 'id', 'inspection_id', 'client_photo_id', 'category', 'description', 'storage_key', 'original_filename', 'mime_type', 'byte_size', 'width', 'height', 'sha256', 'captured_at_utc', 'created_by', 'created_at_utc', 'updated_by', 'updated_at_utc' ), array( 'id' ), array( 'inspection_id', 'client_photo_id' ), 'inspection_id' ) ) {
				return self::failure();
			}
			// Draft-state checks and simultaneous uploads rely on row-level locks.
			foreach ( array( $inspections, $photos, $ai_runs, $ai_reviews ) as $transactional_table ) {
				$table_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $transactional_table ) ), ARRAY_A );
				if ( $wpdb->last_error || ! $table_status || 'innodb' !== strtolower( $table_status['Engine'] ) ) {
					return self::failure( 503 );
				}
			}

			if ( ! self::verify_table( $containers, array( 'id', 'identifier', 'iso_code', 'created_by', 'created_at_utc' ), array( 'id' ), array( 'identifier' ) )
				|| ! self::verify_table( $inspections, array( 'id', 'container_id', 'identifier_snapshot', 'iso_code_snapshot', 'inspection_type', 'inspected_at_utc', 'observations', 'status', 'created_by', 'created_at_utc' ), array( 'id' ), array(), 'container_id' ) ) {
				return self::failure();
			}
			if ( ! self::verify_table( $ai_runs, array( 'id', 'operation', 'inspection_id', 'client_request_id', 'created_by', 'created_at_utc', 'updated_at_utc', 'status', 'processing_expires_at_utc', 'model_configured', 'model_used', 'prompt_version', 'input_fingerprint', 'input_snapshot', 'source_fingerprint', 'proposal', 'usage_json', 'provider_response_id', 'error_code', 'error_message', 'completed_at_utc' ), array( 'id' ), array( 'operation', 'client_request_id' ), 'inspection_id' )
				|| ! self::verify_table( $ai_reviews, array( 'id', 'run_id', 'client_review_id', 'reviewed_by', 'action', 'reviewed_content', 'source_fingerprint', 'created_at_utc' ), array( 'id' ), array( 'run_id', 'client_review_id' ), 'run_id' ) ) {
				return self::failure();
			}

			$permissions = self::register_roles();
			if ( is_wp_error( $permissions ) ) {
				return $permissions;
			}

			// Only mark the version once tables and required indexes are verified.
			update_option( self::VERSION_OPTION, self::DB_VERSION, false );
			if ( self::DB_VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
				return self::failure();
			}
			return true;
		} catch ( Throwable $error ) {
			return self::failure();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Verify table existence, expected columns and full (non-prefix) indexes. */
	private static function verify_table( $table, array $columns, array $primary, array $unique, $indexed = '' ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $wpdb->last_error || $table !== $found ) {
			return false;
		}
		$quoted = '`' . str_replace( '`', '``', $table ) . '`';
		$metadata = $wpdb->get_results( "SHOW COLUMNS FROM $quoted", ARRAY_A );
		if ( $wpdb->last_error || ! $metadata ) {
			return false;
		}
		$actual = array();
		foreach ( $metadata as $column ) {
			$actual[ $column['Field'] ] = $column;
		}
		foreach ( $columns as $name ) {
			if ( ! isset( $actual[ $name ] ) || ! self::verify_column( $name, $actual[ $name ] ) ) {
				return false;
			}
		}
		$rows = $wpdb->get_results( "SHOW INDEX FROM $quoted", ARRAY_A );
		if ( $wpdb->last_error || ! $rows ) {
			return false;
		}
		$indexes = array();
		foreach ( $rows as $row ) {
			$name = $row['Key_name'];
			$indexes[ $name ]['columns'][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
			$indexes[ $name ]['unique'] = 0 === (int) $row['Non_unique'];
			$indexes[ $name ]['partial'] = ! empty( $indexes[ $name ]['partial'] ) || null !== $row['Sub_part'];
		}
		$primary_ok = false;
		$unique_ok = empty( $unique );
		$indexed_ok = '' === $indexed;
		foreach ( $indexes as $name => $index ) {
			ksort( $index['columns'] );
			$index_columns = array_values( $index['columns'] );
			if ( $index['partial'] ) {
				continue;
			}
			if ( 'PRIMARY' === $name && $index['unique'] && $primary === $index_columns ) {
				$primary_ok = true;
			}
			if ( $index['unique'] && $unique === $index_columns ) {
				$unique_ok = true;
			}
			if ( $indexed && $indexed === $index_columns[0] ) {
				$indexed_ok = true;
			}
		}
		return $primary_ok && $unique_ok && $indexed_ok;
	}

	/** Avoid accepting a partially successful dbDelta update on an existing table. */
	private static function verify_column( $name, array $column ) {
		$nullable = in_array( $name, array( 'captured_at_utc', 'processing_expires_at_utc', 'proposal', 'usage_json', 'completed_at_utc', 'reviewed_content' ), true );
		// inspection_id is required in photos and optional in AI runs.
		if ( 'inspection_id' !== $name && ( $nullable ? 'YES' : 'NO' ) !== $column['Null'] ) {
			return false;
		}
		$type = strtolower( $column['Type'] );
		if ( in_array( $name, array( 'id', 'container_id', 'inspection_id', 'created_by', 'updated_by', 'byte_size', 'run_id', 'reviewed_by' ), true ) ) {
			return 1 === preg_match( '/^bigint(?:\([0-9]+\))? unsigned$/D', $type )
				&& ( 'id' !== $name || false !== strpos( $column['Extra'], 'auto_increment' ) );
		}
		if ( in_array( $name, array( 'width', 'height' ), true ) ) {
			return 1 === preg_match( '/^int(?:\([0-9]+\))? unsigned$/D', $type );
		}
		if ( 'status' === $name ) {
			return in_array( $type, array( 'varchar(20)', 'varchar(24)' ), true );
		}
		$types = array(
			'identifier' => 'varchar(11)', 'identifier_snapshot' => 'varchar(11)',
			'iso_code' => 'varchar(4)', 'iso_code_snapshot' => 'varchar(4)',
			'inspection_type' => 'varchar(20)', 'status' => 'varchar(20)',
			'created_at_utc' => 'datetime', 'inspected_at_utc' => 'datetime',
			'observations' => 'longtext',
			'client_photo_id' => 'varchar(36)', 'category' => 'varchar(32)',
			'description' => 'text', 'storage_key' => 'varchar(190)',
			'original_filename' => 'varchar(255)', 'mime_type' => 'varchar(32)',
			'sha256' => 'char(64)', 'captured_at_utc' => 'datetime',
			'updated_at_utc' => 'datetime',
			'operation' => 'varchar(32)', 'client_request_id' => 'varchar(36)',
			'processing_expires_at_utc' => 'datetime',
			'model_configured' => 'varchar(100)', 'model_used' => 'varchar(100)',
			'prompt_version' => 'varchar(32)', 'input_fingerprint' => 'char(64)',
			'input_snapshot' => 'longtext', 'source_fingerprint' => 'char(64)',
			'proposal' => 'longtext', 'usage_json' => 'text',
			'provider_response_id' => 'varchar(190)', 'error_code' => 'varchar(64)',
			'error_message' => 'varchar(255)', 'completed_at_utc' => 'datetime',
			'client_review_id' => 'varchar(36)', 'action' => 'varchar(16)',
			'reviewed_content' => 'longtext',
		);
		return isset( $types[ $name ] ) && $type === $types[ $name ];
	}

	/** Keep database and SQL details out of HTTP responses and activation output. */
	private static function failure( $status = 500 ) {
		self::$error = new WP_Error(
			'infocontainer_install_failed',
			'No se pudo completar la instalación de InfoContainer.',
			array( 'status' => $status )
		);
		return self::$error;
	}
}

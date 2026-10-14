<?php
/** Installation and versioned, additive schema updates. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_Install {
	const DB_VERSION = '1.1.0';
	const VERSION_OPTION = 'infocontainer_db_version';
	const CAPABILITY = 'manage_infocontainer';

	/** @var WP_Error|null Installation failure for the current request. */
	private static $error = null;

	/** Run on existing active installations as well as initial activation. */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
			return self::install();
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
			foreach ( array( $inspections, $photos ) as $transactional_table ) {
				$table_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $transactional_table ) ), ARRAY_A );
				if ( $wpdb->last_error || ! $table_status || 'innodb' !== strtolower( $table_status['Engine'] ) ) {
					return self::failure( 503 );
				}
			}

			if ( ! self::verify_table( $containers, array( 'id', 'identifier', 'iso_code', 'created_by', 'created_at_utc' ), array( 'id' ), array( 'identifier' ) )
				|| ! self::verify_table( $inspections, array( 'id', 'container_id', 'identifier_snapshot', 'iso_code_snapshot', 'inspection_type', 'inspected_at_utc', 'observations', 'status', 'created_by', 'created_at_utc' ), array( 'id' ), array(), 'container_id' ) ) {
				return self::failure();
			}

			$administrators = get_role( 'administrator' );
			if ( ! $administrators ) {
				return self::failure();
			}
			if ( ! $administrators->has_cap( self::CAPABILITY ) ) {
				$administrators->add_cap( self::CAPABILITY );
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
		if ( ( 'captured_at_utc' === $name ? 'YES' : 'NO' ) !== $column['Null'] ) {
			return false;
		}
		$type = strtolower( $column['Type'] );
		if ( in_array( $name, array( 'id', 'container_id', 'inspection_id', 'created_by', 'updated_by', 'byte_size' ), true ) ) {
			return 1 === preg_match( '/^bigint(?:\([0-9]+\))? unsigned$/D', $type )
				&& ( 'id' !== $name || false !== strpos( $column['Extra'], 'auto_increment' ) );
		}
		if ( in_array( $name, array( 'width', 'height' ), true ) ) {
			return 1 === preg_match( '/^int(?:\([0-9]+\))? unsigned$/D', $type );
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

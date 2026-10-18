<?php
/**
 * Database access for containers and their manual inspection history.
 *
 * @package InfoContainer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_Repository {
	/** @var wpdb */
	private $database;

	/** @var string */
	private $containers_table;

	/** @var string */
	private $inspections_table;

	/**
	 * @param wpdb|null $database Optional database connection for dependency injection.
	 */
	public function __construct( $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}

		$this->database         = $database;
		$this->containers_table = $database->prefix . 'infocontainer_containers';
		$this->inspections_table = $database->prefix . 'infocontainer_inspections';
	}

	/**
	 * Create a container from validated data. Authorship and creation time are server values.
	 *
	 * @param array $data Normalized identifier and optional ISO code.
	 * @return array|WP_Error
	 */
	public function create_container( array $data ) {
		return $this->with_suppressed_errors(
			function () use ( $data ) {
				$record = array(
					'identifier'     => $data['identifier'],
					'iso_code'       => isset( $data['iso_code'] ) ? $data['iso_code'] : '',
					'created_by'     => get_current_user_id(),
					'created_at_utc' => current_time( 'mysql', true ),
				);

				$inserted = $this->database->insert(
					$this->containers_table,
					$record,
					array( '%s', '%s', '%d', '%s' )
				);

				if ( false === $inserted || 1 !== (int) $inserted ) {
					// The unique index also protects concurrent creation requests.
					$existing = $this->database->get_row(
						$this->database->prepare(
							"SELECT id FROM {$this->containers_table} WHERE identifier = %s",
							$record['identifier']
						),
						ARRAY_A
					);

					if ( empty( $this->database->last_error ) && is_array( $existing ) ) {
						return new WP_Error(
							'infocontainer_duplicate_identifier',
							__( 'Ya existe un contenedor con ese identificador.', 'infocontainer' ),
							array( 'status' => 409 )
						);
					}

					return $this->internal_error();
				}

				$created = $this->get_container( (int) $this->database->insert_id );
				return is_wp_error( $created ) ? $this->internal_error() : $created;
			}
		);
	}

	/**
	 * @param int $id Container ID.
	 * @return array|WP_Error
	 */
	public function get_container( $id ) {
		return $this->with_suppressed_errors(
			function () use ( $id ) {
				$record = $this->database->get_row(
					$this->database->prepare(
						"SELECT * FROM {$this->containers_table} WHERE id = %d",
						$id
					),
					ARRAY_A
				);

				if ( ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				if ( ! is_array( $record ) ) {
					return new WP_Error(
						'infocontainer_container_not_found',
						__( 'El contenedor no existe.', 'infocontainer' ),
						array( 'status' => 404 )
					);
				}

				return $record;
			}
		);
	}

	/**
	 * Return a stable, paginated list, optionally matching a normalized identifier exactly.
	 *
	 * @param int    $page       Page number, starting at one.
	 * @param int    $per_page   Number of records, capped at 100.
	 * @param string $identifier Optional normalized identifier.
	 * @return array|WP_Error Array with items and total.
	 */
	public function list_containers( $page, $per_page, $identifier = '' ) {
		return $this->with_suppressed_errors(
			function () use ( $page, $per_page, $identifier ) {
				$page     = max( 1, (int) $page );
				$per_page = min( 100, max( 1, (int) $per_page ) );
				$offset   = ( $page - 1 ) * $per_page;
				$where    = '';

				if ( '' !== $identifier ) {
					$where = $this->database->prepare( ' WHERE identifier = %s', $identifier );
				}

				$total = $this->database->get_var( "SELECT COUNT(*) FROM {$this->containers_table}{$where}" );
				if ( null === $total || ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				$items = $this->database->get_results(
					$this->database->prepare(
						"SELECT * FROM {$this->containers_table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d",
						$per_page,
						$offset
					),
					ARRAY_A
				);

				if ( ! is_array( $items ) || ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				return array( 'items' => $items, 'total' => (int) $total );
			}
		);
	}

	/**
	 * Create a draft inspection with snapshots read from the existing container.
	 *
	 * @param int   $container_id Container ID.
	 * @param array $data         Validated type, UTC inspection time and observations.
	 * @return array|WP_Error
	 */
	public function create_inspection( $container_id, array $data ) {
		return $this->with_suppressed_errors(
			function () use ( $container_id, $data ) {
				$container = $this->get_container( $container_id );
				if ( is_wp_error( $container ) ) {
					return $container;
				}

				$now    = current_time( 'mysql', true );
				$record = array(
					'container_id'        => (int) $container['id'],
					'identifier_snapshot' => $container['identifier'],
					'iso_code_snapshot'   => (string) $container['iso_code'],
					'inspection_type'     => $data['inspection_type'],
					'inspected_at_utc'    => isset( $data['inspected_at_utc'] ) ? $data['inspected_at_utc'] : $now,
					'observations'        => isset( $data['observations'] ) ? $data['observations'] : '',
					'status'              => 'draft',
					'created_by'          => get_current_user_id(),
					'created_at_utc'      => $now,
				);

				$inserted = $this->database->insert(
					$this->inspections_table,
					$record,
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
				);

				if ( false === $inserted || 1 !== (int) $inserted ) {
					return $this->internal_error();
				}

				$created = $this->get_inspection( (int) $this->database->insert_id );
				return is_wp_error( $created ) ? $this->internal_error() : $created;
			}
		);
	}

	/**
	 * @param int $id Inspection ID.
	 * @return array|WP_Error
	 */
	public function get_inspection( $id ) {
		return $this->with_suppressed_errors(
			function () use ( $id ) {
				$record = $this->database->get_row(
					$this->database->prepare(
						"SELECT * FROM {$this->inspections_table} WHERE id = %d",
						$id
					),
					ARRAY_A
				);

				if ( ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				if ( ! is_array( $record ) ) {
					return new WP_Error(
						'infocontainer_inspection_not_found',
						__( 'La inspección no existe.', 'infocontainer' ),
						array( 'status' => 404 )
					);
				}

				return $record;
			}
		);
	}

	/**
	 * @param int $container_id Container ID.
	 * @param int $page         Page number, starting at one.
	 * @param int $per_page     Number of records, capped at 100.
	 * @return array|WP_Error Array with items and total.
	 */
	public function list_inspections( $container_id, $page, $per_page ) {
		return $this->with_suppressed_errors(
			function () use ( $container_id, $page, $per_page ) {
				$container = $this->get_container( $container_id );
				if ( is_wp_error( $container ) ) {
					return $container;
				}

				$page     = max( 1, (int) $page );
				$per_page = min( 100, max( 1, (int) $per_page ) );
				$offset   = ( $page - 1 ) * $per_page;
				$total    = $this->database->get_var(
					$this->database->prepare(
						"SELECT COUNT(*) FROM {$this->inspections_table} WHERE container_id = %d",
						$container['id']
					)
				);

				if ( null === $total || ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				$items = $this->database->get_results(
					$this->database->prepare(
						"SELECT * FROM {$this->inspections_table} WHERE container_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
						$container['id'],
						$per_page,
						$offset
					),
					ARRAY_A
				);

				if ( ! is_array( $items ) || ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				return array( 'items' => $items, 'total' => (int) $total );
			}
		);
	}

	/**
	 * Prevent SQL details from being emitted by wpdb, restoring its previous setting.
	 *
	 * @param callable $operation Database operation.
	 * @return array|WP_Error
	 */
	private function with_suppressed_errors( $operation ) {
		$previous = $this->database->suppress_errors( true );

		try {
			return $operation();
		} catch ( Throwable $error ) {
			return $this->internal_error();
		} finally {
			$this->database->suppress_errors( $previous );
		}
	}

	/** @return WP_Error */
	private function internal_error() {
		return new WP_Error(
			'infocontainer_internal_error',
			__( 'No se pudo completar la operación. Inténtalo de nuevo.', 'infocontainer' ),
			array( 'status' => 500 )
		);
	}
}

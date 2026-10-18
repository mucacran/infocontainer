<?php
/**
 * Database access for inspection photographs.
 *
 * Rows returned here include private storage keys; the REST layer selects only
 * the public metadata before returning a response.
 *
 * @package InfoContainer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_Photo_Repository {
	/** @var wpdb */
	private $database;

	/** @var string */
	private $photos_table;

	/** @var string */
	private $inspections_table;

	/** @var string Known internal fields, including the private relative key. */
	private $photo_columns = 'id, inspection_id, client_photo_id, category, description, storage_key, original_filename, mime_type, byte_size, width, height, sha256, captured_at_utc, created_by, created_at_utc, updated_by, updated_at_utc';

	/** @var string Known inspection fields needed for the relationship and draft lock. */
	private $inspection_columns = 'id, container_id, identifier_snapshot, iso_code_snapshot, inspection_type, inspected_at_utc, observations, status, created_by, created_at_utc';

	/**
	 * @param wpdb|null $database Optional database connection.
	 */
	public function __construct( $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}

		$this->database          = $database;
		$this->photos_table      = $database->prefix . 'infocontainer_photos';
		$this->inspections_table = $database->prefix . 'infocontainer_inspections';
	}

	/**
	 * @param int $id Photograph ID.
	 * @return array|WP_Error Internal row or a public-safe error.
	 */
	public function get( $id ) {
		return $this->with_suppressed_errors(
			function () use ( $id ) {
				$record = $this->database->get_row(
					$this->database->prepare(
						"SELECT {$this->photo_columns} FROM {$this->photos_table} WHERE id = %d",
						$id
					),
					ARRAY_A
				);

				if ( ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				return is_array( $record ) ? $record : $this->photo_not_found();
			}
		);
	}

	/**
	 * @param int    $inspection_id  Inspection ID.
	 * @param string $client_photo_id Normalized client UUID.
	 * @return array|null|WP_Error Null means that the retry identity has not been used.
	 */
	public function get_by_client( $inspection_id, $client_photo_id ) {
		return $this->with_suppressed_errors(
			function () use ( $inspection_id, $client_photo_id ) {
				$record = $this->database->get_row(
					$this->database->prepare(
						"SELECT {$this->photo_columns} FROM {$this->photos_table} WHERE inspection_id = %d AND client_photo_id = %s",
						$inspection_id,
						$client_photo_id
					),
					ARRAY_A
				);

				if ( ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				return is_array( $record ) ? $record : null;
			}
		);
	}

	/**
	 * @param int $inspection_id Parent inspection ID.
	 * @param int $page          Page number, starting at one.
	 * @param int $per_page      Number of records, capped at 100.
	 * @return array|WP_Error Array with internal items and total.
	 */
	public function list_for_inspection( $inspection_id, $page, $per_page ) {
		return $this->with_suppressed_errors(
			function () use ( $inspection_id, $page, $per_page ) {
				$inspection = $this->get_inspection( $inspection_id );
				if ( is_wp_error( $inspection ) ) {
					return $inspection;
				}

				$page     = max( 1, (int) $page );
				$per_page = min( 100, max( 1, (int) $per_page ) );
				$offset   = ( $page - 1 ) * $per_page;
				$total    = $this->database->get_var(
					$this->database->prepare(
						"SELECT COUNT(*) FROM {$this->photos_table} WHERE inspection_id = %d",
						$inspection_id
					)
				);

				if ( null === $total || ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				$items = $this->database->get_results(
					$this->database->prepare(
						"SELECT {$this->photo_columns} FROM {$this->photos_table} WHERE inspection_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
						$inspection_id,
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
	 * Insert trusted file values and validated client metadata inside the draft lock.
	 * Authorship and registration timestamps always come from the server.
	 *
	 * @param int   $inspection_id Inspection ID.
	 * @param array $record        Validated metadata and storage-derived file properties.
	 * @return array|WP_Error Created internal row or conflict/internal error.
	 */
	public function insert( $inspection_id, array $record ) {
		return $this->with_suppressed_errors(
			function () use ( $inspection_id, $record ) {
				$now    = current_time( 'mysql', true );
				$author = get_current_user_id();
				$values = array(
					'inspection_id'     => (int) $inspection_id,
					'client_photo_id'   => $record['client_photo_id'],
					'category'          => $record['category'],
					'description'       => isset( $record['description'] ) ? $record['description'] : '',
					'storage_key'       => $record['storage_key'],
					'original_filename' => $record['original_filename'],
					'mime_type'         => $record['mime_type'],
					'byte_size'         => (int) $record['byte_size'],
					'width'             => (int) $record['width'],
					'height'            => (int) $record['height'],
					'sha256'            => $record['sha256'],
					'captured_at_utc'   => array_key_exists( 'captured_at_utc', $record ) ? $record['captured_at_utc'] : null,
					'created_by'        => $author,
					'created_at_utc'    => $now,
					'updated_by'        => $author,
					'updated_at_utc'    => $now,
				);

				$inserted = $this->database->insert(
					$this->photos_table,
					$values,
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
				);

				if ( false === $inserted || 1 !== (int) $inserted ) {
					// The unique index remains the final guard for concurrent retries.
					$existing = $this->get_by_client( $inspection_id, $values['client_photo_id'] );
					if ( is_array( $existing ) ) {
						return new WP_Error(
							'infocontainer_photo_client_conflict',
							__( 'Ese client_photo_id ya está registrado para esta inspección.', 'infocontainer' ),
							array( 'status' => 409 )
						);
					}

					return $this->internal_error();
				}

				$created = $this->get( (int) $this->database->insert_id );
				return is_wp_error( $created ) ? $this->internal_error() : $created;
			}
		);
	}

	/**
	 * Update validated client metadata only, inside the draft lock.
	 *
	 * @param int   $id     Photograph ID.
	 * @param array $fields category, description and/or captured_at_utc.
	 * @return array|WP_Error Updated internal row.
	 */
	public function update( $id, array $fields ) {
		return $this->with_suppressed_errors(
			function () use ( $id, $fields ) {
				$existing = $this->get( $id );
				if ( is_wp_error( $existing ) ) {
					return $existing;
				}

				$values  = array();
				$formats = array();
				foreach ( array( 'category', 'description', 'captured_at_utc' ) as $field ) {
					if ( array_key_exists( $field, $fields ) ) {
						$values[ $field ] = $fields[ $field ];
						$formats[]        = '%s';
					}
				}

				$values['updated_by']     = get_current_user_id();
				$values['updated_at_utc'] = current_time( 'mysql', true );
				$formats[]                = '%d';
				$formats[]                = '%s';
				$updated = $this->database->update( $this->photos_table, $values, array( 'id' => (int) $id ), $formats, array( '%d' ) );

				if ( false === $updated || ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				return $this->get( $id );
			}
		);
	}

	/**
	 * Delete a row after the storage operation succeeds, inside the draft lock.
	 *
	 * @param int $id Photograph ID.
	 * @return true|WP_Error
	 */
	public function delete( $id ) {
		return $this->with_suppressed_errors(
			function () use ( $id ) {
				$deleted = $this->database->delete( $this->photos_table, array( 'id' => (int) $id ), array( '%d' ) );
				if ( false === $deleted || ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				return 1 === (int) $deleted ? true : $this->photo_not_found();
			}
		);
	}

	/**
	 * @param int $id Inspection ID.
	 * @return array|WP_Error Inspection row.
	 */
	public function get_inspection( $id ) {
		return $this->with_suppressed_errors(
			function () use ( $id ) {
				$record = $this->database->get_row(
					$this->database->prepare(
						"SELECT {$this->inspection_columns} FROM {$this->inspections_table} WHERE id = %d",
						$id
					),
					ARRAY_A
				);

				if ( ! empty( $this->database->last_error ) ) {
					return $this->internal_error();
				}

				return is_array( $record ) ? $record : $this->inspection_not_found();
			}
		);
	}

	/**
	 * Serialize mutations and future inspection-state transitions on one parent row.
	 * Both inspection and photograph tables must use InnoDB (checked by installation).
	 * The callback runs on this same connection and receives the locked inspection.
	 * File compensation remains the REST/storage layer's responsibility on failure.
	 *
	 * @param int      $inspection_id Parent inspection ID.
	 * @param callable $callback      Operation returning a value or WP_Error.
	 * @return mixed|WP_Error
	 */
	public function with_draft_inspection( $inspection_id, callable $callback ) {
		return $this->with_suppressed_errors(
			function () use ( $inspection_id, $callback ) {
				if ( false === $this->database->query( 'START TRANSACTION' ) ) {
					return $this->internal_error();
				}

				try {
					$inspection = $this->database->get_row(
						$this->database->prepare(
							"SELECT {$this->inspection_columns} FROM {$this->inspections_table} WHERE id = %d FOR UPDATE",
							$inspection_id
						),
						ARRAY_A
					);

					if ( ! empty( $this->database->last_error ) ) {
						$result = $this->internal_error();
					} elseif ( ! is_array( $inspection ) ) {
						$result = $this->inspection_not_found();
					} elseif ( 'draft' !== $inspection['status'] ) {
						$result = new WP_Error(
							'infocontainer_inspection_not_draft',
							__( 'La inspección debe seguir en borrador para modificar sus fotografías.', 'infocontainer' ),
							array( 'status' => 409 )
						);
					} else {
						$result = call_user_func( $callback, $inspection );
					}

					if ( is_wp_error( $result ) ) {
						return false === $this->database->query( 'ROLLBACK' ) ? $this->internal_error() : $result;
					}

					if ( false === $this->database->query( 'COMMIT' ) ) {
						$this->database->query( 'ROLLBACK' );
						return $this->internal_error();
					}

					return $result;
				} catch ( Throwable $error ) {
					$this->database->query( 'ROLLBACK' );
					return $this->internal_error();
				}
			}
		);
	}

	/**
	 * @param callable $operation Database operation.
	 * @return mixed|WP_Error
	 */
	private function with_suppressed_errors( callable $operation ) {
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
	private function photo_not_found() {
		return new WP_Error(
			'infocontainer_photo_not_found',
			__( 'La fotografía no existe.', 'infocontainer' ),
			array( 'status' => 404 )
		);
	}

	/** @return WP_Error */
	private function inspection_not_found() {
		return new WP_Error(
			'infocontainer_inspection_not_found',
			__( 'La inspección no existe.', 'infocontainer' ),
			array( 'status' => 404 )
		);
	}

	/** @return WP_Error */
	private function internal_error() {
		return new WP_Error(
			'infocontainer_photo_internal_error',
			__( 'No se pudo completar la operación de fotografías. Inténtalo de nuevo.', 'infocontainer' ),
			array( 'status' => 500 )
		);
	}
}

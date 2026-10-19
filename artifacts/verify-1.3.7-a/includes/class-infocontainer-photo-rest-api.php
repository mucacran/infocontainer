<?php
/** Authenticated metadata and original-file endpoints for inspection photographs. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_Photo_REST_API {
	const REST_NAMESPACE = 'infocontainer/v1';

	/** @var InfoContainer_Photo_Repository */
	private $repository;

	/** @var InfoContainer_Photo_Storage */
	private $storage;

	/** @var array|null Only this successful, authorized request may emit a file. */
	private $pending_download = null;

	public function __construct( InfoContainer_Photo_Repository $repository, InfoContainer_Photo_Storage $storage ) {
		$this->repository = $repository;
		$this->storage    = $storage;
	}

	public function register_routes() {
		$permission = array( $this, 'check_permissions' );
		register_rest_route( self::REST_NAMESPACE, '/inspections/(?P<id>[0-9]+)/photos', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_photo' ), 'permission_callback' => $permission ),
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_photos' ), 'permission_callback' => $permission ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/photos/(?P<id>[0-9]+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_photo' ), 'permission_callback' => $permission ),
			array( 'methods' => 'PATCH', 'callback' => array( $this, 'update_photo' ), 'permission_callback' => $permission ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_photo' ), 'permission_callback' => $permission ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/photos/(?P<id>[0-9]+)/file', array(
			'methods' => 'GET', 'callback' => array( $this, 'download_photo' ), 'permission_callback' => $permission,
		) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_download' ), 10, 4 );
	}

	/** Preserve the native WordPress identity and the shared Stage 1 capability. */
	public function check_permissions() {
		$api = new InfoContainer_REST_API( new InfoContainer_Repository() );
		return $api->check_permissions();
	}

	/** Upload one original image; retry identity belongs to an inspection and UUID. */
	public function create_photo( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$data = $this->read_multipart( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$metadata = $this->validate_metadata( $data, true );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$files     = $request->get_file_params();
		$validated = $this->storage->validate_upload( $files['file'] );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$new_key = null;
		$result  = $this->repository->with_draft_inspection( $id, function () use ( $id, $metadata, $validated, &$new_key ) {
			$existing = $this->repository->get_by_client( $id, $metadata['client_photo_id'] );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( is_array( $existing ) ) {
				return $this->retry_result( $existing, $validated['sha256'] );
			}
			$key = $this->storage->store( $validated );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			$new_key = $key;
			$record  = $metadata;
			$record['storage_key'] = $key;
			foreach ( array( 'original_filename', 'mime_type', 'byte_size', 'width', 'height', 'sha256' ) as $name ) {
				$record[ $name ] = $validated[ $name ];
			}
			$row = $this->repository->insert( $id, $record );
			if ( is_wp_error( $row ) ) {
				$cleanup = $this->storage->delete( $new_key );
				if ( is_wp_error( $cleanup ) ) {
					return $this->internal_error();
				}
				$new_key = null;
				$error_data = $row->get_error_data();
				if ( is_array( $error_data ) && isset( $error_data['status'] ) && 409 === (int) $error_data['status'] ) {
					// The unique index remains a final guard even if another writer bypasses this lock.
					$existing = $this->repository->get_by_client( $id, $metadata['client_photo_id'] );
					if ( is_wp_error( $existing ) ) {
						return $existing;
					}
					if ( is_array( $existing ) ) {
						return $this->retry_result( $existing, $validated['sha256'] );
					}
				}
				return $row;
			}
			return array( 'row' => $row, 'created' => true );
		} );
		if ( is_wp_error( $result ) ) {
			// Also handle a transaction that failed to commit after a successful INSERT.
			if ( null !== $new_key && is_wp_error( $this->storage->delete( $new_key ) ) ) {
				return $this->internal_error();
			}
			return $result;
		}
		return $this->item_response( $result['row'], $result['created'] ? 201 : 200 );
	}

	public function list_photos( WP_REST_Request $request ) {
		$id = $this->read_id( $request, array( 'page', 'per_page' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$page = $this->pagination( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$result = $this->repository->list_for_inspection( $id, $page['page'], $page['per_page'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = array();
		foreach ( $result['items'] as $row ) {
			$item = $this->public_record( $row );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$items[] = $item;
		}
		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $page['per_page'] ) );
		return $response;
	}

	public function get_photo( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		return is_wp_error( $id ) ? $id : $this->item_response( $this->read_photo( $id ) );
	}

	/** Only mutable metadata is accepted. The parent is checked inside its row lock. */
	public function update_photo( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$data = $this->read_json( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! $data ) {
			return $this->invalid( 'Envía al menos un campo modificable.' );
		}
		$metadata = $this->validate_metadata( $data, false );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$photo = $this->read_photo( $id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}
		$row = $this->repository->with_draft_inspection( (int) $photo['inspection_id'], function () use ( $id, $metadata ) {
			$current = $this->repository->get( $id );
			return is_wp_error( $current ) ? $current : $this->repository->update( $id, $metadata );
		} );
		return $this->item_response( $row );
	}

	/** Stage a reversible file rename before DELETE; confirm only after the unlink. */
	public function delete_photo( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( '' !== trim( $request->get_body() ) || $request->get_body_params() || $request->get_file_params() ) {
			return $this->invalid( 'DELETE no admite campos ni archivos en el cuerpo.' );
		}
		$photo = $this->read_photo( $id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}
		$token = null;
		$result = $this->repository->with_draft_inspection( (int) $photo['inspection_id'], function () use ( $id, &$token ) {
			$current = $this->repository->get( $id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$staged = $this->storage->stage_delete( $current['storage_key'] );
			if ( is_wp_error( $staged ) ) {
				return $staged;
			}
			$token = $staged;
			$deleted = $this->repository->delete( $id );
			return is_wp_error( $deleted ) ? $deleted : array( 'id' => $id );
		} );
		if ( is_wp_error( $result ) ) {
			if ( null !== $token && is_wp_error( $this->storage->restore_delete( $token ) ) ) {
				return $this->internal_error();
			}
			return $result;
		}
		if ( null === $token || is_wp_error( $this->storage->finish_delete( $token ) ) ) {
			return $this->internal_error();
		}
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/** The open descriptor is private state, never response data or a physical URL. */
	public function download_photo( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$query = $request->get_query_params();
		if ( array_key_exists( '_envelope', $query ) ) {
			return $this->invalid( 'La descarga binaria no admite _envelope.' );
		}
		$photo = $this->read_photo( $id );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}
		$extensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		if ( ! isset( $extensions[ $photo['mime_type'] ] ) ) {
			return $this->internal_error();
		}
		$handle = $this->storage->open( $photo['storage_key'] );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}
		$stat = @fstat( $handle );
		if ( ! is_array( $stat ) || (int) $stat['size'] !== (int) $photo['byte_size'] ) {
			@fclose( $handle );
			return $this->internal_error();
		}
		// Core may apply _fields before rest_pre_serve_request. An empty array
		// keeps that filter safe; only the private descriptor supplies the bytes.
		$response = new WP_REST_Response( array(), 200 );
		$response->header( 'Content-Type', $photo['mime_type'] );
		$response->header( 'Content-Length', (string) $photo['byte_size'] );
		$response->header( 'Content-Disposition', 'inline; filename="infocontainer-photo-' . $id . '.' . $extensions[ $photo['mime_type'] ] . '"' );
		$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$this->close_pending_download();
		$this->pending_download = array( 'handle' => $handle, 'request' => $request, 'response' => $response );
		return $response;
	}

	/** Never affect errors, envelope responses, other routes or another request. */
	public function serve_download( $served, $result, $request, $server ) {
		if ( null === $this->pending_download ) {
			return $served;
		}
		$pending = $this->pending_download;
		if ( $served || $request !== $pending['request'] || $result !== $pending['response']
			|| 200 !== $result->get_status()
			|| ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true )
			|| ! preg_match( '#^/' . self::REST_NAMESPACE . '/photos/[1-9][0-9]*/file$#D', $request->get_route() ) ) {
			$this->close_pending_download();
			return $served;
		}
		// Core's no-cache headers may have overwritten ours just before this filter.
		$server->send_headers( $result->get_headers() );
		if ( 'HEAD' !== $request->get_method() ) {
			@fpassthru( $pending['handle'] );
		}
		$this->close_pending_download();
		return true;
	}

	public function __destruct() {
		$this->close_pending_download();
	}

	private function close_pending_download() {
		if ( null !== $this->pending_download && is_resource( $this->pending_download['handle'] ) ) {
			@fclose( $this->pending_download['handle'] );
		}
		$this->pending_download = null;
	}

	private function read_photo( $id ) {
		$row = $this->repository->get( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$inspection = $this->repository->get_inspection( (int) $row['inspection_id'] );
		return is_wp_error( $inspection ) ? $inspection : $row;
	}

	private function retry_result( array $row, $sha256 ) {
		if ( ! hash_equals( (string) $row['sha256'], (string) $sha256 ) ) {
			return new WP_Error( 'infocontainer_photo_retry_conflict', 'client_photo_id ya corresponde a un archivo diferente en esta inspección.', array( 'status' => 409 ) );
		}
		return array( 'row' => $row, 'created' => false );
	}

	/** Parse body fields separately from files, transport parameters and URL IDs. */
	private function read_multipart( WP_REST_Request $request ) {
		if ( ! preg_match( '#^multipart/form-data(?:\s*;|$)#i', (string) $request->get_header( 'content-type' ) ) ) {
			return $this->invalid( 'Utiliza multipart/form-data con un único archivo en el campo file.' );
		}
		$length = $request->get_header( 'content-length' );
		$limit  = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		if ( $limit > 0 && is_scalar( $length ) && ctype_digit( (string) $length ) && (float) $length > $limit ) {
			return new WP_Error( 'infocontainer_photo_size_limit', 'La solicitud supera el límite de subida del servidor.', array( 'status' => 413 ) );
		}
		$files = $request->get_file_params();
		if ( count( $files ) !== 1 || ! isset( $files['file'] ) || ! is_array( $files['file'] )
			|| ! isset( $files['file']['name'] ) || ! is_string( $files['file']['name'] ) ) {
			return $this->invalid( 'Envía exactamente un archivo en el campo file.' );
		}
		$data  = $request->get_body_params();
		$valid = InfoContainer_Validator::assert_allowed_fields( $data, array( 'client_photo_id', 'category', 'description', 'captured_at' ) );
		return is_wp_error( $valid ) ? $valid : $data;
	}

	private function read_json( WP_REST_Request $request ) {
		if ( ! $request->is_json_content_type() || $request->get_file_params() ) {
			return $this->invalid( 'El cuerpo debe ser un objeto JSON con Content-Type: application/json.' );
		}
		$valid = $request->has_valid_params();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! is_object( json_decode( $request->get_body() ) ) ) {
			return $this->invalid( 'El cuerpo debe ser un objeto JSON.' );
		}
		$data  = $request->get_json_params();
		$valid = InfoContainer_Validator::assert_allowed_fields( $data, array( 'category', 'description', 'captured_at' ) );
		return is_wp_error( $valid ) ? $valid : $data;
	}

	/** Omission preserves PATCH fields; captured_at:null explicitly clears a take date. */
	private function validate_metadata( array $data, $create ) {
		$result = array();
		if ( $create ) {
			$uuid = isset( $data['client_photo_id'] ) ? $data['client_photo_id'] : null;
			if ( ! is_string( $uuid ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $uuid ) ) {
				return $this->invalid( 'client_photo_id debe ser un UUID válido con guiones.' );
			}
			$result['client_photo_id'] = strtolower( $uuid );
		}
		if ( $create || array_key_exists( 'category', $data ) ) {
			$category = isset( $data['category'] ) ? $data['category'] : null;
			if ( ! is_string( $category ) || ! in_array( $category, array( 'identifier', 'technical_plate', 'doors', 'front', 'left_side', 'right_side', 'interior', 'floor', 'damage', 'other' ), true ) ) {
				return $this->invalid( 'category debe ser identifier, technical_plate, doors, front, left_side, right_side, interior, floor, damage u other.' );
			}
			$result['category'] = $category;
		}
		if ( $create || array_key_exists( 'description', $data ) ) {
			$text = array_key_exists( 'description', $data ) ? $data['description'] : '';
			if ( ! is_string( $text ) || strlen( $text ) > 8000 || 1 !== preg_match( '//u', $text ) ) {
				return $this->invalid( 'description debe ser texto UTF-8 de hasta 2.000 caracteres.' );
			}
			$length = preg_match_all( '/./us', $text, $characters );
			if ( false === $length || $length > 2000 ) {
				return $this->invalid( 'description admite un máximo de 2.000 caracteres.' );
			}
			$result['description'] = sanitize_textarea_field( $text );
		}
		if ( array_key_exists( 'captured_at', $data ) ) {
			if ( null === $data['captured_at'] && ! $create ) {
				$result['captured_at_utc'] = null;
			} else {
				if ( ! is_string( $data['captured_at'] ) ) {
					return $this->invalid( 'captured_at debe ser una fecha ISO 8601 con zona horaria explícita.' );
				}
				$date = InfoContainer_Validator::inspected_at( $data['captured_at'] );
				if ( is_wp_error( $date ) ) {
					return $this->invalid( 'captured_at debe ser una fecha ISO 8601 válida con segundos y zona Z o ±HH:MM.' );
				}
				$result['captured_at_utc'] = $date;
			}
		} elseif ( $create ) {
			$result['captured_at_utc'] = null;
		}
		return $result;
	}

	private function read_id( WP_REST_Request $request, array $query_allowed = array() ) {
		$valid = InfoContainer_Validator::assert_allowed_fields( $request->get_query_params(), array_merge(
			$query_allowed, array( '_wpnonce', 'rest_route', '_fields', '_envelope', '_pretty', '_locale' )
		) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$path = $request->get_url_params();
		$id   = isset( $path['id'] ) ? $path['id'] : null;
		if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id )
			|| false === filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) {
			return $this->invalid( 'El ID debe ser un entero positivo válido.' );
		}
		return (int) $id;
	}

	private function pagination( WP_REST_Request $request ) {
		$query  = $request->get_query_params();
		$result = array( 'page' => 1, 'per_page' => 20 );
		foreach ( $result as $name => $default ) {
			if ( ! array_key_exists( $name, $query ) ) {
				continue;
			}
			$value = $query[ $name ];
			if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value )
				|| false === filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) {
				return $this->invalid( 'page y per_page deben ser enteros positivos.' );
			}
			$result[ $name ] = (int) $value;
		}
		if ( $result['per_page'] > 100 || $result['page'] - 1 > intdiv( PHP_INT_MAX, $result['per_page'] ) ) {
			return $this->invalid( 'Paginación fuera de rango; per_page admite como máximo 100.' );
		}
		return $result;
	}

	private function item_response( $row, $status = 200 ) {
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$data = $this->public_record( $row );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$response = new WP_REST_Response( $data, $status );
		if ( 201 === $status ) {
			$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/photos/' . $data['id'] ) );
		}
		return $response;
	}

	/** Explicit allowlist: never emit storage keys, paths or public-media URLs. */
	private function public_record( array $row ) {
		$data = array();
		foreach ( array( 'id', 'inspection_id', 'client_photo_id', 'category', 'description', 'original_filename', 'mime_type', 'byte_size', 'width', 'height', 'sha256', 'captured_at_utc', 'created_by', 'created_at_utc', 'updated_by', 'updated_at_utc' ) as $field ) {
			if ( ! array_key_exists( $field, $row ) ) {
				return $this->internal_error();
			}
			$data[ $field ] = $row[ $field ];
		}
		foreach ( array( 'id', 'inspection_id', 'byte_size', 'width', 'height', 'created_by', 'updated_by' ) as $field ) {
			$data[ $field ] = (int) $data[ $field ];
		}
		foreach ( array( 'captured_at_utc', 'created_at_utc', 'updated_at_utc' ) as $field ) {
			if ( 'captured_at_utc' === $field && null === $data[ $field ] ) {
				continue;
			}
			if ( ! is_string( $data[ $field ] ) ) {
				return $this->internal_error();
			}
			$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $data[ $field ], new DateTimeZone( 'UTC' ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( ! $date || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
				return $this->internal_error();
			}
			$data[ $field ] = $date->format( 'Y-m-d\TH:i:s\Z' );
		}
		return $data;
	}

	private function invalid( $message ) {
		return new WP_Error( 'infocontainer_invalid_photo_request', $message, array( 'status' => 400 ) );
	}

	private function internal_error() {
		return new WP_Error( 'infocontainer_photo_internal_error', 'No se pudo completar la operación de la fotografía. Inténtalo de nuevo.', array( 'status' => 500 ) );
	}
}

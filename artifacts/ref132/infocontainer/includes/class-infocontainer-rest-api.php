<?php
/** Protected REST endpoints for containers and manual inspection drafts. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InfoContainer_REST_API {
	const REST_NAMESPACE = 'infocontainer/v1';

	/** @var InfoContainer_Repository */
	private $repository;

	public function __construct( InfoContainer_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_routes() {
		$permission = array( $this, 'check_permissions' );
		register_rest_route( self::REST_NAMESPACE, '/containers', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_container' ), 'permission_callback' => $permission ),
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_containers' ), 'permission_callback' => $permission ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/containers/(?P<id>[0-9]+)', array(
			'methods' => 'GET', 'callback' => array( $this, 'get_container' ), 'permission_callback' => $permission,
		) );
		register_rest_route( self::REST_NAMESPACE, '/containers/(?P<id>[0-9]+)/inspections', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_inspection' ), 'permission_callback' => $permission ),
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_inspections' ), 'permission_callback' => $permission ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/inspections/(?P<id>[0-9]+)', array(
			'methods' => 'GET', 'callback' => array( $this, 'get_inspection' ), 'permission_callback' => $permission,
		) );
	}

	/** Native WordPress authentication identifies the user before authorization. */
	public function check_permissions() {
		if ( ! get_current_user_id() ) {
			return new WP_Error( 'infocontainer_authentication_required', 'Debes autenticarte para utilizar InfoContainer.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_infocontainer' ) ) {
			return new WP_Error( 'infocontainer_forbidden', 'No tienes permiso para utilizar InfoContainer.', array( 'status' => 403 ) );
		}
		return InfoContainer_Install::get_error() ? InfoContainer_Install::get_error() : true;
	}

	public function create_container( WP_REST_Request $request ) {
		$data = $this->read_json( $request, array( 'identifier', 'iso_code' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$identifier = InfoContainer_Validator::identifier( isset( $data['identifier'] ) ? $data['identifier'] : null );
		if ( is_wp_error( $identifier ) ) {
			return $identifier;
		}
		$iso = InfoContainer_Validator::iso_code( isset( $data['iso_code'] ) ? $data['iso_code'] : null );
		if ( is_wp_error( $iso ) ) {
			return $iso;
		}
		$row = $this->repository->create_container( array( 'identifier' => $identifier, 'iso_code' => $iso ) );
		return $this->item_response( $row, 201, 'containers' );
	}

	public function list_containers( WP_REST_Request $request ) {
		$valid = $this->check_query( $request, array( 'page', 'per_page', 'identifier' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$page = $this->pagination( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$query = $request->get_query_params();
		$identifier = '';
		if ( array_key_exists( 'identifier', $query ) ) {
			$identifier = InfoContainer_Validator::identifier( $query['identifier'] );
			if ( is_wp_error( $identifier ) ) {
				return $identifier;
			}
		}
		$result = $this->repository->list_containers( $page['page'], $page['per_page'], $identifier );
		return $this->list_response( $result, $page['per_page'] );
	}

	public function get_container( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return $this->item_response( $this->repository->get_container( $id ) );
	}

	public function create_inspection( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$data = $this->read_json( $request, array( 'inspection_type', 'inspected_at', 'observations' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( array_key_exists( 'inspected_at', $data ) && ! is_string( $data['inspected_at'] ) ) {
			return $this->invalid( 'Cuando se envía inspected_at, debe ser texto ISO 8601 con zona horaria explícita.' );
		}
		$type = InfoContainer_Validator::inspection_type( isset( $data['inspection_type'] ) ? $data['inspection_type'] : null );
		$date = InfoContainer_Validator::inspected_at( isset( $data['inspected_at'] ) ? $data['inspected_at'] : null );
		$observations = InfoContainer_Validator::observations( array_key_exists( 'observations', $data ) ? $data['observations'] : '' );
		foreach ( array( $type, $date, $observations ) as $value ) {
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}
		$row = $this->repository->create_inspection( $id, array(
			'inspection_type' => $type,
			'inspected_at_utc' => $date,
			'observations' => $observations,
		) );
		return $this->item_response( $row, 201, 'inspections' );
	}

	public function list_inspections( WP_REST_Request $request ) {
		$id = $this->read_id( $request, array( 'page', 'per_page' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$page = $this->pagination( $request );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$result = $this->repository->list_inspections( $id, $page['page'], $page['per_page'] );
		return $this->list_response( $result, $page['per_page'] );
	}

	public function get_inspection( WP_REST_Request $request ) {
		$id = $this->read_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return $this->item_response( $this->repository->get_inspection( $id ) );
	}

	/** Never merge query or path parameters into client-supplied record fields. */
	private function read_json( WP_REST_Request $request, array $allowed ) {
		$valid = $this->check_query( $request, array() );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! $request->is_json_content_type() ) {
			return $this->invalid( 'El cuerpo debe ser un objeto JSON con Content-Type: application/json.' );
		}
		$parsed = $request->has_valid_params();
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		if ( ! is_object( json_decode( $request->get_body() ) ) ) {
			return $this->invalid( 'El cuerpo debe ser un objeto JSON.' );
		}
		$data = $request->get_json_params();
		$valid = InfoContainer_Validator::assert_allowed_fields( $data, $allowed );
		return is_wp_error( $valid ) ? $valid : $data;
	}

	/** WordPress transport parameters are allowed, without becoming record fields. */
	private function check_query( WP_REST_Request $request, array $allowed ) {
		return InfoContainer_Validator::assert_allowed_fields(
			$request->get_query_params(),
			array_merge( $allowed, array( '_wpnonce', 'rest_route', '_fields', '_envelope', '_pretty', '_locale' ) )
		);
	}

	private function read_id( WP_REST_Request $request, array $query_allowed = array() ) {
		$valid = $this->check_query( $request, $query_allowed );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$path = $request->get_url_params();
		$id = isset( $path['id'] ) ? $path['id'] : null;
		if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id )
			|| false === filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) {
			return $this->invalid( 'El ID debe ser un entero positivo válido.' );
		}
		return (int) $id;
	}

	private function pagination( WP_REST_Request $request ) {
		$query = $request->get_query_params();
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

	private function item_response( $row, $status = 200, $collection = '' ) {
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$data = $this->format_record( $row );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$response = new WP_REST_Response( $data, $status );
		if ( 201 === $status ) {
			$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/' . $collection . '/' . $data['id'] ) );
		}
		return $response;
	}

	private function list_response( $result, $per_page ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = array();
		foreach ( $result['items'] as $row ) {
			$item = $this->format_record( $row );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$items[] = $item;
		}
		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $per_page ) );
		return $response;
	}

	/** Convert SQL UTC timestamps and numeric columns to the public JSON contract. */
	private function format_record( array $row ) {
		foreach ( array( 'id', 'container_id', 'created_by' ) as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		foreach ( array( 'created_at_utc', 'inspected_at_utc' ) as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $row[ $key ], new DateTimeZone( 'UTC' ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( ! $date || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
				return new WP_Error( 'infocontainer_internal_error', 'No se pudo completar la operación.', array( 'status' => 500 ) );
			}
			$row[ $key ] = $date->format( 'Y-m-d\TH:i:s\Z' );
		}
		return $row;
	}

	private function invalid( $message ) {
		return new WP_Error( 'infocontainer_invalid_request', $message, array( 'status' => 400 ) );
	}
}

<?php
/** Fixed, server-configured OpenAI Responses API client. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class InfoContainer_OpenAI_Client {
	const ENDPOINT = 'https://api.openai.com/v1/responses';
	const DEFAULT_MODEL = 'gpt-5.4-mini';
	const DEFAULT_TIMEOUT = 45;
	const MAX_OUTPUT_TOKENS = 6000;
	private $send_once;

	/**
	 * The optional sender is intentionally only an injection seam for tests.
	 * Production always uses request_once_curl(), which performs one curl_exec().
	 */
	public function __construct( $send_once = null ) { $this->send_once = is_callable( $send_once ) ? $send_once : null; }

	public function configured_model() {
		$model = defined( 'INFOCONTAINER_OPENAI_MODEL' ) ? INFOCONTAINER_OPENAI_MODEL : getenv( 'INFOCONTAINER_OPENAI_MODEL' );
		return is_string( $model ) && preg_match( '/\A[a-zA-Z0-9._-]{1,100}\z/', $model ) ? $model : self::DEFAULT_MODEL;
	}

	public function is_configured() { return '' !== $this->api_key(); }

	/** @return array|WP_Error Parsed proposal plus provider metadata. */
	public function request( $operation, array $images, array $context ) {
		$key = $this->api_key();
		if ( '' === $key ) return $this->error( 'not_configured', 'La integracion de IA no esta configurada.', 503 );
		$schema = 'identification' === $operation ? InfoContainer_AI_Schemas::identification_schema() : InfoContainer_AI_Schemas::analysis_schema();
		$content = array( array( 'type' => 'input_text', 'text' => InfoContainer_AI_Schemas::prompt( $operation ) . "\nContexto de servidor:\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		foreach ( $images as $image ) {
			$bytes = $this->read_stream( $image['handle'], (int) $image['byte_size'] );
			if ( is_wp_error( $bytes ) ) return $bytes;
			$content[] = array( 'type' => 'input_text', 'text' => 'photo_id=' . ( isset( $image['photo_id'] ) ? (int) $image['photo_id'] : 'pre-registration' ) . '; category=' . $image['category'] );
			$content[] = array( 'type' => 'input_image', 'image_url' => 'data:' . $image['mime_type'] . ';base64,' . base64_encode( $bytes ), 'detail' => 'high' );
			unset( $bytes );
		}
		$model = $this->configured_model();
		$body = array(
			'model' => $model, 'store' => false,
			'reasoning' => array( 'effort' => 'none' ),
			'max_output_tokens' => (int) apply_filters( 'infocontainer_ai_max_output_tokens', self::MAX_OUTPUT_TOKENS, $operation ),
			'input' => array( array( 'role' => 'user', 'content' => $content ) ),
			'text' => array( 'format' => array( 'type' => 'json_schema', 'name' => 'infocontainer_' . $operation, 'strict' => true, 'schema' => $schema ) ),
		);
		$timeout = max( 10, min( 120, (int) apply_filters( 'infocontainer_ai_timeout', self::DEFAULT_TIMEOUT, $operation ) ) );
		$encoded_body = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded_body ) ) return $this->error( 'provider_transport', 'No se pudo preparar la solicitud al proveedor.', 502 );
		$response = $this->send_once( $key, $encoded_body, $timeout );
		unset( $key, $body, $content );
		if ( is_wp_error( $response ) ) {
			$transport_code = (string) $response->get_error_code();
			$code = in_array( $transport_code, array( 'timeout', 'timed_out', 'curl_timeout', 'http_request_failed_timeout' ), true ) ? 'timeout_unknown' : 'provider_transport';
			return $this->error( $code, 'No se pudo obtener una respuesta valida del proveedor.', 'timeout_unknown' === $code ? 504 : 502 );
		}
		$status = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$data = json_decode( isset( $response['body'] ) ? (string) $response['body'] : '', true );
		if ( 200 !== $status || ! is_array( $data ) ) return $this->error( 'provider_error', 'El proveedor no completo la solicitud.', 502 );
		$metadata = array( 'provider_response_id' => isset( $data['id'] ) && is_string( $data['id'] ) ? substr( $data['id'], 0, 190 ) : '', 'model_used' => isset( $data['model'] ) && is_string( $data['model'] ) ? substr( $data['model'], 0, 100 ) : '', 'usage' => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array() );
		if ( ! isset( $data['status'] ) || 'completed' !== $data['status'] ) return $this->error( 'provider_incomplete', 'El proveedor devolvio una respuesta incompleta.', 502, $metadata );
		$texts = array();
		$refused = false;
		foreach ( isset( $data['output'] ) && is_array( $data['output'] ) ? $data['output'] : array() as $item ) {
			foreach ( isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array() as $part ) {
				if ( isset( $part['type'] ) && 'refusal' === $part['type'] ) $refused = true;
				if ( isset( $part['type'], $part['text'] ) && 'output_text' === $part['type'] && is_string( $part['text'] ) ) $texts[] = $part['text'];
			}
		}
		if ( $refused ) return $this->error( 'provider_refusal', 'El proveedor rechazo analizar el contenido.', 422, $metadata );
		if ( empty( $texts ) ) return $this->error( 'provider_empty', 'El proveedor no devolvio contenido estructurado.', 502, $metadata );
		$proposal = json_decode( implode( '', $texts ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $proposal ) ) return $this->error( 'provider_invalid_json', 'El proveedor devolvio contenido estructurado invalido.', 502, $metadata );
		$metadata['proposal'] = $proposal;
		return $metadata;
	}
	private function send_once( $key, $body, $timeout ) {
		if ( null !== $this->send_once ) return call_user_func( $this->send_once, self::ENDPOINT, $key, $body, $timeout );
		return $this->request_once_curl( $key, $body, $timeout );
	}
	/** One direct cURL execution. Never fall back to the WordPress Requests transport. */
	private function request_once_curl( $key, $body, $timeout ) {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) return $this->error( 'provider_transport', 'El transporte seguro no esta disponible.', 502 );
		$handle = curl_init( self::ENDPOINT );
		if ( false === $handle ) return $this->error( 'provider_transport', 'No se pudo inicializar el transporte seguro.', 502 );
		$options = array(
			CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
			CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => min( 10, (int) $timeout ), CURLOPT_TIMEOUT => (int) $timeout,
			CURLOPT_HTTPHEADER => array( 'Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json' ),
		);
		if ( ! curl_setopt_array( $handle, $options ) ) { curl_close( $handle ); return $this->error( 'provider_transport', 'No se pudo configurar el transporte seguro.', 502 ); }
		$result = curl_exec( $handle );
		$error_number = curl_errno( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );
		if ( false === $result ) return $this->error( 28 === $error_number ? 'timeout' : 'provider_transport', 'No se pudo obtener una respuesta valida del proveedor.', 28 === $error_number ? 504 : 502 );
		return array( 'status' => $status, 'body' => (string) $result );
	}

	private function api_key() {
		$value = defined( 'INFOCONTAINER_OPENAI_API_KEY' ) ? INFOCONTAINER_OPENAI_API_KEY : getenv( 'INFOCONTAINER_OPENAI_API_KEY' );
		if ( is_string( $value ) && '' !== trim( $value ) ) return trim( $value );
		return class_exists( 'InfoContainer_OpenAI_Settings' ) ? InfoContainer_OpenAI_Settings::stored_key() : '';
	}
	private function read_stream( $handle, $expected ) {
		if ( ! is_resource( $handle ) || $expected < 1 ) return $this->error( 'image_read', 'No se pudo leer una imagen privada.', 500 );
		rewind( $handle ); $bytes = stream_get_contents( $handle, $expected + 1 );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $expected ) return $this->error( 'image_read', 'No se pudo leer una imagen privada.', 500 );
		return $bytes;
	}
	private function error( $suffix, $message, $status, array $metadata = array() ) { return new WP_Error( 'infocontainer_ai_' . $suffix, __( $message, 'infocontainer' ), array_merge( array( 'status' => $status ), $metadata ) ); }
}

<?php
/** Local, offline transport checks. Run with PHP CLI; never uses the network. */
define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'INFOCONTAINER_OPENAI_API_KEY', 'unit-test-placeholder' );

class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $message ) { return $message; }
function apply_filters( $name, $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
class InfoContainer_AI_Schemas {
	public static function identification_schema() { return array( 'type' => 'object' ); }
	public static function analysis_schema() { return array( 'type' => 'object' ); }
	public static function prompt( $operation ) { return $operation; }
}
require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-infocontainer-openai-client.php';

function check( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
function response_body() {
	return json_encode( array(
		'id' => 'resp_test', 'model' => 'test-model', 'status' => 'completed',
		'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => '{"visible_identifier_text":null,"candidates":[],"iso_code":null,"legibility":"unreadable","warnings":[]}' ) ) ) ),
	) );
}
function run_request( $response, &$calls, $expected_status = null ) {
	$calls = 0;
	$sender = function ( $endpoint, $key, $body, $timeout ) use ( &$calls, $response ) {
		++$calls;
		check( 'https://api.openai.com/v1/responses' === $endpoint, 'endpoint changed' );
		check( '' !== $key && 45 === $timeout && false !== strpos( $body, '"model"' ), 'request options changed' );
		return is_callable( $response ) ? call_user_func( $response ) : $response;
	};
	$client = new InfoContainer_OpenAI_Client( $sender );
	$handle = fopen( 'php://memory', 'r+' ); fwrite( $handle, 'image' );
	$result = $client->request( 'identification', array( array( 'handle' => $handle, 'byte_size' => 5, 'mime_type' => 'image/jpeg', 'category' => 'container' ) ), array() );
	fclose( $handle );
	check( 1 === $calls, 'transport invoked more than once' );
	if ( null !== $expected_status ) check( is_wp_error( $result ) && 'infocontainer_ai_' . $expected_status === $result->get_error_code(), 'unexpected error: ' . $expected_status );
	return $result;
}

$calls = 0;
$result = run_request( array( 'status' => 200, 'body' => response_body() ), $calls );
check( is_array( $result ) && 'resp_test' === $result['provider_response_id'], 'valid response not parsed' );
run_request( array( 'status' => 500, 'body' => '{}' ), $calls, 'provider_error' );
run_request( new WP_Error( 'timeout' ), $calls, 'timeout_unknown' );
run_request( new WP_Error( 'curl_bad_content_encoding' ), $calls, 'provider_transport' );
run_request( new WP_Error( 'curl_write_error' ), $calls, 'provider_transport' );
run_request( function () { throw new RuntimeException( 'simulated receive failure' ); }, $calls, 'provider_transport' );
run_request( array( 'status' => 200, 'body' => json_encode( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => '{invalid' ) ) ) ) ) ) ), $calls, 'provider_invalid_json' );

$source = file_get_contents( dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-infocontainer-openai-client.php' );
check( 1 === substr_count( $source, 'curl_exec( $handle )' ), 'production transport does not have exactly one curl_exec call' );
echo "openai-client-transport: OK (offline, 7 cases; no HTTP requests)\n";

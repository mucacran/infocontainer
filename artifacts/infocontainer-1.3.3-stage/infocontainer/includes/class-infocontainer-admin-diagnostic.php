<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class InfoContainer_Admin_Diagnostic {
	const TEST_SHA256 = '738058ab3581c712288647f79b efee81cc359f52cc1377884423f270131bd8c6';
	public function register() { add_management_page( 'Diagnóstico de InfoContainer', 'Diagnóstico de InfoContainer', 'manage_options', 'infocontainer-diagnostic', array( $this, 'render' ) ); }
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'No autorizado.', 'infocontainer' ), '', array( 'response' => 403 ) ); }
		$storage = new InfoContainer_Photo_Storage(); $d = $storage->diagnostic();
		echo '<div class="wrap"><h1>Diagnóstico de InfoContainer</h1><table class="widefat striped"><tbody>';
		$rows = array( 'Versión del plugin' => INFOCONTAINER_VERSION, 'ABSPATH' => ABSPATH, 'Document root PHP' => ( isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '(no informado)' ), 'Raíz privada efectiva configurada' => $d['configured'], 'Existe' => $d['exists'] ? 'Sí' : 'No', 'Ruta resuelta' => $d['resolved'] ?: '(aún no existe)', 'Fuera de directorios públicos comprobables' => $d['outside_public'] ? 'Sí' : 'No / no determinable', 'Ancestro existente' => $d['ancestor'] ?: '(no determinable)', 'PHP puede leer ancestro' => $d['ancestor_readable'] ? 'Sí' : 'No', 'PHP puede escribir ancestro' => $d['ancestor_writable'] ? 'Sí' : 'No', 'Enlaces en la ruta' => $d['links_in_path'] ? 'Sí' : 'No detectados' );
		foreach ( $rows as $label => $value ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; }
		echo '</tbody></table><p>Siguen pendientes: aliases, subdominios, CDN y reglas del servidor alojado. La consulta normal no crea carpetas, archivos, registros ni cambia permisos.</p>';
		if ( isset( $_GET['infocontainer_test_result'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['infocontainer_test_result'] ) ) ) . '</p></div>'; }
		echo '<h2>Prueba de almacenamiento (PNG sintético)</h2><p>Solo acepta el archivo de prueba distribuido con este diagnóstico. La acción crea y elimina únicamente su archivo.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'infocontainer_storage_test', '_infocontainer_storage_nonce' );
		echo '<input type="file" name="infocontainer_test_file" accept="image/png" required><input type="hidden" name="action" value="infocontainer_storage_test"><p><button class="button button-primary" type="submit">Ejecutar prueba explícita</button></p></form></div>';
	}
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_infocontainer_storage_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_infocontainer_storage_nonce'] ) ), 'infocontainer_storage_test' ) ) { wp_die( 'Solicitud no autorizada.', '', array( 'response' => 403 ) ); }
		$file = isset( $_FILES['infocontainer_test_file'] ) ? $_FILES['infocontainer_test_file'] : array();
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || hash_file( 'sha256', $file['tmp_name'] ) !== str_replace( ' ', '', self::TEST_SHA256 ) ) { $this->redirect_result( 'Archivo rechazado: no coincide con el PNG sintético autorizado.' ); }
		$storage = new InfoContainer_Photo_Storage(); $validated = $storage->validate_upload( $file );
		if ( is_wp_error( $validated ) ) { $this->redirect_result( 'Validación rechazada: ' . $validated->get_error_code() ); }
		$key = $storage->store( $validated ); if ( is_wp_error( $key ) ) { $this->redirect_result( 'Escritura fallida: ' . $key->get_error_code() ); }
		$path = $storage->open( $key ); $read_ok = ! is_wp_error( $path ) && filesize( $path ) === filesize( $file['tmp_name'] ) && hash_file( 'sha256', $path ) === str_replace( ' ', '', self::TEST_SHA256 );
		$deleted = $storage->delete( $key );
		$this->redirect_result( $read_ok && ! is_wp_error( $deleted ) ? 'Prueba completada: creación, escritura, lectura, integridad y limpieza confirmadas.' : 'Prueba incompleta: no se confirmaron todas las fases; revise el almacenamiento.', true );
	}
	private function redirect_result( $message, $success = false ) { $url = add_query_arg( array( 'page' => 'infocontainer-diagnostic', 'infocontainer_test_result' => $message ), admin_url( 'tools.php' ) ); wp_safe_redirect( $url ); exit; }
}

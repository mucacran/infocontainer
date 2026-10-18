<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class InfoContainer_Admin_Diagnostic {
	public function register() { add_management_page( 'Diagnóstico de InfoContainer', 'Diagnóstico de InfoContainer', 'manage_options', 'infocontainer-diagnostic', array( $this, 'render' ) ); }
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'No autorizado.', 'infocontainer' ), '', array( 'response' => 403 ) ); }
		$storage = new InfoContainer_Photo_Storage(); $d = $storage->diagnostic();
		echo '<div class="wrap"><h1>Diagnóstico de InfoContainer</h1><table class="widefat striped"><tbody>';
		$rows = array( 'Versión del plugin' => INFOCONTAINER_VERSION, 'ABSPATH' => ABSPATH, 'Document root PHP' => ( isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '(no informado)' ), 'Raíz privada efectiva configurada' => $d['configured'], 'Existe' => $d['exists'] ? 'Sí' : 'No', 'Ruta resuelta' => $d['resolved'] ?: '(aún no existe)', 'Fuera de directorios públicos comprobables' => $d['outside_public'] ? 'Sí' : 'No / no determinable', 'Ancestro existente' => $d['ancestor'] ?: '(no determinable)', 'PHP puede leer ancestro' => $d['ancestor_readable'] ? 'Sí' : 'No', 'PHP puede escribir ancestro' => $d['ancestor_writable'] ? 'Sí' : 'No', 'Enlaces en la ruta' => $d['links_in_path'] ? 'Sí' : 'No detectados' );
		foreach ( $rows as $label => $value ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; }
		echo '</tbody></table><p>Siguen pendientes: aliases, subdominios, CDN y reglas del servidor alojado. Esta pantalla es informativa y no crea carpetas, archivos, registros ni cambia permisos.</p></div>';
	}
}

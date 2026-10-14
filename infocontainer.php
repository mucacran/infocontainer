<?php
/**
 * Plugin Name:       InfoContainer
 * Plugin URI:        https://github.com/mucacran/infocontainer
 * Description:       Contenedores, inspecciones y fotografías privadas mediante una API REST protegida.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            mucacran
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       infocontainer
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INFOCONTAINER_VERSION', '1.2.0' );
define( 'INFOCONTAINER_FILE', __FILE__ );
define( 'INFOCONTAINER_PATH', plugin_dir_path( __FILE__ ) );
define( 'INFOCONTAINER_URL', plugin_dir_url( __FILE__ ) );

require_once INFOCONTAINER_PATH . 'includes/class-infocontainer-install.php';
require_once INFOCONTAINER_PATH . 'includes/class-infocontainer-validator.php';
require_once INFOCONTAINER_PATH . 'includes/class-infocontainer-repository.php';
require_once INFOCONTAINER_PATH . 'includes/class-infocontainer-rest-api.php';
require_once INFOCONTAINER_PATH . 'includes/class-infocontainer-photo-storage.php';
require_once INFOCONTAINER_PATH . 'includes/class-infocontainer-photo-repository.php';
require_once INFOCONTAINER_PATH . 'includes/class-infocontainer-photo-rest-api.php';

/**
 * Runs when the plugin is activated.
 *
 * Install or upgrade the schema without inserting example records.
 *
 * @return void
 */
function infocontainer_activate() {
	$result = InfoContainer_Install::install();
	if ( is_wp_error( $result ) ) {
		wp_die(
			esc_html( $result->get_error_message() ),
			esc_html__( 'InfoContainer: instalación incompleta', 'infocontainer' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'infocontainer_activate' );

/**
 * Runs when the plugin is deactivated.
 *
 * Containers, inspections and permissions are deliberately retained.
 *
 * @return void
 */
function infocontainer_deactivate() {
	// No persistent data is removed on deactivation.
}
register_deactivation_hook( __FILE__, 'infocontainer_deactivate' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function infocontainer_init() {
	InfoContainer_Install::maybe_upgrade();
	add_action( 'rest_api_init', 'infocontainer_register_rest_routes' );
	add_action( 'init', 'infocontainer_load_textdomain' );
}
add_action( 'plugins_loaded', 'infocontainer_init' );

/** Load translations when WordPress initializes its locale. */
function infocontainer_load_textdomain() {
	load_plugin_textdomain(
		'infocontainer',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}

/** Register the API only when the REST server is initialized. */
function infocontainer_register_rest_routes() {
	$api = new InfoContainer_REST_API( new InfoContainer_Repository() );
	$api->register_routes();
	$photos = new InfoContainer_Photo_REST_API( new InfoContainer_Photo_Repository(), new InfoContainer_Photo_Storage() );
	$photos->register_routes();
}

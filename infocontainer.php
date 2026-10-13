<?php
/**
 * Plugin Name:       InfoContainer
 * Plugin URI:        https://example.com/infocontainer
 * Description:       Plugin base InfoContainer para WordPress.
 * Version:           1.0.0
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

define( 'INFOCONTAINER_VERSION', '1.0.0' );
define( 'INFOCONTAINER_FILE', __FILE__ );
define( 'INFOCONTAINER_PATH', plugin_dir_path( __FILE__ ) );
define( 'INFOCONTAINER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Runs when the plugin is activated.
 *
 * Add setup tasks here as the plugin grows.
 *
 * @return void
 */
function infocontainer_activate() {
	// TODO: Add activation setup when needed.
}
register_activation_hook( __FILE__, 'infocontainer_activate' );

/**
 * Runs when the plugin is deactivated.
 *
 * Add temporary cleanup tasks here as the plugin grows.
 *
 * @return void
 */
function infocontainer_deactivate() {
	// TODO: Add deactivation cleanup when needed.
}
register_deactivation_hook( __FILE__, 'infocontainer_deactivate' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function infocontainer_init() {
	load_plugin_textdomain(
		'infocontainer',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'infocontainer_init' );

<?php
/**
 * Plugin Name:       DEV3 AI Shopping Assistant & Chatbot for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-shopping-assistant-for-woocommerce
 * Description:       An AI shopping assistant chat for WooCommerce that knows your catalog, orders and store policies, recommends products, tracks orders and adds to cart.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-shopping-assistant-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiShoppingAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3SA_VERSION', '1.0.0' );
define( 'DEV3SA_FILE', __FILE__ );
define( 'DEV3SA_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3SA_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3SA_SLUG', 'dev3-ai-shopping-assistant-for-woocommerce' );
define( 'DEV3SA_NAME', 'DEV3 AI Shopping Assistant & Chatbot for WooCommerce' );
define( 'DEV3SA_MIN_PHP', '7.4' );
define( 'DEV3SA_MIN_WP', '6.0' );
define( 'DEV3SA_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3SA_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiShoppingAssistant\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3SA_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiShoppingAssistant\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiShoppingAssistant\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiShoppingAssistant\Plugin', 'instance' ) );

<?php
/**
 * Loads WooCommerce before the plugin in the test suite and installs its tables.
 *
 * @package Dev3\AiShoppingAssistant
 */

tests_add_filter(
	'dev3sa_test_dependencies',
	static function () {
		return array( file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php' );
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles WooCommerce just added.
			wp_roles();
		}
	}
);

tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'shop@example.com';
	}
);

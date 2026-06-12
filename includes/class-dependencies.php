<?php
/**
 * Plugin dependency checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMP_Site_Dependencies {
	public static function woocommerce_available() {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}
}

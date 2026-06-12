<?php
/**
 * Plugin Name: Woods Mystery Plugin
 * Plugin URI: https://github.com/BetterBetterBetter/woodsmystery-plugin
 * Description: Site-specific functionality for Woods Mystery.
 * Version: 0.1.2
 * Author: Thrice Agency
 * License: GPL v2 or later
 * Text Domain: woodsmystery-plugin
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI: https://github.com/BetterBetterBetter/woodsmystery-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMP_SITE_PLUGIN_VERSION', '0.1.2' );
define( 'WMP_SITE_PLUGIN_FILE', __FILE__ );
define( 'WMP_SITE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WMP_SITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMP_SITE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WMP_SITE_PLUGIN_REPOSITORY_URL', 'https://github.com/BetterBetterBetter/woodsmystery-plugin' );

if ( file_exists( WMP_SITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once WMP_SITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

	$wmp_site_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		WMP_SITE_PLUGIN_REPOSITORY_URL,
		__FILE__,
		'woodsmystery-plugin'
	);

	$wmp_site_update_checker->getVcsApi()->enableReleaseAssets();
}

require_once WMP_SITE_PLUGIN_DIR . 'includes/contracts/interface-feature.php';
require_once WMP_SITE_PLUGIN_DIR . 'includes/class-dependencies.php';
require_once WMP_SITE_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once WMP_SITE_PLUGIN_DIR . 'includes/features/mystery-mailchimp/class-mystery-mailchimp.php';
require_once WMP_SITE_PLUGIN_DIR . 'includes/class-plugin.php';

WoodsMysteryPlugin::get_instance();

register_activation_hook( __FILE__, array( 'WoodsMysteryPlugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WoodsMysteryPlugin', 'deactivate' ) );

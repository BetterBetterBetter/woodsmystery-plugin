<?php
/**
 * Main plugin loader.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WoodsMysteryPlugin {
	private static $instance = null;

	private $admin_settings  = null;
	private $features        = array();
	private $admin_page_hooks = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'render_dependency_admin_notice' ) );
		add_filter( 'plugin_action_links_' . WMP_SITE_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	public function init() {
		load_plugin_textdomain( 'woodsmystery-plugin', false, dirname( WMP_SITE_PLUGIN_BASENAME ) . '/languages' );

		if ( ! $this->dependencies_met() ) {
			return;
		}

		$this->admin_settings = new WMP_Site_Admin_Settings();
		$this->admin_settings->init();

		$this->register_features();
		$this->init_features();

		/**
		 * Fires after the site plugin has loaded and dependency checks passed.
		 *
		 * @param WoodsMysteryPlugin $plugin Plugin instance.
		 */
		do_action( 'woodsmystery_plugin_loaded', $this );
	}

	public function add_admin_menu() {
		if ( ! $this->dependencies_met() ) {
			return;
		}

		$this->admin_page_hooks[] = add_menu_page(
			__( 'Woods Mystery', 'woodsmystery-plugin' ),
			__( 'Woods Mystery', 'woodsmystery-plugin' ),
			'manage_options',
			'woodsmystery-site',
			array( $this, 'render_settings_page' ),
			'dashicons-tickets-alt',
			58
		);

		$this->admin_page_hooks[] = add_submenu_page(
			'woodsmystery-site',
			__( 'Woods Mystery Dashboard', 'woodsmystery-plugin' ),
			__( 'Dashboard', 'woodsmystery-plugin' ),
			'manage_options',
			'woodsmystery-site',
			array( $this, 'render_settings_page' )
		);

		$this->admin_page_hooks[] = add_submenu_page(
			'woodsmystery-site',
			__( 'Mystery Mailchimp', 'woodsmystery-plugin' ),
			__( 'Mystery Mailchimp', 'woodsmystery-plugin' ),
			'manage_woocommerce',
			WMP_Mystery_Mailchimp::PAGE_SLUG,
			array( $this, 'render_mystery_mailchimp_page' )
		);

		$this->admin_page_hooks[] = add_submenu_page(
			'woocommerce',
			__( 'Mystery Mailchimp', 'woodsmystery-plugin' ),
			__( 'Mystery Mailchimp', 'woodsmystery-plugin' ),
			'manage_woocommerce',
			WMP_Mystery_Mailchimp::PAGE_SLUG,
			array( $this, 'render_mystery_mailchimp_page' )
		);
	}

	public function admin_enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, $this->admin_page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'woodsmystery-admin',
			WMP_SITE_PLUGIN_URL . 'assets/admin/admin.css',
			array(),
			WMP_SITE_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'woodsmystery-admin',
			WMP_SITE_PLUGIN_URL . 'assets/admin/admin.js',
			array( 'jquery' ),
			WMP_SITE_PLUGIN_VERSION,
			true
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->admin_settings ) {
			$this->admin_settings = new WMP_Site_Admin_Settings();
		}

		$this->admin_settings->display_page();
	}

	public function render_mystery_mailchimp_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		WMP_Mystery_Mailchimp::render_admin_page();
	}

	public function render_dependency_admin_notice() {
		if ( $this->dependencies_met() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Woods Mystery Plugin requires WooCommerce to be installed and active.', 'woodsmystery-plugin' );
		echo '</p></div>';
	}

	public function plugin_action_links( $links ) {
		if ( $this->dependencies_met() ) {
			$settings_link = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=woodsmystery-site' ) ),
				esc_html__( 'Settings', 'woodsmystery-plugin' )
			);

			array_unshift( $links, $settings_link );
		}

		return $links;
	}

	public function dependencies_met() {
		return self::is_woocommerce_available();
	}

	public static function is_woocommerce_available() {
		return WMP_Site_Dependencies::woocommerce_available();
	}

	public static function activate() {
		if ( ! self::is_woocommerce_available() ) {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( WMP_SITE_PLUGIN_BASENAME );
			}

			wp_die(
				esc_html__( 'Woods Mystery Plugin requires WooCommerce to be installed and active before activation.', 'woodsmystery-plugin' ),
				esc_html__( 'Plugin dependency missing', 'woodsmystery-plugin' ),
				array( 'back_link' => true )
			);
		}
	}

	public static function deactivate() {
		// Reserved for future cleanup. Do not delete settings on deactivation.
	}

	private function register_features() {
		$this->features = array(
			new WMP_Mystery_Mailchimp(),
		);

		/**
		 * Filters site plugin features before they are initialized.
		 *
		 * Each feature should implement WMP_Site_Feature.
		 *
		 * @param array $features Feature instances.
		 */
		$this->features = apply_filters( 'woodsmystery_plugin_features', $this->features );
	}

	private function init_features() {
		if ( '1' !== get_option( 'woodsmystery_plugin_enabled', '1' ) ) {
			return;
		}

		foreach ( $this->features as $feature ) {
			if ( $feature instanceof WMP_Site_Feature ) {
				$feature->init();
			}
		}
	}
}

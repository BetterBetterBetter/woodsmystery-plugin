<?php
/**
 * Admin settings/status page for Woods Mystery site features.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMP_Site_Admin_Settings {
	private $options_group = 'woodsmystery_plugin_settings';
	private $page_slug     = 'woodsmystery-plugin';

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		add_settings_section(
			'woodsmystery_general',
			__( 'General Settings', 'woodsmystery-plugin' ),
			array( $this, 'general_section_callback' ),
			$this->page_slug
		);

		add_settings_field(
			'woodsmystery_plugin_enabled',
			__( 'Enable Site Features', 'woodsmystery-plugin' ),
			array( $this, 'enabled_callback' ),
			$this->page_slug,
			'woodsmystery_general'
		);

		register_setting(
			$this->options_group,
			'woodsmystery_plugin_enabled',
			array(
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => '1',
			)
		);
	}

	public function display_page() {
		?>
		<div class="wrap woodsmystery-plugin-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<div class="woodsmystery-status-card">
				<h2><?php esc_html_e( 'Site Plugin Status', 'woodsmystery-plugin' ); ?></h2>
				<dl>
					<dt><?php esc_html_e( 'Plugin version', 'woodsmystery-plugin' ); ?></dt>
					<dd><?php echo esc_html( WMP_SITE_PLUGIN_VERSION ); ?></dd>

					<dt><?php esc_html_e( 'WooCommerce', 'woodsmystery-plugin' ); ?></dt>
					<dd>
						<?php if ( WoodsMysteryPlugin::is_woocommerce_available() ) : ?>
							<span class="woodsmystery-status woodsmystery-status--ok"><?php esc_html_e( 'Active', 'woodsmystery-plugin' ); ?></span>
						<?php else : ?>
							<span class="woodsmystery-status woodsmystery-status--error"><?php esc_html_e( 'Missing', 'woodsmystery-plugin' ); ?></span>
						<?php endif; ?>
					</dd>

					<dt><?php esc_html_e( 'Home URL', 'woodsmystery-plugin' ); ?></dt>
					<dd><code><?php echo esc_html( home_url() ); ?></code></dd>

					<dt><?php esc_html_e( 'QC SOP', 'woodsmystery-plugin' ); ?></dt>
					<dd><code><?php echo esc_html( WMP_SITE_PLUGIN_DIR . 'mystery-party-mailchimp-qc.md' ); ?></code></dd>
				</dl>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( $this->options_group );
				do_settings_sections( $this->page_slug );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function general_section_callback() {
		echo '<p>' . esc_html__( 'Use this plugin for Woods Mystery site-specific features that should not live in the theme or shared plugins.', 'woodsmystery-plugin' ) . '</p>';
	}

	public function enabled_callback() {
		$value = get_option( 'woodsmystery_plugin_enabled', '1' );

		echo '<label>';
		echo '<input type="checkbox" name="woodsmystery_plugin_enabled" value="1" ' . checked( '1', $value, false ) . '>';
		echo ' ' . esc_html__( 'Load Woods Mystery site-specific hooks.', 'woodsmystery-plugin' );
		echo '</label>';
	}

	public function sanitize_checkbox( $value ) {
		return '1' === $value ? '1' : '0';
	}
}

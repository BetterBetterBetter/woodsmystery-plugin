<?php
/**
 * Admin settings/status page for Woods Mystery site features.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMP_Site_Admin_Settings {
	const OPTION_ERROR_NOTIFICATION_EMAILS = 'woodsmystery_plugin_error_notification_emails';

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

		add_settings_field(
			self::OPTION_ERROR_NOTIFICATION_EMAILS,
			__( 'Send Mailchimp errors to', 'woodsmystery-plugin' ),
			array( $this, 'error_notification_emails_callback' ),
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

		register_setting(
			$this->options_group,
			self::OPTION_ERROR_NOTIFICATION_EMAILS,
			array(
				'sanitize_callback' => array( $this, 'sanitize_email_list' ),
				'default'           => '',
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

	public function error_notification_emails_callback() {
		$value = get_option( self::OPTION_ERROR_NOTIFICATION_EMAILS, '' );

		printf(
			'<textarea class="large-text code" rows="3" name="%1$s" id="%1$s">%2$s</textarea>',
			esc_attr( self::OPTION_ERROR_NOTIFICATION_EMAILS ),
			esc_textarea( $value )
		);

		echo '<p class="description">';
		echo esc_html__( 'Enter one or more email addresses separated by commas or new lines. These recipients are notified when a Mystery Mailchimp audience sync or welcome email trigger fails.', 'woodsmystery-plugin' );
		echo '</p>';
	}

	public function sanitize_checkbox( $value ) {
		return '1' === $value ? '1' : '0';
	}

	public function sanitize_email_list( $value ) {
		$emails  = preg_split( '/[\s,;]+/', (string) $value );
		$valid   = array();
		$invalid = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( trim( $email ) );

			if ( '' === $email ) {
				continue;
			}

			if ( is_email( $email ) ) {
				$valid[ strtolower( $email ) ] = $email;
				continue;
			}

			$invalid[] = $email;
		}

		if ( ! empty( $invalid ) ) {
			add_settings_error(
				self::OPTION_ERROR_NOTIFICATION_EMAILS,
				'woodsmystery_invalid_error_notification_emails',
				__( 'Some Mailchimp error notification emails were invalid and were not saved.', 'woodsmystery-plugin' ),
				'warning'
			);
		}

		return implode( ', ', array_values( $valid ) );
	}

	public static function get_error_notification_emails() {
		$value  = get_option( self::OPTION_ERROR_NOTIFICATION_EMAILS, '' );
		$emails = preg_split( '/[\s,;]+/', (string) $value );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $email ) {
							$email = sanitize_email( trim( $email ) );
							return is_email( $email ) ? $email : '';
						},
						$emails
					)
				)
			)
		);
	}
}

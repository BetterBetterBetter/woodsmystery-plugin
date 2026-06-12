<?php
/**
 * Mystery Party Mailchimp feature.
 *
 * Syncs ticket buyers to the correct party-specific Mailchimp audience and
 * validates couple attendee details.
 */

defined( 'ABSPATH' ) || exit;

final class WMP_Mystery_Mailchimp implements WMP_Site_Feature {
	const PAGE_SLUG              = 'woodsmystery-mystery-mailchimp';
	const PRODUCT_META_LIST_ID   = '_woods_mystery_mailchimp_list_id';
	const ORDER_META_SYNCED_AT   = '_woods_mystery_mailchimp_synced_at';
	const ORDER_META_LISTS       = '_woods_mystery_mailchimp_lists';
	const ORDER_META_LAST_ERROR  = '_woods_mystery_mailchimp_last_error';
	const LEGACY_ORDER_META_SYNC = '_mc_synced';
	const OPTION_API_KEY         = 'woods_mystery_mailchimp_api_key';
	const LOGGER_SOURCE          = 'woods-mystery-mailchimp';

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'adjust_checkout_fields' ), 999 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 10, 2 );

		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'sync_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'sync_order' ) );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_woods_mystery_mailchimp_resync', array( __CLASS__, 'handle_order_resync' ) );

		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );

		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>Woods Mystery Mailchimp Sync requires WooCommerce to be active.</p></div>';
	}

	public static function register_settings() {
		register_setting(
			'woods_mystery_mailchimp',
			self::OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	public static function render_product_field() {
		global $post;

		if ( ! $post ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'          => 'woods_mystery_mailchimp_list_id',
				'label'       => 'Mailchimp Audience ID',
				'description' => 'Mystery Party audience/list ID. Variations inherit this from the parent product.',
				'desc_tip'    => true,
				'value'       => get_post_meta( $post->ID, self::PRODUCT_META_LIST_ID, true ),
			)
		);
	}

	public static function save_product_field( $product ) {
		if ( ! $product || ! isset( $_POST['woods_mystery_mailchimp_list_id'] ) ) {
			return;
		}

		$list_id = sanitize_text_field( wp_unslash( $_POST['woods_mystery_mailchimp_list_id'] ) );
		$product->update_meta_data( self::PRODUCT_META_LIST_ID, $list_id );
	}

	public static function adjust_checkout_fields( $fields ) {
		$definitions = self::other_attendee_field_definitions();
		$has_couple  = self::cart_has_couple_ticket();

		foreach ( $definitions as $key => $definition ) {
			if ( $has_couple ) {
				if ( ! isset( $fields['billing'][ $key ] ) ) {
					$fields['billing'][ $key ] = $definition;
				}

				$fields['billing'][ $key ]['required'] = true;
				$fields['billing'][ $key ]['class']    = array_values(
					array_unique(
						array_merge(
							(array) ( $fields['billing'][ $key ]['class'] ?? array() ),
							array( 'validate-required' )
						)
					)
				);
			} else {
				unset( $fields['billing'][ $key ] );
			}
		}

		return $fields;
	}

	public static function validate_checkout( $data, $errors ) {
		if ( ! self::cart_has_couple_ticket() ) {
			return;
		}

		$first = trim( (string) ( $data['other_attendee_first_name'] ?? '' ) );
		$last  = trim( (string) ( $data['other_attendee_last_name'] ?? '' ) );
		$email = trim( (string) ( $data['other_attendee_email'] ?? '' ) );

		if ( '' === $first ) {
			$errors->add( 'other_attendee_first_name_required', 'Please enter the other attendee first name.' );
		}

		if ( '' === $last ) {
			$errors->add( 'other_attendee_last_name_required', 'Please enter the other attendee last name.' );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors->add( 'other_attendee_email_required', 'Please enter a valid other attendee email address.' );
		}

		$billing_email = trim( (string) ( $data['billing_email'] ?? '' ) );
		if ( $email && $billing_email && strtolower( $email ) === strtolower( $billing_email ) ) {
			$errors->add( 'other_attendee_email_unique', 'Please use a different email address for the other attendee.' );
		}
	}

	public static function add_order_action( $actions ) {
		$actions['woods_mystery_mailchimp_resync'] = 'Resync Mystery Mailchimp';
		return $actions;
	}

	public static function handle_order_resync( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->delete_meta_data( self::ORDER_META_SYNCED_AT );
		$order->delete_meta_data( self::ORDER_META_LISTS );
		$order->delete_meta_data( self::ORDER_META_LAST_ERROR );
		$order->delete_meta_data( self::LEGACY_ORDER_META_SYNC );
		$order->save();

		self::sync_order( $order->get_id(), true );
	}

	public static function sync_order( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( ! $force && ( $order->get_meta( self::ORDER_META_SYNCED_AT, true ) || $order->get_meta( self::LEGACY_ORDER_META_SYNC, true ) ) ) {
			self::log( 'info', 'Order already synced', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$list_ids = self::get_order_list_ids( $order );

		if ( empty( $list_ids ) ) {
			self::log( 'info', 'No Mailchimp audience mapping found for order', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$api_key = self::get_api_key();

		if ( ! $api_key ) {
			self::mark_order_failed( $order, 'No Mailchimp API key is configured.' );
			return;
		}

		$attendees = self::get_order_attendees( $order );

		if ( is_wp_error( $attendees ) ) {
			self::mark_order_failed( $order, $attendees->get_error_message() );
			return;
		}

		$errors = array();

		foreach ( $list_ids as $list_id ) {
			foreach ( $attendees as $attendee ) {
				$result = self::sync_member( $api_key, $list_id, $attendee, $order );

				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						'%s on audience %s: %s',
						self::mask_email( $attendee['email'] ),
						$list_id,
						$result->get_error_message()
					);
				}
			}
		}

		if ( ! empty( $errors ) ) {
			self::mark_order_failed( $order, implode( '; ', $errors ) );
			return;
		}

		$order->update_meta_data( self::ORDER_META_SYNCED_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::ORDER_META_LISTS, implode( ',', $list_ids ) );
		$order->update_meta_data( self::LEGACY_ORDER_META_SYNC, 1 );
		$order->delete_meta_data( self::ORDER_META_LAST_ERROR );
		$order->add_order_note( sprintf( 'Mystery Mailchimp sync completed for audience(s): %s.', implode( ', ', $list_ids ) ) );
		$order->save();

		self::log(
			'info',
			'Order Mailchimp sync complete',
			array(
				'order_id' => $order->get_id(),
				'lists'    => $list_ids,
			)
		);
	}

	private static function sync_member( $api_key, $list_id, array $attendee, WC_Order $order ) {
		$email = strtolower( trim( (string) $attendee['email'] ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Invalid email address.' );
		}

		$dc = self::get_data_center( $api_key );

		if ( ! $dc ) {
			return new WP_Error( 'invalid_api_key', 'Mailchimp API key does not include a data center.' );
		}

		$payload = array(
			'email_address' => $email,
			'status_if_new' => 'subscribed',
			'merge_fields'  => array(
				'FNAME'   => $attendee['first_name'],
				'LNAME'   => $attendee['last_name'],
				'PHONE'   => $order->get_billing_phone(),
				'ADDRESS' => array(
					'addr1'   => $order->get_billing_address_1(),
					'addr2'   => $order->get_billing_address_2(),
					'city'    => $order->get_billing_city(),
					'state'   => $order->get_billing_state(),
					'zip'     => $order->get_billing_postcode(),
					'country' => $order->get_billing_country(),
				),
			),
		);

		$url      = sprintf( 'https://%s.api.mailchimp.com/3.0/lists/%s/members/%s', rawurlencode( $dc ), rawurlencode( $list_id ), md5( $email ) );
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		self::log(
			$status >= 200 && $status < 300 ? 'info' : 'error',
			'Mailchimp member sync response',
			array(
				'order_id'   => $order->get_id(),
				'list_id'    => $list_id,
				'email_hash' => md5( $email ),
				'role'       => $attendee['role'],
				'status'     => $status,
				'title'      => is_array( $body ) ? ( $body['title'] ?? '' ) : '',
				'detail'     => is_array( $body ) ? ( $body['detail'] ?? '' ) : '',
			)
		);

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) ? trim( ( $body['title'] ?? 'Mailchimp error' ) . ': ' . ( $body['detail'] ?? '' ) ) : 'Mailchimp error.';
			return new WP_Error( 'mailchimp_sync_failed', $message );
		}

		return true;
	}

	private static function get_order_attendees( WC_Order $order ) {
		$billing_email = trim( (string) $order->get_billing_email() );

		if ( ! is_email( $billing_email ) ) {
			return new WP_Error( 'billing_email_missing', 'Billing email is missing or invalid.' );
		}

		$attendees = array(
			array(
				'role'       => 'billing',
				'email'      => $billing_email,
				'first_name' => trim( (string) $order->get_billing_first_name() ),
				'last_name'  => trim( (string) $order->get_billing_last_name() ),
			),
		);

		if ( ! self::order_has_couple_ticket( $order ) ) {
			return $attendees;
		}

		$other_email = trim( (string) $order->get_meta( 'other_attendee_email', true ) );
		$other_first = trim( (string) $order->get_meta( 'other_attendee_first_name', true ) );
		$other_last  = trim( (string) $order->get_meta( 'other_attendee_last_name', true ) );

		if ( ! is_email( $other_email ) ) {
			return new WP_Error( 'other_attendee_email_missing', 'Couple ticket is missing a valid other attendee email address.' );
		}

		if ( strtolower( $other_email ) === strtolower( $billing_email ) ) {
			return new WP_Error( 'other_attendee_email_duplicate', 'Couple ticket other attendee email must be different from billing email.' );
		}

		if ( '' === $other_first || '' === $other_last ) {
			return new WP_Error( 'other_attendee_name_missing', 'Couple ticket is missing the other attendee name.' );
		}

		$attendees[] = array(
			'role'       => 'other_attendee',
			'email'      => $other_email,
			'first_name' => $other_first,
			'last_name'  => $other_last,
		);

		return $attendees;
	}

	private static function get_order_list_ids( WC_Order $order ) {
		$list_ids = array();

		foreach ( $order->get_items() as $item ) {
			$list_id = self::get_item_list_id( $item );

			if ( $list_id ) {
				$list_ids[ $list_id ] = true;
			}
		}

		return array_keys( $list_ids );
	}

	private static function get_item_list_id( WC_Order_Item_Product $item ) {
		$ids = array_filter(
			array(
				$item->get_variation_id(),
				$item->get_product_id(),
			)
		);

		foreach ( $ids as $id ) {
			$list_id = trim( (string) get_post_meta( $id, self::PRODUCT_META_LIST_ID, true ) );

			if ( $list_id ) {
				return $list_id;
			}
		}

		return '';
	}

	private static function cart_has_couple_ticket() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( self::cart_item_is_couple( $cart_item ) ) {
				return true;
			}
		}

		return false;
	}

	private static function order_has_couple_ticket( WC_Order $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( self::order_item_is_couple( $item ) ) {
				return true;
			}
		}

		return false;
	}

	private static function cart_item_is_couple( array $cart_item ) {
		if ( isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
			foreach ( $cart_item['variation'] as $value ) {
				if ( self::contains_couple( $value ) ) {
					return true;
				}
			}
		}

		if ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product && self::contains_couple( $cart_item['data']->get_name() ) ) {
			return true;
		}

		return false;
	}

	private static function order_item_is_couple( WC_Order_Item_Product $item ) {
		if ( self::contains_couple( $item->get_name() ) ) {
			return true;
		}

		foreach ( $item->get_meta_data() as $meta ) {
			if ( self::contains_couple( $meta->key ) || self::contains_couple( $meta->value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function contains_couple( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return false;
		}

		return false !== stripos( (string) $value, 'couple' );
	}

	private static function other_attendee_field_definitions() {
		return array(
			'other_attendee_first_name' => array(
				'type'        => 'text',
				'label'       => 'Other Attendee First Name',
				'required'    => true,
				'class'       => array( 'form-row-first', 'validate-required' ),
				'priority'    => 30,
				'autocomplete' => 'given-name',
			),
			'other_attendee_last_name'  => array(
				'type'        => 'text',
				'label'       => 'Other Attendee Last Name',
				'required'    => true,
				'class'       => array( 'form-row-last', 'validate-required' ),
				'priority'    => 40,
				'autocomplete' => 'family-name',
			),
			'other_attendee_email'      => array(
				'type'        => 'email',
				'label'       => 'Other Attendee Email address',
				'required'    => true,
				'class'       => array( 'form-row-wide', 'validate-required', 'validate-email' ),
				'validate'    => array( 'email' ),
				'priority'    => 50,
				'autocomplete' => 'email',
			),
		);
	}

	private static function get_api_key() {
		if ( defined( 'WOODS_MYSTERY_MAILCHIMP_API_KEY' ) && WOODS_MYSTERY_MAILCHIMP_API_KEY ) {
			return trim( (string) WOODS_MYSTERY_MAILCHIMP_API_KEY );
		}

		$plugin_key = trim( (string) get_option( self::OPTION_API_KEY, '' ) );

		if ( $plugin_key ) {
			return $plugin_key;
		}

		$mailchimp_for_woocommerce = get_option( 'mailchimp-woocommerce', array() );

		if ( is_array( $mailchimp_for_woocommerce ) && ! empty( $mailchimp_for_woocommerce['mailchimp_api_key'] ) ) {
			return trim( (string) $mailchimp_for_woocommerce['mailchimp_api_key'] );
		}

		return '';
	}

	private static function get_data_center( $api_key ) {
		$dash_position = strrpos( $api_key, '-' );

		if ( false === $dash_position ) {
			return '';
		}

		return substr( $api_key, $dash_position + 1 );
	}

	private static function mark_order_failed( WC_Order $order, $message ) {
		$order->update_meta_data( self::ORDER_META_LAST_ERROR, $message );
		$order->delete_meta_data( self::ORDER_META_SYNCED_AT );
		$order->delete_meta_data( self::LEGACY_ORDER_META_SYNC );
		$order->add_order_note( 'Mystery Mailchimp sync failed: ' . $message );
		$order->save();

		self::log(
			'error',
			'Order Mailchimp sync failed',
			array(
				'order_id' => $order->get_id(),
				'message'  => $message,
			)
		);
	}

	private static function log( $level, $message, array $context = array() ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array_merge( array( 'source' => self::LOGGER_SOURCE ), $context ) );
		}
	}

	private static function mask_email( $email ) {
		$email = (string) $email;
		$parts = explode( '@', $email );

		if ( 2 !== count( $parts ) ) {
			return 'invalid-email';
		}

		$local = $parts[0];

		return substr( $local, 0, 1 ) . '***@' . $parts[1];
	}

	public static function render_admin_page() {
		$api_key    = self::get_api_key();
		$list_data  = $api_key ? self::get_mailchimp_lists() : new WP_Error( 'missing_api_key', 'No Mailchimp API key is configured.' );
		$journeys   = $api_key ? self::get_mailchimp_journeys() : new WP_Error( 'missing_api_key', 'No Mailchimp API key is configured.' );
		$products   = self::get_configured_products();
		$list_lookup = is_wp_error( $list_data ) ? array() : self::map_lists_by_id( $list_data );
		$journey_map = is_wp_error( $journeys ) ? array() : self::map_journeys_by_list( $journeys );
		?>
		<div class="wrap">
			<h1>Mystery Mailchimp</h1>

			<form method="post" action="options.php" style="max-width: 760px;">
				<?php settings_fields( 'woods_mystery_mailchimp' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_API_KEY ); ?>">Mailchimp API key override</label></th>
						<td>
							<input type="password" class="regular-text" id="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_API_KEY, '' ) ); ?>" autocomplete="off">
							<p class="description">Leave blank to use the key already configured in Mailchimp for WooCommerce.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save API settings' ); ?>
			</form>

			<h2>Configured Party Products</h2>
			<p>Set the Mailchimp Audience ID on the WooCommerce product edit screen. Single and Couple variations inherit the parent product value.</p>

			<?php if ( is_wp_error( $list_data ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $list_data->get_error_message() ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_wp_error( $journeys ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $journeys->get_error_message() ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Product</th>
						<th>Status</th>
						<th>Audience ID</th>
						<th>Mailchimp Audience</th>
						<th>Customer Journey</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr><td colspan="5">No party products have a Mailchimp Audience ID yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $products as $product ) : ?>
							<?php
							$list_id = $product->get_meta( self::PRODUCT_META_LIST_ID, true );
							$list    = $list_lookup[ $list_id ] ?? null;
							$journey = $journey_map[ $list_id ] ?? null;
							?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></td>
								<td><?php echo esc_html( $product->get_status() ); ?></td>
								<td><code><?php echo esc_html( $list_id ); ?></code></td>
								<td>
									<?php
									if ( $list ) {
										echo esc_html( sprintf( '%s (%d members)', $list['name'], $list['member_count'] ) );
									} else {
										echo '<strong style="color:#b32d2e;">Not found</strong>';
									}
									?>
								</td>
								<td>
									<?php
									if ( $journey ) {
										echo esc_html( sprintf( '%s (%s)', $journey['journey_name'], $journey['status'] ) );
									} else {
										echo '<strong style="color:#b32d2e;">No journey found</strong>';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2>Admin QC Flow</h2>
			<ol>
				<li>Confirm the party product has the correct Mailchimp Audience ID above.</li>
				<li>Confirm the Customer Journey column shows a sending journey for that audience.</li>
				<li>Place one Single test order with a unique test email.</li>
				<li>Place one Couple test order with two different unique test emails.</li>
				<li>Open each order and confirm the Mystery Mailchimp order note says the sync completed.</li>
				<li>Use the N8N verifier to confirm every test email is in the expected audience.</li>
				<li>If needed, open the order actions dropdown and run Resync Mystery Mailchimp.</li>
			</ol>
		</div>
		<?php
	}

	private static function get_configured_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		return wc_get_products(
			array(
				'limit'      => -1,
				'status'     => array( 'publish', 'draft', 'private' ),
				'type'       => array( 'simple', 'variable' ),
				'meta_key'   => self::PRODUCT_META_LIST_ID,
				'meta_value' => '',
				'meta_compare' => '!=',
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);
	}

	private static function get_mailchimp_lists() {
		$response = self::mailchimp_get( '/lists?count=100&fields=lists.id,lists.name,lists.stats.member_count' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$lists = array();

		foreach ( $response['lists'] ?? array() as $list ) {
			$lists[] = array(
				'id'           => $list['id'],
				'name'         => $list['name'],
				'member_count' => (int) ( $list['stats']['member_count'] ?? 0 ),
			);
		}

		return $lists;
	}

	private static function get_mailchimp_journeys() {
		$response = self::mailchimp_get( '/customer-journeys/journeys?count=100' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['journeys'] ?? array();
	}

	private static function map_journeys_by_list( array $journeys ) {
		$map = array();

		foreach ( $journeys as $journey ) {
			$list_id = $journey['list_id'] ?? '';

			if ( ! $list_id ) {
				continue;
			}

			if ( ! isset( $map[ $list_id ] ) || 'sending' === ( $journey['status'] ?? '' ) ) {
				$map[ $list_id ] = array(
					'journey_name' => $journey['journey_name'] ?? 'Unnamed journey',
					'status'       => $journey['status'] ?? 'unknown',
				);
			}
		}

		return $map;
	}

	private static function map_lists_by_id( array $lists ) {
		$map = array();

		foreach ( $lists as $list ) {
			if ( empty( $list['id'] ) ) {
				continue;
			}

			$map[ $list['id'] ] = $list;
		}

		return $map;
	}

	private static function mailchimp_get( $path ) {
		$api_key = self::get_api_key();
		$dc      = self::get_data_center( $api_key );

		if ( ! $api_key || ! $dc ) {
			return new WP_Error( 'missing_api_key', 'Mailchimp API key is missing or invalid.' );
		}

		$response = wp_remote_get(
			'https://' . rawurlencode( $dc ) . '.api.mailchimp.com/3.0' . $path,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) ? trim( ( $body['title'] ?? 'Mailchimp error' ) . ': ' . ( $body['detail'] ?? '' ) ) : 'Mailchimp error.';
			return new WP_Error( 'mailchimp_get_failed', $message );
		}

		return is_array( $body ) ? $body : array();
	}
}

<?php
/**
 * Mystery Party Mailchimp feature.
 *
 * Syncs ticket buyers to the correct party-specific Mailchimp audience and
 * validates couple attendee details.
 */

defined( 'ABSPATH' ) || exit;

final class WMP_Mystery_Mailchimp implements WMP_Site_Feature {
	const PAGE_SLUG                        = 'woodsmystery-mystery-mailchimp';
	const PRODUCT_META_LIST_ID             = '_woods_mystery_mailchimp_list_id';
	const PRODUCT_META_JOURNEY_TRIGGER_URL = '_woods_mystery_mailchimp_journey_trigger_url';
	const ORDER_META_SYNCED_AT             = '_woods_mystery_mailchimp_synced_at';
	const ORDER_META_LISTS                 = '_woods_mystery_mailchimp_lists';
	const ORDER_META_LAST_ERROR            = '_woods_mystery_mailchimp_last_error';
	const ORDER_META_JOURNEY_TRIGGERED     = '_woods_mystery_mailchimp_journey_triggered';
	const LEGACY_ORDER_META_SYNC           = '_mc_synced';
	const OPTION_API_KEY                   = 'woods_mystery_mailchimp_api_key';
	const LOGGER_SOURCE                    = 'woods-mystery-mailchimp';
	const LIST_CACHE_TRANSIENT             = 'woods_mystery_mailchimp_lists_cache';

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

		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_data_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );

		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_woods_mystery_mailchimp_backfill_journey', array( __CLASS__, 'handle_journey_backfill' ) );
		add_action( 'update_option_' . self::OPTION_API_KEY, array( __CLASS__, 'clear_list_cache' ) );
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

	public static function add_product_data_tab( $tabs ) {
		$tabs['woods_mystery_mailchimp'] = array(
			'label'    => __( 'Mystery Mailchimp', 'woodsmystery-plugin' ),
			'target'   => 'woods_mystery_mailchimp_product_data',
			'class'    => array(),
			'priority' => 65,
		);

		return $tabs;
	}

	public static function render_product_data_panel() {
		echo '<div id="woods_mystery_mailchimp_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';
		self::render_product_field();
		echo '</div>';
		echo '</div>';
	}

	public static function render_product_field() {
		global $post;

		if ( ! $post ) {
			return;
		}

		$saved_list_id     = trim( (string) get_post_meta( $post->ID, self::PRODUCT_META_LIST_ID, true ) );
		$saved_trigger_url = trim( (string) get_post_meta( $post->ID, self::PRODUCT_META_JOURNEY_TRIGGER_URL, true ) );
		$list_data         = self::get_mailchimp_lists( true );

		if ( is_wp_error( $list_data ) ) {
			woocommerce_wp_text_input(
				array(
					'id'          => 'woods_mystery_mailchimp_list_id',
					'label'       => 'Mailchimp Audience ID',
					'description' => 'Could not load Mailchimp audiences. Enter the audience/list ID manually. Variations inherit this from the parent product.',
					'desc_tip'    => true,
					'value'       => $saved_list_id,
				)
			);

			self::render_journey_trigger_field( $saved_trigger_url );

			echo '<p class="form-field"><span class="description" style="color:#b32d2e;">';
			echo esc_html( 'Mailchimp audiences could not be loaded: ' . $list_data->get_error_message() );
			echo '</span></p>';
			return;
		}

		$options     = array( '' => 'Select a Mailchimp audience' );
		$list_lookup = self::map_lists_by_id( $list_data );

		foreach ( $list_data as $list ) {
			$options[ $list['id'] ] = sprintf( '%s (%s)', $list['name'], $list['id'] );
		}

		if ( $saved_list_id && ! isset( $list_lookup[ $saved_list_id ] ) ) {
			$options[ $saved_list_id ] = sprintf( 'Saved audience ID not found in Mailchimp (%s)', $saved_list_id );
		}

		woocommerce_wp_select(
			array(
				'id'          => 'woods_mystery_mailchimp_list_id',
				'label'       => 'Mailchimp Audience',
				'description' => 'Mystery Party audience/list. Variations inherit this from the parent product.',
				'desc_tip'    => true,
				'options'     => $options,
				'value'       => $saved_list_id,
			)
		);

		self::render_journey_trigger_field( $saved_trigger_url );
	}

	private static function render_journey_trigger_field( $saved_trigger_url ) {
		woocommerce_wp_text_input(
			array(
				'id'          => 'woods_mystery_mailchimp_journey_trigger_url',
				'label'       => 'Customer Journey Trigger URL',
				'description' => 'Mailchimp Customer Journey API trigger URL for this party. Variations inherit this from the parent product.',
				'desc_tip'    => true,
				'type'        => 'url',
				'value'       => $saved_trigger_url,
			)
		);
	}

	public static function save_product_field( $product ) {
		if ( ! $product ) {
			return;
		}

		if ( isset( $_POST['woods_mystery_mailchimp_list_id'] ) ) {
			$list_id = sanitize_text_field( wp_unslash( $_POST['woods_mystery_mailchimp_list_id'] ) );
			$product->update_meta_data( self::PRODUCT_META_LIST_ID, $list_id );
		}

		if ( isset( $_POST['woods_mystery_mailchimp_journey_trigger_url'] ) ) {
			$trigger_url = self::normalize_journey_trigger_url( wp_unslash( $_POST['woods_mystery_mailchimp_journey_trigger_url'] ) );
			$product->update_meta_data( self::PRODUCT_META_JOURNEY_TRIGGER_URL, $trigger_url );
		}
	}

	public static function clear_list_cache() {
		delete_transient( self::LIST_CACHE_TRANSIENT );
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

	public static function handle_journey_backfill() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to trigger Mystery Mailchimp journeys.', 'woodsmystery-plugin' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		check_admin_referer( 'woods_mystery_mailchimp_backfill_journey_' . $product_id );

		$result = self::backfill_journey_for_product( $product_id );

		self::redirect_with_backfill_result( $result );
	}

	private static function backfill_journey_for_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return self::build_backfill_result( 'error', 'WooCommerce products are not available.' );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return self::build_backfill_result( 'error', 'The selected product could not be found.' );
		}

		$list_id     = trim( (string) $product->get_meta( self::PRODUCT_META_LIST_ID, true ) );
		$trigger_url = self::normalize_journey_trigger_url( $product->get_meta( self::PRODUCT_META_JOURNEY_TRIGGER_URL, true ) );

		if ( '' === $list_id ) {
			return self::build_backfill_result( 'error', sprintf( '%s does not have a Mailchimp audience configured.', $product->get_name() ) );
		}

		if ( '' === $trigger_url ) {
			return self::build_backfill_result( 'error', sprintf( '%s does not have a valid Customer Journey trigger URL configured.', $product->get_name() ) );
		}

		$api_key = self::get_api_key();

		if ( ! $api_key ) {
			return self::build_backfill_result( 'error', 'No Mailchimp API key is configured.' );
		}

		$emails = self::get_subscribed_member_emails( $list_id );

		if ( is_wp_error( $emails ) ) {
			return self::build_backfill_result( 'error', $emails->get_error_message() );
		}

		if ( empty( $emails ) ) {
			return self::build_backfill_result( 'warning', sprintf( 'No subscribed members were found in the Mailchimp audience for %s.', $product->get_name() ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$triggered = 0;
		$errors    = array();

		foreach ( $emails as $email ) {
			$result = self::trigger_customer_journey_for_email(
				$api_key,
				$trigger_url,
				$email,
				array(
					'product_id' => $product->get_id(),
					'list_id'    => $list_id,
					'source'     => 'manual_backfill',
				)
			);

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( '%s: %s', self::mask_email( $email ), $result->get_error_message() );
				continue;
			}

			$triggered++;
		}

		if ( ! empty( $errors ) ) {
			return self::build_backfill_result(
				$triggered > 0 ? 'warning' : 'error',
				sprintf(
					'Triggered the welcome Journey for %1$d of %2$d subscribed member(s) in %3$s. %4$d failed.',
					$triggered,
					count( $emails ),
					$product->get_name(),
					count( $errors )
				),
				$errors
			);
		}

		return self::build_backfill_result(
			'success',
			sprintf(
				'Triggered the welcome Journey for all %1$d subscribed member(s) in %2$s.',
				$triggered,
				$product->get_name()
			)
		);
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

		$trigger_urls = self::get_order_trigger_urls( $order );
		$api_key      = self::get_api_key();

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
						'Audience sync failed for %s on audience %s: %s',
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

		foreach ( $trigger_urls as $trigger_url ) {
			foreach ( $attendees as $attendee ) {
				$result = self::maybe_trigger_customer_journey( $api_key, $trigger_url, $attendee, $order );

				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						'Welcome email trigger failed for %s using %s: %s',
						self::mask_email( $attendee['email'] ),
						$trigger_url,
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

		if ( empty( $trigger_urls ) ) {
			$order->add_order_note( sprintf( 'Mystery Mailchimp sync completed for audience(s): %s. No Customer Journey trigger URL is configured for the purchased product(s).', implode( ', ', $list_ids ) ) );
		} else {
			$order->add_order_note( sprintf( 'Mystery Mailchimp sync and welcome email trigger completed for audience(s): %s.', implode( ', ', $list_ids ) ) );
		}

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

	private static function maybe_trigger_customer_journey( $api_key, $trigger_url, array $attendee, WC_Order $order ) {
		$email = strtolower( trim( (string) $attendee['email'] ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Invalid email address.' );
		}

		$trigger_url = self::normalize_journey_trigger_url( $trigger_url );

		if ( ! $trigger_url ) {
			return new WP_Error( 'missing_journey_trigger_url', 'Customer Journey trigger URL is missing or invalid.' );
		}

		if ( self::journey_already_triggered( $order, $trigger_url, $email ) ) {
			self::log(
				'info',
				'Mailchimp welcome journey already triggered for order attendee',
				array(
					'order_id'   => $order->get_id(),
					'email_hash' => md5( $email ),
					'role'       => $attendee['role'],
					'url_hash'   => md5( $trigger_url ),
				)
			);

			return true;
		}

		$result = self::trigger_customer_journey( $api_key, $trigger_url, $attendee, $order );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::mark_journey_triggered( $order, $trigger_url, $email );

		return true;
	}

	private static function trigger_customer_journey( $api_key, $trigger_url, array $attendee, WC_Order $order ) {
		$email = strtolower( trim( (string) $attendee['email'] ) );

		return self::trigger_customer_journey_for_email(
			$api_key,
			$trigger_url,
			$email,
			array(
				'order_id' => $order->get_id(),
				'role'     => $attendee['role'],
			)
		);
	}

	private static function trigger_customer_journey_for_email( $api_key, $trigger_url, $email, array $context = array() ) {
		$email = strtolower( trim( (string) $email ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Invalid email address.' );
		}

		$trigger_url = self::normalize_journey_trigger_url( $trigger_url );

		if ( ! $trigger_url ) {
			return new WP_Error( 'missing_journey_trigger_url', 'Customer Journey trigger URL is missing or invalid.' );
		}

		$response = wp_remote_post(
			$trigger_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email_address' => $email,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		self::log(
			$status >= 200 && $status < 300 ? 'info' : 'error',
			'Mailchimp welcome journey trigger response',
			array_merge(
				$context,
				array(
					'email_hash' => md5( $email ),
					'status'     => $status,
					'title'      => is_array( $body ) ? ( $body['title'] ?? '' ) : '',
					'detail'     => is_array( $body ) ? ( $body['detail'] ?? '' ) : '',
					'url_hash'   => md5( $trigger_url ),
				)
			)
		);

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) ? trim( ( $body['title'] ?? 'Mailchimp journey error' ) . ': ' . ( $body['detail'] ?? '' ) ) : 'Mailchimp journey error.';
			return new WP_Error( 'mailchimp_journey_trigger_failed', $message );
		}

		return true;
	}

	private static function journey_already_triggered( WC_Order $order, $trigger_url, $email ) {
		$email = strtolower( trim( (string) $email ) );
		$keys  = self::get_journey_triggered_keys( $order );

		return in_array( self::get_journey_trigger_key( $trigger_url, $email ), $keys, true ) || in_array( $email, $keys, true );
	}

	private static function mark_journey_triggered( WC_Order $order, $trigger_url, $email ) {
		$keys = self::get_journey_triggered_keys( $order );
		$key  = self::get_journey_trigger_key( $trigger_url, $email );

		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
		}

		$order->update_meta_data( self::ORDER_META_JOURNEY_TRIGGERED, $keys );
	}

	private static function get_journey_triggered_keys( WC_Order $order ) {
		$value = $order->get_meta( self::ORDER_META_JOURNEY_TRIGGERED, true );

		if ( is_string( $value ) ) {
			$value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $key ) {
							return sanitize_text_field( trim( (string) $key ) );
						},
						$value
					)
				)
			)
		);
	}

	private static function get_journey_trigger_key( $trigger_url, $email ) {
		return md5( self::normalize_journey_trigger_url( $trigger_url ) ) . ':' . strtolower( trim( (string) $email ) );
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

	private static function get_order_trigger_urls( WC_Order $order ) {
		$trigger_urls = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$trigger_url = self::get_item_trigger_url( $item );

			if ( $trigger_url ) {
				$trigger_urls[ $trigger_url ] = true;
			}
		}

		return array_keys( $trigger_urls );
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

	private static function get_item_trigger_url( WC_Order_Item_Product $item ) {
		$ids = array_filter(
			array(
				$item->get_variation_id(),
				$item->get_product_id(),
			)
		);

		foreach ( $ids as $id ) {
			$trigger_url = self::normalize_journey_trigger_url( get_post_meta( $id, self::PRODUCT_META_JOURNEY_TRIGGER_URL, true ) );

			if ( $trigger_url ) {
				return $trigger_url;
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

	private static function normalize_journey_trigger_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}

		if ( ! preg_match( '/(^|\.)api\.mailchimp\.com$/', strtolower( $parts['host'] ) ) ) {
			return '';
		}

		if ( false === strpos( $parts['path'], '/3.0/customer-journeys/journeys/' ) || false === strpos( $parts['path'], '/actions/trigger' ) ) {
			return '';
		}

		return $url;
	}

	private static function mark_order_failed( WC_Order $order, $message ) {
		$previous_message = (string) $order->get_meta( self::ORDER_META_LAST_ERROR, true );

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

		if ( $message !== $previous_message ) {
			self::notify_sync_failure( $order, $message );
		}
	}

	private static function notify_sync_failure( WC_Order $order, $message ) {
		if ( ! class_exists( 'WMP_Site_Admin_Settings' ) ) {
			return;
		}

		$recipients = WMP_Site_Admin_Settings::get_error_notification_emails();

		if ( empty( $recipients ) ) {
			return;
		}

		$subject = sprintf(
			'[%s] Mystery Mailchimp sync failed for order #%s',
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$order->get_order_number()
		);

		$sent = wp_mail(
			$recipients,
			$subject,
			self::build_failure_email_body( $order, $message ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		self::log(
			$sent ? 'info' : 'error',
			'Mystery Mailchimp failure notification email result',
			array(
				'order_id'        => $order->get_id(),
				'recipient_count' => count( $recipients ),
				'sent'            => $sent ? 1 : 0,
			)
		);
	}

	private static function build_failure_email_body( WC_Order $order, $message ) {
		$lines = array(
			sprintf( 'Mystery Mailchimp sync failed for order #%s.', $order->get_order_number() ),
			'',
			'Error:',
			$message,
			'',
			'Order:',
			admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			'',
			'Billing attendee:',
			sprintf(
				'%s %s <%s>',
				$order->get_billing_first_name(),
				$order->get_billing_last_name(),
				$order->get_billing_email()
			),
		);

		$other_attendee = self::get_other_attendee_summary( $order );

		if ( $other_attendee ) {
			$lines[] = '';
			$lines[] = 'Other attendee:';
			$lines[] = $other_attendee;
		}

		$products = self::get_order_product_names( $order );

		if ( ! empty( $products ) ) {
			$lines[] = '';
			$lines[] = 'Products:';

			foreach ( $products as $product ) {
				$lines[] = '- ' . $product;
			}
		}

		$lines[] = '';
		$lines[] = 'Retry instructions:';
		$lines[] = 'Open the WooCommerce order, choose "Resync Mystery Mailchimp" from Order actions, then click Update.';

		return implode( "\n", $lines );
	}

	private static function get_other_attendee_summary( WC_Order $order ) {
		$email = trim( (string) $order->get_meta( 'other_attendee_email', true ) );
		$first = trim( (string) $order->get_meta( 'other_attendee_first_name', true ) );
		$last  = trim( (string) $order->get_meta( 'other_attendee_last_name', true ) );

		if ( '' === $email && '' === $first && '' === $last ) {
			return '';
		}

		return sprintf( '%s %s <%s>', $first, $last, $email );
	}

	private static function get_order_product_names( WC_Order $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$products[] = $item->get_name();
			}
		}

		return $products;
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

	private static function build_backfill_result( $status, $message, array $errors = array() ) {
		return array(
			'status'  => $status,
			'message' => $message,
			'errors'  => $errors,
		);
	}

	private static function redirect_with_backfill_result( array $result ) {
		set_transient(
			self::get_backfill_result_transient_key(),
			$result,
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => 'status',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_backfill_result_notice() {
		$key    = self::get_backfill_result_transient_key();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( $key );

		$status  = in_array( $result['status'] ?? '', array( 'success', 'warning', 'error' ), true ) ? $result['status'] : 'info';
		$message = (string) ( $result['message'] ?? '' );
		$errors  = is_array( $result['errors'] ?? null ) ? $result['errors'] : array();

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $status ), esc_html( $message ) );

		if ( ! empty( $errors ) ) {
			echo '<ul>';

			foreach ( array_slice( $errors, 0, 10 ) as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}

			if ( count( $errors ) > 10 ) {
				printf( '<li>%s</li>', esc_html( sprintf( '%d additional error(s) omitted.', count( $errors ) - 10 ) ) );
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	private static function get_backfill_result_transient_key() {
		return 'woods_mystery_mailchimp_backfill_result_' . get_current_user_id();
	}

	public static function render_admin_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';

		if ( ! in_array( $active_tab, array( 'status', 'sop' ), true ) ) {
			$active_tab = 'status';
		}

		$status_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => 'status',
			),
			admin_url( 'admin.php' )
		);
		$sop_url    = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => 'sop',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap woodsmystery-plugin-admin">
			<h1>Mystery Mailchimp</h1>

			<?php self::render_backfill_result_notice(); ?>

			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'status' === $active_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $status_url ); ?>">Audience Status</a>
				<a class="nav-tab <?php echo 'sop' === $active_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $sop_url ); ?>">QC SOP</a>
			</h2>

			<?php if ( 'sop' === $active_tab ) : ?>
				<?php self::render_sop_tab(); ?>
			<?php else : ?>
				<?php
				$api_key     = self::get_api_key();
				$list_data   = $api_key ? self::get_mailchimp_lists() : new WP_Error( 'missing_api_key', 'No Mailchimp API key is configured.' );
				$journeys    = $api_key ? self::get_mailchimp_journeys() : new WP_Error( 'missing_api_key', 'No Mailchimp API key is configured.' );
				$products    = self::get_configured_products();
				$list_lookup = is_wp_error( $list_data ) ? array() : self::map_lists_by_id( $list_data );
				$journey_map = is_wp_error( $journeys ) ? array() : self::map_journeys_by_list( $journeys );
				?>

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
			<p>Set the Mailchimp Audience ID and Customer Journey Trigger URL on the WooCommerce product edit screen. Single and Couple variations inherit the parent product values.</p>

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
						<th>Trigger URL</th>
						<th>Manual Trigger</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr><td colspan="7">No party products have a Mailchimp Audience ID yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $products as $product ) : ?>
							<?php
							$list_id     = $product->get_meta( self::PRODUCT_META_LIST_ID, true );
							$trigger_url = self::normalize_journey_trigger_url( $product->get_meta( self::PRODUCT_META_JOURNEY_TRIGGER_URL, true ) );
							$list        = $list_lookup[ $list_id ] ?? null;
							$journey     = $journey_map[ $list_id ] ?? null;
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
								<td>
									<?php if ( $trigger_url ) : ?>
										<code><?php echo esc_html( $trigger_url ); ?></code>
									<?php else : ?>
										<strong style="color:#b32d2e;">Not configured</strong>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $list_id && $trigger_url ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="woods_mystery_mailchimp_backfill_journey">
											<input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>">
											<?php wp_nonce_field( 'woods_mystery_mailchimp_backfill_journey_' . $product->get_id() ); ?>
											<?php
											submit_button(
												'Trigger audience',
												'secondary small',
												'submit',
												false,
												array(
													'onclick' => "return confirm('This will trigger the configured Mailchimp Customer Journey for every subscribed member in this audience. Continue?');",
												)
											);
											?>
										</form>
									<?php else : ?>
										<span class="description">Configure an audience and trigger URL first.</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2>Admin QC Flow</h2>
			<ol>
				<li>Confirm the party product has the correct Mailchimp Audience above.</li>
				<li>Confirm the Customer Journey column shows a sending journey for that audience.</li>
				<li>Place one Single test order with a unique test email.</li>
				<li>Place one Couple test order with two different unique test emails.</li>
				<li>Open each order and confirm the Mystery Mailchimp order note says the sync completed.</li>
				<li>Use the N8N verifier to confirm every test email is in the expected audience.</li>
				<li>If needed, open the order actions dropdown and run Resync Mystery Mailchimp.</li>
				<li>If existing audience members missed the welcome email, use Trigger audience for the configured product above.</li>
			</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_sop_tab() {
		$sop_path = WMP_SITE_PLUGIN_DIR . 'mystery-party-mailchimp-qc.md';

		if ( ! is_readable( $sop_path ) ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'The Mystery Party Mailchimp QC SOP file could not be found.', 'woodsmystery-plugin' );
			echo '</p></div>';
			return;
		}

		$markdown = file_get_contents( $sop_path );

		if ( false === $markdown || '' === trim( $markdown ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'The Mystery Party Mailchimp QC SOP file is empty.', 'woodsmystery-plugin' );
			echo '</p></div>';
			return;
		}

		echo '<div class="woodsmystery-card woodsmystery-sop">';
		self::render_sop_markdown( $markdown );
		echo '</div>';
	}

	private static function render_sop_markdown( $markdown ) {
		$lines     = preg_split( '/\R/', (string) $markdown );
		$list_type = '';

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				self::close_sop_list( $list_type );
				continue;
			}

			if ( preg_match( '/^(#{1,3})\s+(.+)$/', $line, $matches ) ) {
				self::close_sop_list( $list_type );
				$level = min( 4, strlen( $matches[1] ) + 1 );
				printf( '<h%d>%s</h%d>', $level, esc_html( $matches[2] ), $level );
				continue;
			}

			if ( preg_match( '/^\d+\.\s+(.+)$/', $line, $matches ) ) {
				if ( 'ol' !== $list_type ) {
					self::close_sop_list( $list_type );
					echo '<ol>';
					$list_type = 'ol';
				}

				echo '<li>' . esc_html( $matches[1] ) . '</li>';
				continue;
			}

			if ( preg_match( '/^-\s+(.+)$/', $line, $matches ) ) {
				if ( 'ul' !== $list_type ) {
					self::close_sop_list( $list_type );
					echo '<ul>';
					$list_type = 'ul';
				}

				echo '<li>' . esc_html( $matches[1] ) . '</li>';
				continue;
			}

			self::close_sop_list( $list_type );
			echo '<p>' . esc_html( $line ) . '</p>';
		}

		self::close_sop_list( $list_type );
	}

	private static function close_sop_list( &$list_type ) {
		if ( 'ol' === $list_type ) {
			echo '</ol>';
		} elseif ( 'ul' === $list_type ) {
			echo '</ul>';
		}

		$list_type = '';
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

	private static function get_subscribed_member_emails( $list_id ) {
		$list_id = trim( (string) $list_id );

		if ( '' === $list_id ) {
			return new WP_Error( 'missing_list_id', 'Mailchimp audience ID is missing.' );
		}

		$emails = array();
		$count  = 1000;
		$offset = 0;

		do {
			$response = self::mailchimp_get(
				sprintf(
					'/lists/%s/members?status=subscribed&count=%d&offset=%d&fields=members.email_address,total_items',
					rawurlencode( $list_id ),
					$count,
					$offset
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( $response['members'] ?? array() as $member ) {
				$email = strtolower( trim( (string) ( $member['email_address'] ?? '' ) ) );

				if ( is_email( $email ) ) {
					$emails[ $email ] = true;
				}
			}

			$total_items = (int) ( $response['total_items'] ?? count( $emails ) );
			$offset     += $count;
		} while ( $offset < $total_items );

		return array_keys( $emails );
	}

	private static function get_mailchimp_lists( $use_cache = false ) {
		if ( $use_cache ) {
			$cached = get_transient( self::LIST_CACHE_TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

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

		if ( $use_cache ) {
			set_transient( self::LIST_CACHE_TRANSIENT, $lists, 5 * MINUTE_IN_SECONDS );
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

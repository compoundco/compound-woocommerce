<?php
/**
 * The Compound payment gateway: on checkout it creates a Compound order (order-first,
 * SKU + quantity only) and a charge, then completes the WooCommerce order on capture.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Compound extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'compound';
		$this->method_title       = __( 'Compound', 'compound-woocommerce' );
		$this->method_description = __( 'Route checkout and orders through Compound (payments orchestration + pharmacy fulfillment).', 'compound-woocommerce' );
		// has_fields = true so the shopper picks a payment method (card / crypto) at checkout;
		// that choice drives which processors Compound routes across.
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * The payment methods (rails) the shopper can choose. What they pick is sent to Compound as
	 * method_type, which selects the eligible processors the routing engine chooses among.
	 */
	private function methods(): array {
		return array(
			'card'   => __( 'Card', 'compound-woocommerce' ),
			'crypto' => __( 'Cryptocurrency', 'compound-woocommerce' ),
		);
	}

	/**
	 * Checkout UI: a short description + a rail chooser. The selection posts as `compound_method`.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		$methods = $this->methods();
		echo '<fieldset id="compound-method" style="border:0;padding:0;margin:0;">';
		$first = true;
		foreach ( $methods as $value => $label ) {
			printf(
				'<label style="display:block;margin:4px 0;"><input type="radio" name="compound_method" value="%s" %s /> %s</label>',
				esc_attr( $value ),
				checked( $first, true, false ),
				esc_html( $label )
			);
			$first = false;
		}
		echo '</fieldset>';
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'compound-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Compound', 'compound-woocommerce' ),
				'default' => 'no',
			),
			'title'        => array(
				'title'       => __( 'Title', 'compound-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'compound-woocommerce' ),
				'default'     => __( 'Card', 'compound-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'  => array(
				'title'   => __( 'Description', 'compound-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely. Your card is processed by Compound.', 'compound-woocommerce' ),
			),
			'environment'  => array(
				'title'   => __( 'Environment', 'compound-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'sandbox' => __( 'Sandbox (test, no real money)', 'compound-woocommerce' ),
					'live'    => __( 'Live', 'compound-woocommerce' ),
				),
				'default' => 'sandbox',
			),
			'api_key'      => array(
				'title'       => __( 'Secret API key', 'compound-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'A Compound secret key (sk_...) with orders:write and charges:write. Create it in the Compound admin portal (Developers).', 'compound-woocommerce' ),
			),
			'orders_url'   => array(
				'title'   => __( 'Orders API base URL', 'compound-woocommerce' ),
				'type'    => 'text',
				'default' => 'https://api.compound.dev',
			),
			'payments_url' => array(
				'title'   => __( 'Payments API base URL', 'compound-woocommerce' ),
				'type'    => 'text',
				'default' => 'https://api.compound.dev',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'compound-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Verifies inbound Compound webhooks (order.shipped/delivered).', 'compound-woocommerce' ),
			),
		);
	}

	private function api(): WC_Compound_API {
		return new WC_Compound_API(
			$this->get_option( 'orders_url' ),
			$this->get_option( 'payments_url' ),
			$this->get_option( 'api_key' )
		);
	}

	/**
	 * Order-first: create the Compound order, then the charge. Complete the WC order
	 * on capture; fail cleanly (with the real reason) otherwise.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$api   = $this->api();

		// The rail the shopper chose (WooCommerce has already verified the checkout nonce).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$method = isset( $_POST['compound_method'] ) ? sanitize_text_field( wp_unslash( $_POST['compound_method'] ) ) : 'card';
		if ( ! array_key_exists( $method, $this->methods() ) ) {
			$method = 'card';
		}
		$order->update_meta_data( '_compound_method', $method );

		// Build line items as {sku, quantity} ONLY. A missing SKU can't be fulfilled.
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';
			if ( '' === $sku ) {
				wc_add_notice( __( 'A product in your cart is not set up for Compound fulfillment. Please contact support.', 'compound-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$line_items[] = array(
				'sku'      => $sku,
				'quantity' => (int) $item->get_quantity(),
			);
		}

		$amount_cents = (int) round( (float) $order->get_total() * 100 );
		$reference    = $order->get_order_key();

		// Attribution recorded on every order: channel + where it came from + any discount.
		$coupons = $order->get_coupon_codes();
		$meta    = array(
			'channel'        => 'woocommerce',
			'attribution'    => $this->attribution( $order ),
			'coupon_code'    => ! empty( $coupons ) ? (string) $coupons[0] : '',
			'discount_cents' => (int) round( (float) $order->get_total_discount() * 100 ),
		);

		// 1) Order-first intake (idempotent on the WC order key).
		$created = $api->create_order(
			$line_items,
			$amount_cents,
			array( 'email' => $order->get_billing_email() ),
			$this->shipping_address( $order ),
			$reference,
			'wc-order-' . $reference,
			$meta
		);
		if ( is_wp_error( $created ) ) {
			wc_add_notice( $created->get_error_message(), 'error' );
			$order->add_order_note( 'Compound order intake failed: ' . $created->get_error_message() );
			return array( 'result' => 'failure' );
		}
		$compound_order_id = (string) ( $created['id'] ?? '' );
		$order->update_meta_data( '_compound_order_id', $compound_order_id );

		// 2) Charge against that order, on the chosen rail. Compound routes across the processors
		//    that support this method (card -> card processors; crypto -> the crypto gateway).
		$charge = $api->create_charge( $compound_order_id, $amount_cents, 'wc-charge-' . $reference, $method );
		if ( is_wp_error( $charge ) ) {
			wc_add_notice( $charge->get_error_message(), 'error' );
			$order->add_order_note( 'Compound charge failed: ' . $charge->get_error_message() );
			$order->save();
			return array( 'result' => 'failure' );
		}

		$status    = (string) ( $charge['status'] ?? '' );
		$charge_id = (string) ( $charge['id'] ?? '' );
		$order->update_meta_data( '_compound_charge_id', $charge_id );

		if ( 'captured' === $status ) {
			$order->payment_complete( $charge_id );
			$order->add_order_note( sprintf( 'Compound order %s - charge %s captured via %s.', $compound_order_id, $charge_id, (string) ( $charge['processor_used'] ?? 'processor' ) ) );
			$order->save();
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// declined / processing (non-captured) -> do not complete the order.
		$order->update_status( 'failed', sprintf( 'Compound charge not captured (status: %s).', $status ) );
		$order->save();
		wc_add_notice( __( 'Your payment could not be completed. Please try another method.', 'compound-woocommerce' ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Order attribution as WooCommerce records it (Order Attribution feature): where
	 * the order came from. Only non-empty values are sent.
	 */
	private function attribution( WC_Order $order ): array {
		$fields = array(
			'source_type', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
			'utm_term', 'referrer', 'device_type', 'session_entry',
		);
		$out = array();
		foreach ( $fields as $f ) {
			$v = $order->get_meta( '_wc_order_attribution_' . $f );
			if ( '' !== $v && null !== $v ) {
				$out[ $f ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Minimum-necessary shipping address for fulfillment (PHI destination).
	 */
	private function shipping_address( WC_Order $order ): array {
		return array(
			'name'   => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'line1'  => $order->get_shipping_address_1(),
			'line2'  => $order->get_shipping_address_2(),
			'city'   => $order->get_shipping_city(),
			'state'  => $order->get_shipping_state(),
			'zip'    => $order->get_shipping_postcode(),
			'country'=> $order->get_shipping_country(),
		);
	}
}

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
		// has_fields = true so the shopper picks a payment method (card / bank transfer / crypto)
		// at checkout; that choice drives which processors Compound routes across.
		$this->has_fields = true;
		$this->supports   = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * The payment methods (rails) the shopper can choose, keyed by the method_type sent to
	 * Compound. Single source of truth so the classic checkout (payment_fields) and the block
	 * checkout (WC_Compound_Blocks) offer exactly the same rails - they must never drift.
	 */
	public static function method_labels(): array {
		return array(
			'card'   => __( 'Card', 'compound-woocommerce' ),
			'ach'    => __( 'Bank transfer (ACH)', 'compound-woocommerce' ),
			'crypto' => __( 'Cryptocurrency', 'compound-woocommerce' ),
		);
	}

	public static function sandbox_test_values(): array {
		return array(
			'card'   => array(
				'4242424242424242' => __( 'Success', 'compound-woocommerce' ),
				'4000000000000002' => __( 'Invalid card', 'compound-woocommerce' ),
				'4000000000009995' => __( 'Insufficient funds', 'compound-woocommerce' ),
				'4000000000000119' => __( 'Retryable processor decline', 'compound-woocommerce' ),
			),
			'ach'    => array(
				'routing'      => '110000000',
				'000123456789' => __( 'Success', 'compound-woocommerce' ),
				'000111111113' => __( 'Insufficient funds', 'compound-woocommerce' ),
				'000111111116' => __( 'Account closed', 'compound-woocommerce' ),
				'000111111119' => __( 'Retryable processor decline', 'compound-woocommerce' ),
			),
			'crypto' => array(
				'crypto_success'   => __( 'Success', 'compound-woocommerce' ),
				'crypto_declined'  => __( 'Declined', 'compound-woocommerce' ),
				'crypto_retryable' => __( 'Retryable processor decline', 'compound-woocommerce' ),
			),
		);
	}

	/**
	 * The payment methods (rails) the shopper can choose. What they pick is sent to Compound as
	 * method_type, which selects the eligible processors the routing engine chooses among.
	 */
	private function methods(): array {
		return self::method_labels();
	}

	/**
	 * Checkout UI: a short description + a rail chooser. The selection posts as `compound_method`.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			// kses outermost: wpautop adds markup after sanitising, so escaping has to be
			// the last thing applied, not the first.
			echo wp_kses_post( wpautop( $this->description ) );
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
		if ( 'sandbox' === $this->get_option( 'environment' ) ) {
			wp_enqueue_script(
				'wc-compound-sandbox-checkout',
				plugins_url( 'assets/js/sandbox-checkout.js', COMPOUND_WC_FILE ),
				array( 'jquery' ),
				COMPOUND_WC_VERSION,
				true
			);
			echo '<div class="compound-sandbox-fields" data-compound-sandbox-fields>';
			echo '<p><strong>' . esc_html__( 'Sandbox test payment', 'compound-woocommerce' ) . '</strong></p>';
			echo '<div data-compound-sandbox-method="card"><label>' . esc_html__( 'Test card number', 'compound-woocommerce' );
			echo '<input name="compound_card_number" inputmode="numeric" autocomplete="off" value="4242424242424242" /></label></div>';
			echo '<div data-compound-sandbox-method="ach" hidden><label>' . esc_html__( 'Test ACH routing number', 'compound-woocommerce' );
			echo '<input name="compound_ach_routing_number" inputmode="numeric" autocomplete="off" value="110000000" /></label>';
			echo '<label>' . esc_html__( 'Test ACH account number', 'compound-woocommerce' );
			echo '<input name="compound_ach_account_number" inputmode="numeric" autocomplete="off" value="000123456789" /></label></div>';
			echo '<div data-compound-sandbox-method="crypto" hidden><label>' . esc_html__( 'Test crypto session', 'compound-woocommerce' );
			echo '<input name="compound_crypto_reference" autocomplete="off" value="crypto_success" /></label></div>';
			echo '<p class="description">' . esc_html__( 'Test values only. No money will move.', 'compound-woocommerce' ) . '</p></div>';
		}
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'compound-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Compound', 'compound-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'compound-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'compound-woocommerce' ),
				'default'     => __( 'Secure payment', 'compound-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'   => __( 'Description', 'compound-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Choose card, bank transfer, or cryptocurrency. Your payment is processed by Compound.', 'compound-woocommerce' ),
			),
			'environment'    => array(
				'title'   => __( 'Environment', 'compound-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'sandbox' => __( 'Sandbox (test, no real money)', 'compound-woocommerce' ),
					'live'    => __( 'Live', 'compound-woocommerce' ),
				),
				'default' => 'sandbox',
			),
			'api_key'        => array(
				'title'       => __( 'Secret API key', 'compound-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'A Compound secret key (sk_...) with orders:write and charges:write. Create it in the Compound admin portal (Developers).', 'compound-woocommerce' ),
			),
			'orders_url'     => array(
				'title'   => __( 'Orders API base URL', 'compound-woocommerce' ),
				'type'    => 'text',
				'default' => 'https://api.compound.dev',
			),
			'payments_url'   => array(
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

		$payment_method = $this->payment_method( $method );
		if ( is_wp_error( $payment_method ) ) {
			wc_add_notice( $payment_method->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

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
		// that support this method (card -> card processors; ACH -> bank processors;
		// crypto -> the crypto gateway).
		$charge_attempt = max( 1, (int) $order->get_meta( '_compound_charge_attempt' ) );
		if ( 'yes' === $order->get_meta( '_compound_charge_retry_ready' ) ) {
			++$charge_attempt;
			$order->delete_meta_data( '_compound_charge_retry_ready' );
		}
		$order->update_meta_data( '_compound_charge_attempt', $charge_attempt );
		$order->save();
		$charge = $api->create_charge(
			$compound_order_id,
			$amount_cents,
			'wc-charge-' . $reference . '-' . $charge_attempt,
			$method,
			$payment_method
		);
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
		if ( 'declined' === $status ) {
			$order->update_meta_data( '_compound_charge_retry_ready', 'yes' );
		}
		$order->update_status( 'failed', sprintf( 'Compound charge not captured (status: %s).', $status ) );
		$order->save();
		wc_add_notice( __( 'Your payment could not be completed. Please try another method.', 'compound-woocommerce' ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Convert sandbox test values (or a future live hosted-field token) into the opaque
	 * payment_method object sent to Compound. Raw sandbox inputs are never persisted.
	 *
	 * @param string $method Rail the shopper chose: card, ach, or crypto.
	 * @return array|WP_Error
	private function payment_method( string $method ) {
		if ( 'sandbox' !== $this->get_option( 'environment' ) ) {
			// WooCommerce verifies the checkout nonce before process_payment runs, so this
			// is not an unauthenticated read. The ignore has to sit on the line that
			// actually touches $_POST - it applies to the next line only, and the previous
			// placement left the second access on the ternary's continuation unguarded.
			// One line on purpose: phpcs:ignore applies to the next line only, and both the
			// nonce and sanitisation sniffs read the line the access appears on. Splitting
			// it left one access unguarded and made the sanitiser invisible to the linter.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = isset( $_POST['compound_payment_token'] ) ? sanitize_text_field( wp_unslash( $_POST['compound_payment_token'] ) ) : '';
			if ( '' === $token ) {
				return new WP_Error( 'compound_payment_token_required', __( 'Connect a payment method before placing the order.', 'compound-woocommerce' ) );
			}
			if ( 'ach' === $method ) {
				return array( 'bank_account_token' => $token );
			}
			if ( 'crypto' === $method ) {
				return array( 'onramp_session_id' => $token );
			}
			return array( 'token' => $token );
		}

		// WooCommerce verifies the checkout nonce before process_payment runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );
		if ( 'card' === $method ) {
			$number = preg_replace( '/\D+/', '', (string) ( $post['compound_card_number'] ?? '' ) );
			$tokens = array(
				'4242424242424242' => 'ctok_sandbox_success',
				'4000000000000002' => 'ctok_sandbox_invalid',
				'4000000000009995' => 'ctok_sandbox_insufficient_funds',
				'4000000000000119' => 'ctok_sandbox_retryable',
			);
			return isset( $tokens[ $number ] )
				? array( 'token' => $tokens[ $number ] )
				: new WP_Error( 'compound_invalid_test_card', __( 'Use one of the documented sandbox test card numbers.', 'compound-woocommerce' ) );
		}
		if ( 'ach' === $method ) {
			$routing = preg_replace( '/\D+/', '', (string) ( $post['compound_ach_routing_number'] ?? '' ) );
			$account = preg_replace( '/\D+/', '', (string) ( $post['compound_ach_account_number'] ?? '' ) );
			$tokens  = array(
				'000123456789' => 'btok_sandbox_success',
				'000111111113' => 'btok_sandbox_insufficient_funds',
				'000111111116' => 'btok_sandbox_account_closed',
				'000111111119' => 'btok_sandbox_retryable',
			);
			if ( '110000000' !== $routing || ! isset( $tokens[ $account ] ) ) {
				return new WP_Error( 'compound_invalid_test_bank', __( 'Use the documented sandbox ACH routing and account numbers.', 'compound-woocommerce' ) );
			}
			return array( 'bank_account_token' => $tokens[ $account ] );
		}

		$reference = sanitize_text_field( (string) ( $post['compound_crypto_reference'] ?? '' ) );
		$tokens    = array(
			'crypto_success'   => 'xtok_sandbox_success',
			'crypto_declined'  => 'xtok_sandbox_declined',
			'crypto_retryable' => 'xtok_sandbox_retryable',
		);
		return isset( $tokens[ $reference ] )
			? array( 'onramp_session_id' => $tokens[ $reference ] )
			: new WP_Error( 'compound_invalid_test_crypto', __( 'Use one of the documented sandbox crypto sessions.', 'compound-woocommerce' ) );
	}

	/**
	 * Order attribution as WooCommerce records it (Order Attribution feature): where
	 * the order came from. Only non-empty values are sent.
	 *
	 * @param WC_Order $order Order whose attribution meta is being read.
	 * @return array Attribution fields, omitting any WooCommerce did not record.
	 */
	private function attribution( WC_Order $order ): array {
		$fields = array(
			'source_type',
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term',
			'referrer',
			'device_type',
			'session_entry',
		);
		$out    = array();
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
	 *
	 * @param WC_Order $order Order whose shipping destination is being sent.
	 * @return array Shipping address fields required to fulfil the order.
	 */
	private function shipping_address( WC_Order $order ): array {
		return array(
			'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'line1'   => $order->get_shipping_address_1(),
			'line2'   => $order->get_shipping_address_2(),
			'city'    => $order->get_shipping_city(),
			'state'   => $order->get_shipping_state(),
			'zip'     => $order->get_shipping_postcode(),
			'country' => $order->get_shipping_country(),
		);
	}
}

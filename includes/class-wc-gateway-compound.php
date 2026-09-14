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
		$this->migrate_legacy_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * One-time migration for installs saved before Orders/Payments URLs were combined into a
	 * single API base (they were always the same host - Compound's public API routes to both
	 * services by path, not by host). Runs on every load but only writes once `api_base` is
	 * missing; self-healing, no separate activation hook needed.
	 */
	private function migrate_legacy_settings(): void {
		if ( ! empty( $this->settings['api_base'] ) ) {
			return;
		}
		$legacy = $this->settings['orders_url'] ?? ( $this->settings['payments_url'] ?? '' );
		if ( ! $legacy ) {
			return;
		}
		$this->settings['api_base'] = $legacy;
		unset( $this->settings['orders_url'], $this->settings['payments_url'] );
		update_option( $this->get_option_key(), $this->settings );
	}

	/**
	 * The payment methods (rails) a shopper could choose, keyed by the method_type sent to
	 * Compound. Single source of truth so the classic checkout (payment_fields) and the block
	 * checkout (WC_Compound_Blocks) offer exactly the same rails - they must never drift.
	 */
	public static function method_labels(): array {
		return array(
			'card'        => __( 'Card', 'compound-woocommerce' ),
			'ach'         => __( 'Bank transfer (ACH)', 'compound-woocommerce' ),
			// Open banking: the customer authorises the debit inside their own bank, so no
			// account number is entered on this site at all.
			'pay_by_bank' => __( 'Pay by bank', 'compound-woocommerce' ),
			'crypto'      => __( 'Cryptocurrency', 'compound-woocommerce' ),
		);
	}

	/**
	 * The rails from method_labels() filtered down to whichever this merchant has toggled on
	 * (WooCommerce -> Settings -> Payments -> Compound). Missing enable_* keys default to "yes"
	 * (on) so installs saved before this setting existed keep every rail they had before.
	 *
	 * @param array $settings Raw gateway settings (either $this->settings, or the same option
	 *                        read directly - the block checkout reads it without an instance).
	 * @return array
	 */
	public static function enabled_methods( array $settings ): array {
		return array_filter(
			self::method_labels(),
			function ( $method ) use ( $settings ) {
				return 'no' !== ( $settings[ "enable_{$method}" ] ?? 'yes' );
			},
			ARRAY_FILTER_USE_KEY
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
		return self::enabled_methods( $this->settings );
	}

	/** The email this checkout is for, when it is already known (logged-in or posted). */
	private function checkout_email(): string {
		if ( is_user_logged_in() ) {
			return (string) wp_get_current_user()->user_email;
		}
		return WC()->customer ? (string) WC()->customer->get_billing_email() : '';
	}

	/**
	 * Never offer Compound at checkout with zero rails enabled - toggling every method off is
	 * equivalent to disabling the gateway, not an empty chooser.
	 */
	public function is_available(): bool {
		return parent::is_available() && ! empty( $this->methods() );
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
		// The first rail is the one checked, which decides whether the pay-by-bank panel starts
		// visible. Rendered server-side so the initial state is right before any script runs,
		// rather than flashing the panel open and then hiding it.
		$default = (string) array_key_first( $methods );
		if ( count( $methods ) === 1 ) {
			// One rail is not a choice. A lone radio button asks the shopper to pick the only
			// option there is, so the selection just posts.
			printf(
				'<input type="hidden" name="compound_method" value="%s" />',
				esc_attr( $default )
			);
		} else {
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
		// Pay by bank needs the customer to link before placing the order, so its panel is
		// rendered with the rails rather than after submission. Hidden until the rail is
		// chosen; the shared script handles that.
		if ( array_key_exists( WC_Compound_PayByBank::METHOD, $methods ) ) {
			printf(
				'<div class="compound-pbb-panel" data-method="%s"%s>',
				esc_attr( WC_Compound_PayByBank::METHOD ),
				WC_Compound_PayByBank::METHOD === $default ? '' : ' hidden'
			);
			WC_Compound_PayByBank::render_field( $this->checkout_email() );
			echo '</div>';
		}
		// Sandbox test values exist for the rails where the shopper types a number here. Pay by
		// bank has none: the test profile is chosen inside the provider's own flow, so on a
		// pay-by-bank-only checkout this block is a heading over three hidden fields.
		$typed_rails = array_intersect_key( $methods, array_flip( array( 'card', 'ach', 'crypto' ) ) );
		if ( 'sandbox' === $this->get_option( 'environment' ) && ! empty( $typed_rails ) ) {
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
		$this->form_fields = $this->build_form_fields();
	}

	/**
	 * The settings screen's fields. Split out so the per-rail toggles can be generated from
	 * method_labels() instead of being maintained as a second, hand-written copy of it.
	 */
	private function build_form_fields(): array {
		$fields = array(
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
				'default' => __( 'Your payment is processed by Compound.', 'compound-woocommerce' ),
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
			'api_base'       => array(
				'title'       => __( 'API base URL', 'compound-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Compound\'s public API - one host, routing to both Orders and Payments by path.', 'compound-woocommerce' ),
				'default'     => 'https://api.thepeptides.company',
				'desc_tip'    => true,
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'compound-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Verifies inbound Compound webhooks (order.shipped/delivered).', 'compound-woocommerce' ),
			),
			'custom_css'     => array(
				'title'       => __( 'Custom CSS', 'compound-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Applied on every page this plugin renders anything on (checkout, the telemedicine intake form) - use it to match your site\'s look. Printed after the plugin\'s own base styles, so it can override them.', 'compound-woocommerce' ),
				'css'         => 'width:100%;height:160px;font-family:monospace;',
			),
		);

		// One toggle per rail, built from method_labels() rather than written out here. The
		// two lists drifted apart once already: pay by bank shipped at checkout while this
		// screen still offered three methods, so the only way to turn it off was to not have
		// it, and the only way to find that out was to look at the code.
		$methods = array();
		$first   = true;
		foreach ( self::method_labels() as $method => $label ) {
			$methods[ "enable_{$method}" ] = array_merge(
				// WooCommerce prints the title once and groups what follows under it, so only
				// the first row carries it.
				$first ? array( 'title' => __( 'Payment methods', 'compound-woocommerce' ) ) : array(),
				array(
					'type'    => 'checkbox',
					'label'   => $label,
					'default' => 'yes',
				)
			);
			if ( 'pay_by_bank' === $method ) {
				$methods[ "enable_{$method}" ]['description'] = __(
					'Open banking: the customer authorises the debit inside their own bank, so no account number is entered on this site. Compound picks the provider.',
					'compound-woocommerce'
				);
			}
			$first = false;
		}

		// Rails sit between the credentials and the styling, which is where they read best.
		$css = $fields['custom_css'];
		unset( $fields['custom_css'] );
		return array_merge( $fields, $methods, array( 'custom_css' => $css ) );
	}

	private function api(): WC_Compound_API {
		return new WC_Compound_API(
			$this->get_option( 'api_base' ),
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
		// A method that isn't currently enabled (tampered request, or disabled after the page
		// loaded) falls back to whichever enabled rail sorts first - never a hardcoded 'card',
		// which the merchant may have turned off.
		$enabled = $this->methods();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$method = isset( $_POST['compound_method'] ) ? sanitize_text_field( wp_unslash( $_POST['compound_method'] ) ) : '';
		if ( ! array_key_exists( $method, $enabled ) ) {
			$method = (string) array_key_first( $enabled );
		}
		$order->update_meta_data( '_compound_method', $method );

		$payment_method = $this->payment_method( $method );
		if ( is_wp_error( $payment_method ) ) {
			WC_Compound_Sentry::report(
				'payment_method failed: ' . $payment_method->get_error_message(),
				array(
					'order_id' => $order_id,
					'method'   => $method,
				)
			);
			wc_add_notice( $payment_method->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		// Build line items as {sku, quantity} ONLY - plus consult_type/consult_kind when
		// telemedicine is on,
		// so Compound can link/auto-reup a consult server-side. Telemedicine has no per-product
		// opt-in: the brand-level toggle (Compound admin portal) is the only gate - every
		// product is included once it's on (class-wc-gen-health-product-meta.php). A missing
		// SKU can't be fulfilled.
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';
			if ( '' === $sku ) {
				wc_add_notice( __( 'A product in your cart is not set up for Compound fulfillment. Please contact support.', 'compound-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$line_item = array(
				'sku'      => $sku,
				'quantity' => (int) $item->get_quantity(),
			);
			if ( $product && WC_Gen_Health_Settings::is_active() ) {
				$line_item['consult_type'] = WC_Gen_Health_Product_Meta::consult_type( $product );
				// Compound routes on the kind as well as the type, and the two consult kinds
				// are not interchangeable, so send it rather than letting the API assume.
				$line_item['consult_kind'] = WC_Gen_Health_Product_Meta::consult_kind( $product );
			}
			// Screening answers collected when this line went in the cart. Sent raw: Compound
			// resolves them against the saved questionnaire, supplying the labels and deciding
			// which answers hold the order, so nothing here can relabel a question or claim an
			// answer is unremarkable.
			$answers = $item->get_meta( WC_Compound_Screening::ORDER_META );
			if ( is_array( $answers ) && ! empty( $answers ) ) {
				$line_item['intake_answers'] = $answers;
			}
			$line_items[] = $line_item;
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
			$meta,
			(string) $order->get_customer_note(),
			// get_edit_order_url() resolves the correct wp-admin URL regardless of whether
			// this store uses HPOS or legacy post-based order storage - never build that URL
			// by hand on the Compound side, where neither piece of information is known.
			$order->get_edit_order_url()
		);
		if ( is_wp_error( $created ) ) {
			WC_Compound_Sentry::report(
				'create_order failed: ' . $created->get_error_message(),
				array(
					'order_id'  => $order_id,
					'reference' => $reference,
				)
			);
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
			WC_Compound_Sentry::report(
				'create_charge failed: ' . $charge->get_error_message(),
				array(
					'order_id'          => $order_id,
					'compound_order_id' => $compound_order_id,
				)
			);
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
	 */
	private function payment_method( string $method ) {
		// Pay by bank has no sandbox card-number equivalent: the token is always a real
		// provider-issued customer reference produced by the linking flow, in both
		// environments, because there is nothing else it could be.
		if ( WC_Compound_PayByBank::METHOD === $method ) {
			// Its own field, deliberately not compound_payment_token: the card rail reads that
			// one in live mode, and a bank reference must never be picked up as a card token.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before process_payment runs.
			$token = isset( $_POST['compound_pbb_token'] ) ? sanitize_text_field( wp_unslash( $_POST['compound_pbb_token'] ) ) : '';
			if ( '' === $token ) {
				return new WP_Error( 'compound_bank_link_required', __( 'Link your bank account before placing the order.', 'compound-woocommerce' ) );
			}
			return array( 'bank_account_token' => $token );
		}
		if ( 'sandbox' !== $this->get_option( 'environment' ) ) {
			// WooCommerce verifies the checkout nonce before process_payment runs, so this
			// is not an unauthenticated read. Kept on one line because phpcs:ignore applies
			// to the next line only, and both the nonce and sanitisation sniffs read the
			// line the access appears on - splitting it leaves an access unguarded and
			// hides the sanitiser from the linter.
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

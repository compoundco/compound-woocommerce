<?php
/**
 * Registers the Compound gateway with the WooCommerce Cart/Checkout blocks. A classic
 * WC_Payment_Gateway is invisible to the block checkout ("no payment methods available")
 * unless it also ships a block integration - this is that integration. It renders the same
 * card / bank transfer / crypto rail chooser as the classic checkout and hands the choice back
 * to the server (as compound_method) so WC_Gateway_Compound::process_payment works unchanged.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Compound_Blocks extends AbstractPaymentMethodType {

	protected $name = 'compound';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_compound_settings', array() );
	}

	/**
	 * Only offer Compound in the block checkout when the gateway is enabled and at least one
	 * payment rail is toggled on - matches the classic checkout's availability
	 * (WC_Gateway_Compound::is_available()).
	 */
	public function is_active(): bool {
		if ( ! isset( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}
		return ! empty( WC_Gateway_Compound::enabled_methods( $this->settings ) );
	}

	/**
	 * The client script that calls registerPaymentMethod. No build step: it uses the WooCommerce
	 * Blocks + WordPress UMD globals declared as dependencies.
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'wc-compound-blocks';
		wp_register_script(
			$handle,
			plugins_url( 'assets/js/blocks.js', COMPOUND_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities' ),
			COMPOUND_WC_VERSION,
			true
		);
		return array( $handle );
	}

	/**
	 * Data handed to the client script (available there as getSetting('compound_data')). The rails
	 * come from the gateway's single source so classic + block checkout never diverge.
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => (string) ( $this->settings['title'] ?? __( 'Compound', 'compound-woocommerce' ) ),
			'description' => (string) ( $this->settings['description'] ?? '' ),
			'methods'     => WC_Gateway_Compound::enabled_methods( $this->settings ),
			'sandbox'     => 'sandbox' === ( $this->settings['environment'] ?? 'sandbox' ),
			'testValues'  => WC_Gateway_Compound::sandbox_test_values(),
			// Pay by bank needs the customer to link a bank before the order can be placed, so
			// the block checkout needs the same AJAX endpoints and nonce the classic one uses.
			// Without this the rail is selectable in the block checkout and there is nothing to
			// click, which is exactly how it behaved.
			'payByBank'   => array(
				'method'  => WC_Compound_PayByBank::METHOD,
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'compound_pbb' ),
				// The block checkout only knows what the shopper has typed into ITS OWN fields
				// so far, not their account - a signed-in customer who has not touched the name
				// fields yet would otherwise be blocked from linking a bank they are entitled to
				// link. Used only as a fallback behind whatever the shopper has actually entered.
				'account' => is_user_logged_in() ? self::current_user_billing() : null,
			),
			'supports'    => array( 'products', 'refunds' ),
		);
	}

	/**
	 * The signed-in customer's own name and email, from their WooCommerce billing profile
	 * where set, else their account record. Never used to override a field the shopper has
	 * actually typed - see billingDetails() in blocks.js.
	 *
	 * @return array{first_name: string, last_name: string, email: string}
	 */
	private static function current_user_billing(): array {
		$user    = wp_get_current_user();
		$wc_cust = class_exists( 'WC_Customer' ) ? new WC_Customer( $user->ID ) : null;
		$first   = $wc_cust ? $wc_cust->get_billing_first_name() : '';
		$last    = $wc_cust ? $wc_cust->get_billing_last_name() : '';
		return array(
			'first_name' => '' !== $first ? $first : (string) $user->first_name,
			'last_name'  => '' !== $last ? $last : (string) $user->last_name,
			'email'      => (string) $user->user_email,
		);
	}
}

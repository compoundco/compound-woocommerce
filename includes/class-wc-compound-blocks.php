<?php
/**
 * Registers the Compound gateway with the WooCommerce Cart/Checkout blocks. A classic
 * WC_Payment_Gateway is invisible to the block checkout ("no payment methods available")
 * unless it also ships a block integration - this is that integration. It renders the same
 * card / crypto rail chooser as the classic checkout and hands the choice back to the
 * server (as compound_method) so WC_Gateway_Compound::process_payment works unchanged.
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
	 * Only offer Compound in the block checkout when the gateway is enabled - matches the
	 * classic checkout's availability.
	 */
	public function is_active(): bool {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
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
			'methods'     => WC_Gateway_Compound::method_labels(),
			'supports'    => array( 'products', 'refunds' ),
		);
	}
}

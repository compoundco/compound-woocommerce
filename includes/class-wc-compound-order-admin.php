<?php
/**
 * Links a WooCommerce order back to its record in the Compound brand portal, wherever the
 * order edit screen already shows order-level details. HPOS-safe: hooks WooCommerce's own
 * order-data-panel action rather than reading/writing custom order tables directly.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Order_Admin {

	public function register(): void {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_link' ) );
	}

	/**
	 * Print the "View in Compound" link, if this order has a linked Compound order.
	 *
	 * @param WC_Order $order The order being viewed in wp-admin.
	 */
	public function render_link( WC_Order $order ): void {
		$compound_order_id = (string) $order->get_meta( '_compound_order_id' );
		if ( '' === $compound_order_id ) {
			return;
		}

		$settings    = get_option( 'woocommerce_compound_settings', array() );
		$portal_base = untrailingslashit( (string) ( $settings['portal_base'] ?? 'https://app.thepeptides.company' ) );
		$url         = $portal_base . '/orders/' . rawurlencode( $compound_order_id );

		printf(
			'<p class="form-field form-field-wide"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'View in Compound →', 'compound-woocommerce' )
		);
	}
}

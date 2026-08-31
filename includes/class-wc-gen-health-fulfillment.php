<?php
/**
 * Decrements a prescription's remaining fills when an order linked to it ships. Listens to
 * the compound_wc_order_shipped extension point added in class-wc-compound-webhooks.php
 * rather than that file needing to know Gen Health exists.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Fulfillment {

	public function register(): void {
		add_action( 'compound_wc_order_shipped', array( $this, 'decrement_fill' ) );
	}

	public function decrement_fill( WC_Order $order ): void {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return;
		}
		$user_id           = $order->get_customer_id();
		$client_product_id = (string) $order->get_meta( WC_Gen_Health_Order_Link::PRODUCT_META );
		if ( ! $user_id || '' === $client_product_id ) {
			return;
		}
		WC_Gen_Health_Rx::decrement_fill( $user_id, $client_product_id );
	}
}

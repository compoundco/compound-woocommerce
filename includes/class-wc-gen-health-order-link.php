<?php
/**
 * Tags a WC order with the customer's current Gen Health RX request for any telehealth-gated
 * product it contains, and fires a fresh consult request when the customer has none with
 * fills remaining. Deliberately never touches WHETHER or WHEN WC_Compound_API::create_order()
 * runs - order creation proceeds exactly as it does today; this only tags it afterward.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Order_Link {

	const RX_REQUEST_META = '_gen_health_rx_request_id';
	const PRODUCT_META    = '_gen_health_client_product_id';

	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'tag_order' ), 10, 3 );
	}

	public function tag_order( int $order_id, $posted_data, WC_Order $order ): void {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return;
		}
		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return; // Guest checkout - no patient record exists to link to.
		}

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! WC_Gen_Health_Product_Meta::requires_consult( $product ) ) {
				continue;
			}
			$client_product_id = WC_Gen_Health_Product_Meta::client_product_id( $product );
			if ( '' === $client_product_id ) {
				continue;
			}

			$patient_id = get_user_meta( $user_id, WC_Gen_Health_Intake::PATIENT_ID_META, true );
			if ( ! $patient_id ) {
				// Bought a gated product without ever completing intake. Purchase still isn't
				// blocked (per plan) - flag it for a human, since there is no patient record
				// to start a consult against.
				$order->add_order_note( __( 'Gen Health: this order contains a telehealth-gated product, but the customer has no health intake on file. No consult could be started.', 'compound-woocommerce' ) );
				continue;
			}

			// Auto re-up: no active (pending, or approved-with-fills-left) request for this
			// product - start a new one in the background. The purchase still proceeds
			// unblocked either way.
			if ( ! WC_Gen_Health_Rx::has_active_request( $user_id, $client_product_id ) ) {
				WC_Gen_Health_Rx::start_consult( $user_id, $patient_id, $client_product_id );
			}

			$request = WC_Gen_Health_Rx::get_request( $user_id, $client_product_id );
			if ( $request && ! empty( $request['order_id'] ) ) {
				$order->update_meta_data( self::RX_REQUEST_META, $request['order_id'] );
				$order->update_meta_data( self::PRODUCT_META, $client_product_id );
				$order->save();
			}
		}
	}
}

<?php
/**
 * Receives inbound webhooks from Compound (order.shipped / delivered / cancelled)
 * and reflects them on the WooCommerce order. Signature-verified before processing.
 *
 * NOTE: Compound outbound webhooks to brands are on the roadmap; this endpoint is
 * ready for them and can also be driven manually in testing.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Webhooks {

	public function register(): void {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'compound/v1',
					'/webhook',
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'handle' ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	public function handle( WP_REST_Request $request ) {
		$raw    = $request->get_body();
		$secret = $this->secret();

		// Verify HMAC-SHA256 over the raw body before trusting anything.
		if ( '' === $secret || ! $this->verify( $raw, $request->get_header( 'compound_signature' ), $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		$data     = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		$order_id = (string) ( $data['order_id'] ?? '' );
		$order    = $order_id ? $this->find_order( $order_id ) : null;
		if ( ! $order ) {
			// Ack so Compound doesn't retry an event for an order we don't have.
			return new WP_REST_Response( array( 'applied' => false, 'reason' => 'order not found' ), 200 );
		}

		switch ( $event['type'] ) {
			case 'order.shipped':
				$carrier  = (string) ( $data['carrier'] ?? '' );
				$tracking = (string) ( $data['tracking_number'] ?? '' );
				$order->update_meta_data( '_compound_carrier', $carrier );
				$order->update_meta_data( '_compound_tracking', $tracking );
				$order->add_order_note( sprintf( 'Shipped via %s (tracking %s).', $carrier, $tracking ) );
				$order->save();
				break;
			case 'order.delivered':
				$order->update_status( 'completed', 'Delivered (reported by Compound).' );
				break;
			case 'order.cancelled':
				$order->update_status( 'cancelled', 'Cancelled (reported by Compound).' );
				break;
			default:
				// Tolerate unknown event types (additive-only evolution).
				return new WP_REST_Response( array( 'applied' => false, 'reason' => 'ignored' ), 200 );
		}

		return new WP_REST_Response( array( 'applied' => true ), 200 );
	}

	private function verify( string $body, ?string $signature, string $secret ): bool {
		if ( null === $signature || '' === $signature ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $expected, trim( $signature ) );
	}

	private function secret(): string {
		$settings = get_option( 'woocommerce_compound_settings', array() );
		return is_array( $settings ) ? (string) ( $settings['webhook_secret'] ?? '' ) : '';
	}

	/**
	 * Find the WooCommerce order carrying this Compound order id (HPOS-safe).
	 */
	private function find_order( string $compound_order_id ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_compound_order_id',
				'meta_value' => $compound_order_id,
			)
		);
		return ! empty( $orders ) ? $orders[0] : null;
	}
}

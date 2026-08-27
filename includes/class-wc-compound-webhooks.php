<?php
/**
 * Receives outbound webhooks from Compound and mirrors fulfillment status onto the
 * WooCommerce order. Compound owns the canonical order state and delivers order.*
 * events (routed / shipped / delivered / exception / cancelled), signed HMAC-SHA256
 * over the raw body (Compound-Signature). Signature-verified before processing;
 * idempotent per event on Compound's side (safe to retry).
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
			WC_Compound_Sentry::report( 'inbound webhook: invalid signature' );
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			WC_Compound_Sentry::report( 'inbound webhook: invalid payload' );
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		$data     = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		$order_id = (string) ( $data['order_id'] ?? '' );
		$order    = $order_id ? $this->find_order( $order_id ) : null;
		if ( ! $order ) {
			// Ack so Compound doesn't retry an event for an order we don't have.
			return new WP_REST_Response(
				array(
					'applied' => false,
					'reason'  => 'order not found',
				),
				200
			);
		}

		// Map Compound fulfillment events onto native WooCommerce statuses + notes. Compound
		// owns the canonical fulfillment state (routed -> compounding -> shipped -> delivered,
		// or exception); WooCommerce mirrors it for the merchant.
		switch ( $event['type'] ) {
			case 'order.routed':
				$pharmacy = (string) ( $data['pharmacy_id'] ?? '' );
				$order->update_meta_data( '_compound_pharmacy_id', $pharmacy );
				$order->add_order_note( sprintf( 'Routed to pharmacy %s (Compound).', $pharmacy ) );
				// Move a still-pending order into fulfillment.
				if ( in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
					$order->update_status( 'processing', 'Routed to pharmacy (Compound).' );
				} else {
					$order->save();
				}
				break;
			case 'order.shipped':
				$carrier  = (string) ( $data['carrier'] ?? '' );
				$tracking = (string) ( $data['tracking_number'] ?? '' );
				$order->update_meta_data( '_compound_carrier', $carrier );
				$order->update_meta_data( '_compound_tracking', $tracking );
				$order->add_order_note( sprintf( 'Shipped via %s (tracking %s).', $carrier, $tracking ) );
				if ( 'completed' !== $order->get_status() ) {
					$order->update_status( 'processing', 'Shipped (reported by Compound).' );
				} else {
					$order->save();
				}
				break;
			case 'order.delivered':
				$order->update_status( 'completed', 'Delivered (reported by Compound).' );
				break;
			case 'order.exception':
				// Fail-safe: a fulfillment problem holds the order for a human.
				$order->update_status( 'on-hold', 'Fulfillment exception (reported by Compound). Needs review.' );
				break;
			case 'order.cancelled':
				$order->update_status( 'cancelled', 'Cancelled (reported by Compound).' );
				break;
			default:
				// Tolerate unknown / non-fulfillment event types (additive-only evolution).
				return new WP_REST_Response(
					array(
						'applied' => false,
						'reason'  => 'ignored',
					),
					200
				);
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
	 *
	 * @param string $compound_order_id Compound's order id, stored on the order as meta.
	 * @return WC_Order|null The matching order, or null when none carries that id.
	 */
	private function find_order( string $compound_order_id ): ?WC_Order {
		// meta_key/meta_value is the supported way to look an order up by a foreign id in
		// WooCommerce, and _compound_order_id is unique per order, so this returns one row
		// rather than scanning. The sniff warns about unindexed meta at scale; accepted.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => '_compound_order_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $compound_order_id,
			)
		);
		return ! empty( $orders ) ? $orders[0] : null;
	}
}

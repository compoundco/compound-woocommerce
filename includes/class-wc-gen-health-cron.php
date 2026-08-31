<?php
/**
 * WP-Cron poll of outstanding Gen Health consult requests - the primary (and, until Gen
 * Health confirms a webhook signing scheme, only) way this plugin learns a consult resolved.
 * On approval: records the prescription + fill count. On denial: refunds every WC order
 * linked to that request through Compound's real refund endpoint (never a WooCommerce-side
 * status flip - money already moved, so only Compound reversing it is honest) unless the
 * order already shipped, in which case a human is flagged instead of an automatic reversal -
 * the same fail-safe split Compound itself uses for a post-ship ACH return.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Cron {

	const HOOK = 'gen_health_poll_rx_requests';

	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'poll' ) );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	public function poll(): void {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return;
		}
		$api = WC_Gen_Health_Settings::api();
		foreach ( WC_Gen_Health_Rx::pending_requests() as $row ) {
			$this->resolve_one( $api, (int) $row['user_id'], (string) $row['client_product_id'] );
		}
	}

	private function resolve_one( WC_Gen_Health_API $api, int $user_id, string $client_product_id ): void {
		$request = WC_Gen_Health_Rx::get_request( $user_id, $client_product_id );
		if ( null === $request || 'pending' !== $request['status'] || '' === $request['order_id'] ) {
			WC_Gen_Health_Rx::dequeue_pending( $user_id, $client_product_id );
			return;
		}

		$order = $api->get_order( $request['order_id'] );
		if ( is_wp_error( $order ) ) {
			return; // Transient - stays queued, tried again next run.
		}

		$order_status  = (string) ( $order['orderStatus'] ?? '' );
		$prescriptions = is_array( $order['prescriptions'] ?? null ) ? $order['prescriptions'] : array();

		if ( ! empty( $prescriptions ) ) {
			$this->approve( $user_id, $client_product_id, $request, $prescriptions[0] );
			return;
		}
		if ( in_array( $order_status, array( 'denied', 'cancelled', 'refunded' ), true ) ) {
			$this->deny( $user_id, $client_product_id, $request );
		}
		// Otherwise still under clinical review - leave it queued for the next poll.
	}

	private function approve( int $user_id, string $client_product_id, array $request, array $rx ): void {
		$refills                    = (int) ( $rx['refills'] ?? 0 );
		$request['status']          = 'approved';
		$request['prescription_id'] = (string) ( $rx['prescriptionId'] ?? '' );
		$request['medication']      = (string) ( $rx['medicationName'] ?? '' );
		$request['prescriber']      = (string) ( $rx['providerName'] ?? '' );
		$request['fills_total']     = 1 + $refills;
		$request['fills_used']      = 0;
		$request['fills_remaining'] = 1 + $refills;
		WC_Gen_Health_Rx::save_request( $user_id, $client_product_id, $request );
		WC_Gen_Health_Rx::dequeue_pending( $user_id, $client_product_id );
	}

	private function deny( int $user_id, string $client_product_id, array $request ): void {
		$request['status'] = 'denied';
		WC_Gen_Health_Rx::save_request( $user_id, $client_product_id, $request );
		WC_Gen_Health_Rx::dequeue_pending( $user_id, $client_product_id );
		$this->refund_linked_orders( (string) $request['order_id'] );
	}

	private function refund_linked_orders( string $rx_request_order_id ): void {
		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => WC_Gen_Health_Order_Link::RX_REQUEST_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $rx_request_order_id,
			)
		);
		foreach ( $orders as $order ) {
			if ( $this->already_shipped( $order ) ) {
				$order->add_order_note( __( 'Gen Health: consult was denied, but this order has already shipped. Flagged for manual review - not auto-reversed.', 'compound-woocommerce' ) );
				continue;
			}
			$this->refund_order( $order );
		}
	}

	private function already_shipped( WC_Order $order ): bool {
		return 'completed' === $order->get_status() || '' !== (string) $order->get_meta( '_compound_tracking' );
	}

	private function refund_order( WC_Order $order ): void {
		$charge_id = (string) $order->get_meta( '_compound_charge_id' );
		if ( '' === $charge_id ) {
			$order->add_order_note( __( 'Gen Health: consult was denied, but this order has no linked Compound charge to refund.', 'compound-woocommerce' ) );
			return;
		}
		$settings = get_option( 'woocommerce_compound_settings', array() );
		$api      = new WC_Compound_API( $settings['api_base'] ?? '', $settings['api_key'] ?? '' );
		$result   = $api->refund_charge( $charge_id, null, 'gen_health_consult_denied', 'gen-health-denial-refund-' . $order->get_id() );
		if ( is_wp_error( $result ) ) {
			WC_Compound_Sentry::report(
				'Gen Health denial refund failed: ' . $result->get_error_message(),
				array(
					'order_id'  => $order->get_id(),
					'charge_id' => $charge_id,
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Gen Health: consult was denied. Refund attempt failed: %s. Needs manual follow-up.', 'compound-woocommerce' ),
					$result->get_error_message()
				)
			);
			return;
		}
		// Compound's own order state machine reacts to charge.refunded independently and
		// delivers order.cancelled via the existing inbound webhook (class-wc-compound-
		// webhooks.php) - this note gives immediate visibility without waiting on that
		// round-trip; it does not itself change the order status (one source of truth).
		$order->add_order_note( __( 'Gen Health: consult was denied. Refunded through Compound.', 'compound-woocommerce' ) );
	}
}

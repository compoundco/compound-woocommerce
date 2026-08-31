<?php
/**
 * Owns the RX-request / fills data model - the one place that reads and writes it, so
 * class-wc-gen-health-intake.php (starts a request), class-wc-gen-health-order-link.php
 * (tags orders, triggers a re-up), class-wc-gen-health-cron.php (resolves a request), and
 * class-wc-gen-health-fulfillment.php (decrements fills on shipment) all agree on its shape.
 *
 * Storage: one user-meta row per (customer, Gen Health clientProductId) holding the request's
 * state as JSON - `_gen_health_rx_{client_product_id}`. A small wp_option array indexes every
 * still-unresolved request so the cron poll never has to scan all users/meta.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Rx {

	const PENDING_QUEUE_OPTION = 'gen_health_pending_rx_requests';

	private static function meta_key( string $client_product_id ): string {
		return '_gen_health_rx_' . $client_product_id;
	}

	/**
	 * Start a consult for this patient/product and store it as the customer's current
	 * (pending) request for that product - overwriting any prior resolved request, which is
	 * exactly the "fills exhausted -> a fresh request" re-up case.
	 *
	 * @param int    $user_id           WordPress user id of the customer.
	 * @param string $patient_id        Gen Health patientId.
	 * @param string $client_product_id Gen Health clientProductId for the medication requested.
	 * @return array|WP_Error The stored request record, or the API error.
	 */
	public static function start_consult( int $user_id, string $patient_id, string $client_product_id ) {
		$order = WC_Gen_Health_Settings::api()->create_order( $patient_id, $client_product_id );
		if ( is_wp_error( $order ) ) {
			WC_Compound_Sentry::report(
				'Gen Health create_order (consult) failed: ' . $order->get_error_message(),
				array(
					'user_id'           => $user_id,
					'client_product_id' => $client_product_id,
				)
			);
			return $order;
		}

		$request = array(
			'order_id'          => (string) ( $order['orderId'] ?? '' ),
			'client_product_id' => $client_product_id,
			'status'            => 'pending', // One of: pending, approved, denied.
			'prescription_id'   => '',
			'medication'        => '',
			'prescriber'        => '',
			'fills_total'       => 0,
			'fills_used'        => 0,
			'fills_remaining'   => 0,
			'requested_at'      => gmdate( 'c' ),
		);
		self::save_request( $user_id, $client_product_id, $request );
		self::enqueue_pending( $user_id, $client_product_id );
		return $request;
	}

	public static function get_request( int $user_id, string $client_product_id ): ?array {
		$raw = get_user_meta( $user_id, self::meta_key( $client_product_id ), true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public static function save_request( int $user_id, string $client_product_id, array $request ): void {
		update_user_meta( $user_id, self::meta_key( $client_product_id ), wp_json_encode( $request ) );
	}

	/**
	 * True when the customer has an approved request for this product with fills left.
	 *
	 * @param int    $user_id           WordPress user id of the customer.
	 * @param string $client_product_id Gen Health clientProductId.
	 */
	public static function has_fills_remaining( int $user_id, string $client_product_id ): bool {
		$request = self::get_request( $user_id, $client_product_id );
		return null !== $request && 'approved' === $request['status'] && (int) $request['fills_remaining'] > 0;
	}

	/**
	 * True when the customer has any non-terminal (pending or fill-bearing approved) request.
	 *
	 * @param int    $user_id           WordPress user id of the customer.
	 * @param string $client_product_id Gen Health clientProductId.
	 */
	public static function has_active_request( int $user_id, string $client_product_id ): bool {
		$request = self::get_request( $user_id, $client_product_id );
		if ( null === $request ) {
			return false;
		}
		return 'pending' === $request['status'] || self::has_fills_remaining( $user_id, $client_product_id );
	}

	/**
	 * Consume one fill after an order linked to this request ships.
	 *
	 * @param int    $user_id           WordPress user id of the customer.
	 * @param string $client_product_id Gen Health clientProductId.
	 */
	public static function decrement_fill( int $user_id, string $client_product_id ): void {
		$request = self::get_request( $user_id, $client_product_id );
		if ( null === $request || 'approved' !== $request['status'] ) {
			return;
		}
		$request['fills_used']      = (int) $request['fills_used'] + 1;
		$request['fills_remaining'] = max( 0, (int) $request['fills_remaining'] - 1 );
		self::save_request( $user_id, $client_product_id, $request );
	}

	/**
	 * Every still-unresolved (pending) RX request, across all customers.
	 *
	 * @return array<int, array{user_id: int, client_product_id: string}>
	 */
	public static function pending_requests(): array {
		$queue = get_option( self::PENDING_QUEUE_OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	private static function enqueue_pending( int $user_id, string $client_product_id ): void {
		$queue   = self::pending_requests();
		$queue[] = array(
			'user_id'           => $user_id,
			'client_product_id' => $client_product_id,
		);
		update_option( self::PENDING_QUEUE_OPTION, $queue, false );
	}

	public static function dequeue_pending( int $user_id, string $client_product_id ): void {
		$queue = array_values(
			array_filter(
				self::pending_requests(),
				static fn( array $row ) => ! ( (int) $row['user_id'] === $user_id && $row['client_product_id'] === $client_product_id )
			)
		);
		update_option( self::PENDING_QUEUE_OPTION, $queue, false );
	}
}

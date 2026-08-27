<?php
/**
 * HTTP client for the Compound external API (Orders + Payments). One public host routes
 * to both services by path (/v1/orders*, /v1/coupons* -> Orders; /v1/charges* -> Payments) -
 * this client never needs, and never takes, two different hosts. Server-side only; the
 * brand's secret API key never reaches the browser.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_API {

	private string $api_base;
	private string $api_key;

	public function __construct( string $api_base, string $api_key ) {
		$this->api_base = untrailingslashit( $api_base );
		$this->api_key  = $api_key;
	}

	/**
	 * Order-first intake. Sends ONLY {sku, quantity} per line item plus the amount -
	 * never price, name, or images (Compound data-minimization rule).
	 *
	 * @param array  $line_items       List of ['sku' => string, 'quantity' => int].
	 * @param int    $amount_cents     Total in integer minor units (cents).
	 * @param array  $customer         ['email' => string].
	 * @param array  $shipping_address Associative address array (PHI destination).
	 * @param string $order_reference  The brand's own reference (WooCommerce order key).
	 * @param string $idempotency_key  Stable key so retries don't duplicate the order.
	 * @param array  $meta             Attribution + discount recorded on the order:
	 *                                 channel, attribution (assoc), coupon_code, discount_cents.
	 * @param string $note             Customer-provided checkout note (optional).
	 * @return array|WP_Error Decoded order on success.
	 */
	public function create_order( array $line_items, int $amount_cents, array $customer, array $shipping_address, string $order_reference, string $idempotency_key, array $meta = array(), string $note = '' ) {
		$body = array(
			'amount'           => $amount_cents,
			'currency'         => 'usd',
			'order_reference'  => $order_reference,
			'customer'         => $customer,
			'shipping_address' => $shipping_address,
			'line_items'       => array_values( $line_items ),
			'channel'          => $meta['channel'] ?? 'woocommerce',
			'attribution'      => $meta['attribution'] ?? new stdClass(),
			'coupon_code'      => $meta['coupon_code'] ?? '',
			'discount_cents'   => $meta['discount_cents'] ?? 0,
		);
		// Omit entirely rather than sending an empty string - '' isn't the same as "no note".
		if ( '' !== $note ) {
			$body['note'] = $note;
		}
		return $this->post( $this->api_base . '/v1/orders', $body, $idempotency_key );
	}

	/**
	 * List the brand's active coupons (for syncing into WooCommerce).
	 *
	 * @return array|WP_Error
	 */
	public function get_coupons() {
		$response = wp_remote_get(
			$this->api_base . '/v1/coupons',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'compound_api_error', sprintf( 'List coupons returned %d.', $code ) );
		}
		return is_array( $decoded ) && isset( $decoded['coupons'] ) ? $decoded['coupons'] : array();
	}

	/**
	 * Create a charge against an existing Compound order. The routing engine picks
	 * the processor; the response is terminal for card (captured|declined).
	 *
	 * @param string $order_id        Compound order id.
	 * @param int    $amount_cents    Amount in cents (must match the order).
	 * @param string $idempotency_key Stable per-charge key.
	 * @param string $method_type     Rail the shopper chose: card, ach, or crypto.
	 * @param array  $payment_method  Opaque, tokenized funding-source data.
	 * @return array|WP_Error Decoded charge on success.
	 */
	public function create_charge( string $order_id, int $amount_cents, string $idempotency_key, string $method_type = 'card', array $payment_method = array() ) {
		$body = array(
			'order_id'    => $order_id,
			'amount'      => $amount_cents,
			'method_type' => $method_type,
		);
		if ( $payment_method ) {
			$body['payment_method'] = $payment_method;
		}
		return $this->post(
			$this->api_base . '/v1/charges',
			$body,
			$idempotency_key
		);
	}

	/**
	 * POST JSON with the brand API key. Returns the decoded body, or a WP_Error whose
	 * message is the Compound error envelope's message when present.
	 *
	 * @param string $url Absolute endpoint URL.
	 * @param array  $body Request payload, JSON-encoded before sending.
	 * @param string $idempotency_key Stable key so a retry cannot double-charge.
	 * @return array|WP_Error
	 */
	private function post( string $url, array $body, string $idempotency_key ) {
		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'Authorization'   => 'Bearer ' . $this->api_key,
				'Idempotency-Key' => $idempotency_key,
			),
			'body'    => wp_json_encode( $body ),
		);

		$response = wp_remote_post( $url, $args );

		// is_wp_error here means the request never got an HTTP response at all (DNS, TCP,
		// TLS, timeout - e.g. the "Could not resolve host" class of failure) - never a
		// decline or a validation error, which Compound always answers with a real status
		// code instead. Retry once: the same Idempotency-Key makes this safe even if the
		// first attempt actually reached Compound and only the response was lost in transit
		// (Compound non-negotiable: "same key + same body returns the original result").
		if ( is_wp_error( $response ) ) {
			usleep( 500000 );
			$response = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			WC_Compound_Sentry::report(
				'request failed after retry: ' . $response->get_error_message(),
				array( 'url' => $url )
			);
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) && isset( $decoded['error'] )
				? ( is_array( $decoded['error'] ) ? ( $decoded['error']['message'] ?? 'Request failed.' ) : $decoded['error'] )
				: sprintf( 'Compound API returned %d.', $code );
			return new WP_Error( 'compound_api_error', $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $decoded ) ) {
			// A 2xx with a body that isn't valid JSON - not a request failure by status code,
			// but the caller still gets an empty array back and would otherwise never know.
			WC_Compound_Sentry::report(
				'Compound API returned a non-JSON 2xx body',
				array(
					'url'    => $url,
					'status' => $code,
				)
			);
			return array();
		}

		return $decoded;
	}
}

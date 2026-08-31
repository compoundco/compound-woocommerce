<?php
/**
 * HTTP client for the Gen Health V2 Client API (api.gen-health.app). Server-side only; the
 * client API key never reaches the browser. Mirrors class-wc-compound-api.php's shape
 * (constructor + private request wrapper + WP_Error on failure + Sentry reporting), but
 * Gen Health authenticates with X-API-Key rather than a bearer token, and has no
 * Idempotency-Key header convention - POST /v2/client/orders is idempotent on its own
 * (transactionId, or an in-flight order for the same patient+product is simply returned).
 *
 * Deliberately narrow: only what this plugin actually uses (patient creation, starting a
 * consult, reading its status, reading a prescription). Gen Health's API is much larger
 * (its own payments, pharmacy pairing, subscriptions) - none of that is called here by
 * design, since Compound already owns payment collection and pharmacy routing.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_API {

	private string $api_base;
	private string $api_key;

	public function __construct( string $api_base, string $api_key ) {
		$this->api_base = untrailingslashit( $api_base );
		$this->api_key  = $api_key;
	}

	/**
	 * Create (or reuse, if this email already exists for this client) a patient record.
	 * Intake's clinical fields (allergies, current medications, medical conditions) travel
	 * here directly - Gen Health accepts them on patient creation, not as a separate form.
	 *
	 * @param array $patient Associative array: email, firstName, lastName, phone,
	 *                        dateOfBirth, address {street1, city, state, zip}, allergies[],
	 *                        currentMedications[], medicalConditions[].
	 * @return array|WP_Error Decoded { patientId, ... } on success.
	 */
	public function create_patient( array $patient ) {
		return $this->post(
			$this->api_base . '/v2/client/patients',
			array(
				'patient'    => $patient,
				'send_email' => false,
			)
		);
	}

	/**
	 * Start a consult for an existing patient against one Gen Health product. payment_status
	 * is always "unpaid" - Compound/WooCommerce collects payment; Gen Health is never asked
	 * to charge the patient (the clientCollectedProductPayment seam in their API).
	 *
	 * @param string $patient_id       Gen Health patientId (or partnerPatientId).
	 * @param string $client_product_id Gen Health clientProductId for the medication requested.
	 * @return array|WP_Error Decoded { orderId, orderStatus, ... } on success.
	 */
	public function create_order( string $patient_id, string $client_product_id ) {
		return $this->post(
			$this->api_base . '/v2/client/orders',
			array(
				'patient_id' => $patient_id,
				'order'      => array(
					'clientProductId' => $client_product_id,
					'payment_status'  => 'unpaid',
				),
				'send_email' => false,
			)
		);
	}

	/**
	 * Read a consult order back - used by the cron poll to check whether it has resolved,
	 * and carries the prescriptions[] array once a provider has acted.
	 *
	 * @param string $order_id Gen Health orderId.
	 * @return array|WP_Error
	 */
	public function get_order( string $order_id ) {
		return $this->get( $this->api_base . '/v2/client/orders/' . rawurlencode( $order_id ) );
	}

	/**
	 * Read one prescription - used on demand (never cached) to fetch a fresh, short-lived
	 * signed PDF URL for an admin viewing it. Never called for anything else.
	 *
	 * @param string $prescription_id Gen Health prescriptionId.
	 * @return array|WP_Error
	 */
	public function get_prescription( string $prescription_id ) {
		return $this->get( $this->api_base . '/v2/client/prescriptions/' . rawurlencode( $prescription_id ) );
	}

	/**
	 * POST JSON with the client API key. Returns the decoded `data` object, or a WP_Error
	 * whose message is Gen Health's error envelope message when present.
	 *
	 * @param string $url  Absolute endpoint URL.
	 * @param array  $body Request payload, JSON-encoded before sending.
	 * @return array|WP_Error
	 */
	private function post( string $url, array $body ) {
		return $this->request( 'POST', $url, $body );
	}

	/**
	 * GET with the client API key. Same response handling as post().
	 *
	 * @param string $url Absolute endpoint URL.
	 * @return array|WP_Error
	 */
	private function get( string $url ) {
		return $this->request( 'GET', $url, null );
	}

	/**
	 * Shared request/response handling for get() and post().
	 *
	 * @param string     $method 'GET' or 'POST'.
	 * @param string     $url    Absolute endpoint URL.
	 * @param array|null $body   Request payload for POST; null for GET.
	 * @return array|WP_Error
	 */
	private function request( string $method, string $url, ?array $body ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'X-API-Key' => $this->api_key,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		// Same "transport failure, never a real answer" retry as the Compound client - a
		// single retry, safe here too since Gen Health's own idempotency (transactionId /
		// in-flight-order matching) makes a resend harmless.
		if ( is_wp_error( $response ) ) {
			usleep( 500000 );
			$response = wp_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			WC_Compound_Sentry::report(
				'Gen Health request failed after retry: ' . $response->get_error_message(),
				array( 'url' => $url )
			);
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) && isset( $decoded['error'] )
				? ( is_array( $decoded['error'] ) ? ( $decoded['error']['message'] ?? 'Request failed.' ) : $decoded['error'] )
				: sprintf( 'Gen Health API returned %d.', $code );
			return new WP_Error( 'gen_health_api_error', $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			WC_Compound_Sentry::report(
				'Gen Health API returned a 2xx body with no data envelope',
				array(
					'url'    => $url,
					'status' => $code,
				)
			);
			return array();
		}

		return $decoded['data'];
	}
}

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
	 * @param string $note                  Customer-provided checkout note (optional).
	 * @param string $storefront_order_url  Deep link to this order in wp-admin (optional) -
	 *                                      shown as a link in the Compound brand portal.
	 * @return array|WP_Error Decoded order on success.
	 */
	public function create_order( array $line_items, int $amount_cents, array $customer, array $shipping_address, string $order_reference, string $idempotency_key, array $meta = array(), string $note = '', string $storefront_order_url = '' ) {
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
		if ( '' !== $storefront_order_url ) {
			$body['storefront_order_url'] = $storefront_order_url;
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
	/**
	 * Starts a pay-by-bank linking session. Compound creates the session with its own (or the
	 * brand's) processor credential and returns a URL for the provider's hosted flow; this
	 * plugin never holds a processor credential and never sees an account number.
	 *
	 * @param string $first_name   Customer first name.
	 * @param string $last_name    Customer last name.
	 * @param string $email        Customer email.
	 * @param string $redirect_url Where the customer returns after linking. Must be https.
	 * @param int    $amount_cents Optional order amount, shown on the provider's pay screen.
	 * @return array|WP_Error {session_url}
	 */
	public function paybybank_session( string $first_name, string $last_name, string $email, string $redirect_url, int $amount_cents = 0 ) {
		$body = array(
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'email'        => $email,
			'redirect_url' => $redirect_url,
		);
		if ( $amount_cents > 0 ) {
			$body['amount'] = $amount_cents;
		}
		return $this->post( $this->api_base . '/v1/paybybank/sessions', $body, '' );
	}

	/**
	 * Records the bank link after the customer returns from their bank. Compound verifies the
	 * customer id against the provider before storing it, so a wrong or forged id is refused
	 * there rather than trusted here.
	 *
	 * @param string $email       Customer email the link belongs to.
	 * @param string $customer_id Provider customer id, from the redirect's query parameters.
	 * @param string $link_token   Compound's token binding this link to the session that
	 *                             started it. Required: the provider returns no email, so this
	 *                             is what proves the id belongs to this shopper.
	 * @return array|WP_Error {linked, status, bank_account_token, bank_name, account_last4}
	 */
	public function paybybank_link( string $email, string $customer_id, string $link_token = '' ) {
		return $this->post(
			$this->api_base . '/v1/paybybank/link',
			array(
				'email'       => $email,
				'customer_id' => $customer_id,
				'link_token'  => $link_token,
			),
			''
		);
	}

	/**
	 * Whether this customer already has a usable linked bank account, so a returning customer
	 * can pay without being sent back through their bank.
	 *
	 * @param string $email Customer email.
	 * @return array|WP_Error {linked, status, bank_account_token, bank_name, account_last4}
	 */
	public function paybybank_customer( string $email ) {
		return $this->get( $this->api_base . '/v1/paybybank/customers/' . rawurlencode( $email ) );
	}

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
	 * Refund a captured charge, in full (omit $amount_cents) or in part. Real money movement
	 * through Compound - never a WooCommerce-side status flip. Idempotent on $idempotency_key,
	 * same as every other mutating call here.
	 *
	 * @param string   $charge_id       Compound charge id (order meta _compound_charge_id).
	 * @param int|null $amount_cents    Partial amount in cents, or null for the full remaining balance.
	 * @param string   $reason          Free-text reason, recorded on the refund.
	 * @param string   $idempotency_key Stable key so a retry can't double-refund.
	 * @return array|WP_Error Decoded charge (with its refund history) on success.
	 */
	public function refund_charge( string $charge_id, ?int $amount_cents, string $reason, string $idempotency_key ) {
		$body = array( 'reason' => $reason );
		if ( null !== $amount_cents ) {
			$body['amount'] = $amount_cents;
		}
		return $this->post(
			$this->api_base . '/v1/charges/' . rawurlencode( $charge_id ) . '/refund',
			$body,
			$idempotency_key
		);
	}

	/**
	 * Whether telemedicine is enabled for this brand (set in the Compound admin portal -
	 * this plugin never configures it). Callers should cache this (see
	 * WC_Gen_Health_Settings::is_active()) rather than calling it on every page load.
	 *
	 * @return array|WP_Error {enabled: bool}
	 */
	public function telemedicine_config() {
		return $this->get( $this->api_base . '/v1/telemedicine/config' );
	}

	/**
	 * Submit a health intake and start a consult. Compound forwards this to its telehealth
	 * provider and returns only a pointer + status - nothing sent here is stored back on this
	 * request beyond that pointer (see the telemedicine plan). Never blocks or reverses
	 * account creation on failure - the caller decides how to handle a WP_Error.
	 *
	 * @param array  $payload         {customer:{email}, product_sku, consult_type, first_name,
	 *                                 last_name, phone, date_of_birth, address, allergies?,
	 *                                 medications?, conditions?}.
	 * @param string $idempotency_key Stable key so a retry can't start a second consult.
	 * @return array|WP_Error {consult_id, status}
	 */
	public function telemedicine_intake( array $payload, string $idempotency_key ) {
		return $this->post( $this->api_base . '/v1/telemedicine/intake', $payload, $idempotency_key );
	}

	/**
	 * The brand's configured intake questions. Compound owns this configuration (the brand
	 * edits it in the Compound portal), so the storefront renders whatever comes back rather
	 * than shipping its own idea of what to ask.
	 *
	 * @return array|WP_Error {questions: array[]}
	 */
	public function telemedicine_intake_form() {
		return $this->get( $this->api_base . '/v1/telemedicine/intake-form' );
	}

	/**
	 * The screening questionnaire a SKU asks when it goes in the cart, and the customer's
	 * previous answers for it so a reorder is confirmed rather than retyped.
	 *
	 * Returns an empty question list rather than an error when the product has none, which is
	 * the common case: most products need nothing beyond the registration intake.
	 *
	 * @param string $sku   The product's Compound SKU.
	 * @param string $email Customer's account email, for the pre-fill. Optional.
	 * @return array|WP_Error {questionnaire_id, name, questions: array[], previous_answers: array[]}
	 */
	public function telemedicine_questionnaire( string $sku, string $email = '' ) {
		$url = $this->api_base . '/v1/telemedicine/questionnaire?product_sku=' . rawurlencode( $sku );
		if ( '' !== $email ) {
			$url .= '&email=' . rawurlencode( $email );
		}
		return $this->get( $url );
	}

	/**
	 * A customer's consults (status + fills remaining), most recent first.
	 *
	 * @param string $email Customer's account email.
	 * @return array|WP_Error {consults: array[]}
	 */
	public function telemedicine_consults( string $email ) {
		return $this->get( $this->api_base . '/v1/telemedicine/consults?email=' . rawurlencode( $email ) );
	}

	/**
	 * Live detail for one consult (medication, prescriber, prescription file link) - never
	 * cached on either side, matching how the prescription file itself is always fetched fresh.
	 *
	 * @param string $consult_id Compound consult id.
	 * @return array|WP_Error
	 */
	public function telemedicine_consult_detail( string $consult_id ) {
		return $this->get( $this->api_base . '/v1/telemedicine/consults/' . rawurlencode( $consult_id ) . '/detail' );
	}

	/**
	 * Sandbox-only: resolve a consult by hand instead of waiting on real clinical review -
	 * exercises the exact same fills/refund logic the real poll uses. Compound itself refuses
	 * this outside sandbox.
	 *
	 * @param string $consult_id Compound consult id.
	 * @param string $outcome    'approved' or 'denied'.
	 * @param int    $refills    Refills to grant on approval (ignored for a denial).
	 * @return array|WP_Error
	 */
	public function telemedicine_dev_resolve( string $consult_id, string $outcome, int $refills = 2 ) {
		$response = wp_remote_post(
			$this->api_base . '/v1/telemedicine/consults/' . rawurlencode( $consult_id ) . '/dev/resolve',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode(
					array(
						'outcome' => $outcome,
						'refills' => $refills,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
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
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * GET with the brand API key. Same decode/error handling as post().
	 *
	 * @param string $url Absolute endpoint URL.
	 * @return array|WP_Error
	 */
	private function get( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			WC_Compound_Sentry::report( 'request failed: ' . $response->get_error_message(), array( 'url' => $url ) );
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
		return is_array( $decoded ) ? $decoded : array();
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

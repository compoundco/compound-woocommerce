<?php
/**
 * Pay by bank (open banking ACH) at checkout.
 *
 * The customer authenticates with their own bank inside the provider's hosted flow, so no
 * account or routing number ever reaches this plugin, the merchant's site, or Compound. What
 * comes back is an opaque customer reference, and that reference is what a later debit names.
 *
 * The link happens BEFORE the order is placed rather than as a redirect out of
 * process_payment. Two reasons: the customer leaves the site to authenticate with their bank,
 * and an order placed first would be stranded if they never came back; and a returning
 * customer already has a usable link, so this rail should just work for them with no
 * redirect at all, which is most of the point of pay by bank.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_PayByBank {

	const METHOD            = 'pay_by_bank';
	const SESSION_TOKEN_KEY = 'compound_pbb_token';
	/** Compound's token binding a bank link to the session that started it, and its email. */
	const LINK_TOKEN_KEY = 'compound_pbb_link_token';
	const LINK_EMAIL_KEY = 'compound_pbb_link_email';

	public function register(): void {
		add_action( 'wp_ajax_compound_pbb_session', array( $this, 'ajax_session' ) );
		add_action( 'wp_ajax_nopriv_compound_pbb_session', array( $this, 'ajax_session' ) );
		add_action( 'wp_ajax_compound_pbb_link', array( $this, 'ajax_link' ) );
		add_action( 'wp_ajax_nopriv_compound_pbb_link', array( $this, 'ajax_link' ) );
	}

	/** Settings for the Compound gateway, read without needing a gateway instance. */
	private static function settings(): array {
		$opts = get_option( 'woocommerce_compound_settings', array() );
		return is_array( $opts ) ? $opts : array();
	}

	private static function api(): WC_Compound_API {
		$o = self::settings();
		return new WC_Compound_API( (string) ( $o['api_base'] ?? '' ), (string) ( $o['api_key'] ?? '' ) );
	}

	/** The email this checkout is for: the posted billing email, else the logged-in account. */
	private static function checkout_email(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- every caller is an
		// AJAX handler that ran check_ajax_referer() before reaching this.
		$posted = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $posted ) {
			return $posted;
		}
		return is_user_logged_in() ? (string) wp_get_current_user()->user_email : '';
	}

	/**
	 * An already-linked bank for this customer, or null. Used to skip the bank flow entirely
	 * on a reorder, which is the difference between pay by bank being convenient and being a
	 * chore.
	 *
	 * @param string $email Customer email.
	 * @return array|null {bank_account_token, bank_name, account_last4}
	 */
	public static function existing_link( string $email ): ?array {
		if ( '' === $email ) {
			return null;
		}
		$result = self::api()->paybybank_customer( $email );
		if ( is_wp_error( $result ) || empty( $result['linked'] ) || empty( $result['bank_account_token'] ) ) {
			return null;
		}
		return array(
			'bank_account_token' => (string) $result['bank_account_token'],
			'bank_name'          => (string) ( $result['bank_name'] ?? '' ),
			'account_last4'      => (string) ( $result['account_last4'] ?? '' ),
		);
	}

	/** Starts a linking session and hands the storefront the provider's session URL. */
	public function ajax_session(): void {
		check_ajax_referer( 'compound_pbb', 'nonce' );
		$email = self::checkout_email();
		if ( '' === $email ) {
			wp_send_json_error( array( 'message' => __( 'Enter your email address first.', 'compound-woocommerce' ) ), 400 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $first || '' === $last ) {
			wp_send_json_error( array( 'message' => __( 'Enter your name first.', 'compound-woocommerce' ) ), 400 );
		}

		// No cart total passed here - see the note on WC_Compound_API::paybybank_session().
		// This step only links a bank account; the real, tracked charge happens separately
		// when the order is actually placed.
		$result = self::api()->paybybank_session( $first, $last, $email, wc_get_checkout_url() );
		if ( is_wp_error( $result ) ) {
			WC_Compound_Sentry::report( 'paybybank_session failed: ' . $result->get_error_message(), array( 'email' => $email ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}
		if ( empty( $result['session_url'] ) ) {
			WC_Compound_Sentry::report( 'paybybank_session failed: no session_url', array( 'email' => $email ) );
			wp_send_json_error( array( 'message' => __( 'Could not start bank linking. Please try again.', 'compound-woocommerce' ) ), 502 );
		}
		// Compound issues a token binding this session to this email, and requires it back when
		// the customer returns. Kept server-side in the WooCommerce session rather than handed
		// to the browser: the browser has no use for it, and the round trip it would take is
		// exactly the one the token exists to make untrustworthy.
		if ( ! empty( $result['link_token'] ) && WC()->session ) {
			WC()->session->set( self::LINK_TOKEN_KEY, (string) $result['link_token'] );
			WC()->session->set( self::LINK_EMAIL_KEY, $email );
		}
		wp_send_json_success( array( 'session_url' => (string) $result['session_url'] ) );
	}

	/**
	 * Records the link once the customer is back.
	 *
	 * The provider returns its customer id in the redirect's query parameters, so it arrives
	 * through the browser and cannot be taken on trust. Link Money's customer read carries no
	 * email to check it against, so Compound binds the link to the session it issued instead:
	 * the token stored when the session started goes back with the id, and a link for an email
	 * that never started a session is refused there rather than believed here.
	 */
	public function ajax_link(): void {
		check_ajax_referer( 'compound_pbb', 'nonce' );
		$email = self::checkout_email();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$customer_id = isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '';
		if ( '' === $email || '' === $customer_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not confirm the bank link.', 'compound-woocommerce' ) ), 400 );
		}

		$token = WC()->session ? (string) WC()->session->get( self::LINK_TOKEN_KEY, '' ) : '';
		$for   = WC()->session ? (string) WC()->session->get( self::LINK_EMAIL_KEY, '' ) : '';
		// The email may have been edited on the checkout form after the session started, in
		// which case the token is for the wrong address and Compound would refuse it. Say so
		// here rather than surfacing that as a generic failure.
		if ( '' === $token || strtolower( $for ) !== strtolower( $email ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Start bank linking again: your email changed since you began.', 'compound-woocommerce' ) ),
				400
			);
		}

		$result = self::api()->paybybank_link( $email, $customer_id, $token );
		if ( is_wp_error( $result ) ) {
			// Compound's own error messages are already written to be shown (state-facts,
			// name-the-real-cause), so this is surfaced rather than replaced with a generic
			// string that turns every distinct failure into the same unhelpful line.
			WC_Compound_Sentry::report( 'paybybank_link failed: ' . $result->get_error_message(), array( 'email' => $email ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		if ( empty( $result['bank_account_token'] ) ) {
			// A real response, just not one that can be charged yet: Link Money reports the
			// account as still activating rather than a failure. Distinct from the error case
			// above, so a shopper is told to wait rather than that something went wrong.
			wp_send_json_error(
				array( 'message' => __( 'Your bank is still confirming this account. Wait a moment and try linking again.', 'compound-woocommerce' ) ),
				409
			);
		}
		wp_send_json_success(
			array(
				'bank_account_token' => (string) $result['bank_account_token'],
				'bank_name'          => (string) ( $result['bank_name'] ?? '' ),
				'account_last4'      => (string) ( $result['account_last4'] ?? '' ),
			)
		);
	}

	/**
	 * Checkout markup for this rail: either the account the customer already linked, or the
	 * provider's own button. The button is theirs by design, both for brand consistency and
	 * because it is what opens their hosted flow.
	 *
	 * @param string $email Customer email, when known.
	 */
	public static function render_field( string $email ): void {
		$existing = self::existing_link( $email );
		$sandbox  = 'sandbox' === ( self::settings()['environment'] ?? 'sandbox' );
		?>
		<div
			class="compound-pbb"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'compound_pbb' ) ); ?>"
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-environment="<?php echo esc_attr( $sandbox ? 'sandbox' : 'production' ); ?>"
		>
			<input
				type="hidden"
				name="compound_pbb_token"
				class="compound-pbb__token"
				value="<?php echo esc_attr( $existing['bank_account_token'] ?? '' ); ?>"
			/>
			<p class="compound-pbb__status">
				<?php if ( $existing ) : ?>
					<?php
					$label = trim( ( $existing['bank_name'] ?? '' ) . ' ' . ( $existing['account_last4'] ? '••••' . $existing['account_last4'] : '' ) );
					echo esc_html( '' !== $label ? $label : __( 'Your bank account is linked.', 'compound-woocommerce' ) );
					?>
				<?php else : ?>
					<?php esc_html_e( 'Link your bank to pay directly from your account.', 'compound-woocommerce' ); ?>
				<?php endif; ?>
			</p>
			<div class="compound-pbb__button">
				<?php if ( ! $existing ) : ?>
					<?php
					// Rendered here rather than created by the script. A button a shopper can
					// see and click, that then reports a failure, beats an empty div that looks
					// like the feature is missing. The script binds to it.
					?>
					<button type="button" class="button compound-pbb__start">
						<?php esc_html_e( 'Link your bank', 'compound-woocommerce' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

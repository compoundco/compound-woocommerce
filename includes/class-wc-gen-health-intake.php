<?php
/**
 * Health intake and the start of the first consult. Primarily collected AS PART OF
 * registration itself (fields injected into WooCommerce's own account-creation form via
 * `woocommerce_register_form`, validated via `woocommerce_process_registration_errors` before
 * the account is even created) - the My Account "Health intake" tab is a fallback for an
 * account that ended up without intake some other way (created before telemedicine was
 * enabled, created outside the storefront register form, etc.), not the primary path anymore.
 *
 * Deliberately does NOT gate checkout or fulfillment in any way - a customer can buy a
 * telehealth-gated product before a consult resolves; the only consequence of a denied
 * consult is a refund, handled entirely on the Compound side. This plugin never talks to the
 * telehealth provider directly and never stores intake content (name, DOB, address,
 * allergies, medications, conditions) - it's forwarded straight through to Compound
 * (WC_Compound_API::telemedicine_intake()) and Compound itself is pointer-only. There is
 * therefore no local "intake status" to track here anymore: status is always read live from
 * Compound (WC_Compound_API::telemedicine_consults()) when it needs to be shown.
 *
 * Hook order verified against WooCommerce core (templates/myaccount/form-login.php,
 * includes/class-wc-form-handler.php): `woocommerce_register_form` fires inside the
 * register `<form>`, right before the submit button; `woocommerce_process_registration_errors`
 * fires from WC_Form_Handler::process_registration() AFTER `woocommerce-register-nonce` has
 * already been verified, so reading $_POST directly in both is safe.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Intake {

	const ENDPOINT = 'gen-health-intake';

	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'admin_post_gen_health_submit_intake', array( $this, 'handle_submit' ) );

		// Primary path: collected as part of registration itself.
		add_action( 'woocommerce_register_form', array( $this, 'render_registration_fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_registration_fields' ), 10, 4 );
		add_action( 'woocommerce_created_customer', array( $this, 'on_customer_created' ), 10, 1 );
	}

	/**
	 * Relies on the deploy pipeline's existing `wp rewrite flush --hard` (provision.sh.tftpl)
	 * to pick this up - a fresh local install may need Settings -> Permalinks -> Save once.
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function add_menu_item( array $items ): array {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return $items;
		}
		// Insert before "Logout" (last item) so it doesn't get buried at the very end.
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'Health intake', 'compound-woocommerce' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Renders the same intake fields as the My Account tab, inline inside WooCommerce's own
	 * registration form. Skipped when there are no gated products yet - a required field with
	 * no real option to pick would trap every registration on a store still being configured.
	 */
	public function render_registration_fields(): void {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return;
		}
		$products = $this->gated_products();
		if ( empty( $products ) ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Health intake', 'compound-woocommerce' ) . '</h3>';
		$this->render_fields( $products );
	}

	/**
	 * Blocks account creation until intake is complete, when telemedicine is active and at
	 * least one product is gated (mirrors render_registration_fields()'s own guard, so
	 * validation never requires fields that were never shown).
	 *
	 * @param WP_Error $errors   Accumulated registration errors so far.
	 * @param string   $username Chosen or generated username (unused; required by the filter signature).
	 * @param string   $password Chosen or generated password (unused; required by the filter signature).
	 * @param string   $email    The submitted email (unused; required by the filter signature).
	 * @return WP_Error
	 */
	public function validate_registration_fields( $errors, $username, $password, $email ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! WC_Gen_Health_Settings::is_active() || empty( $this->gated_products() ) ) {
			return $errors;
		}
		$required = array(
			'product_sku'   => __( 'Choose what you are interested in.', 'compound-woocommerce' ),
			'first_name'    => __( 'First name is required.', 'compound-woocommerce' ),
			'last_name'     => __( 'Last name is required.', 'compound-woocommerce' ),
			'phone'         => __( 'Phone is required.', 'compound-woocommerce' ),
			'date_of_birth' => __( 'Date of birth is required.', 'compound-woocommerce' ),
			'street1'       => __( 'Street address is required.', 'compound-woocommerce' ),
			'city'          => __( 'City is required.', 'compound-woocommerce' ),
			'state'         => __( 'State is required.', 'compound-woocommerce' ),
			'zip'           => __( 'ZIP is required.', 'compound-woocommerce' ),
		);
		foreach ( $required as $field => $message ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce-register-nonce before calling this filter (WC_Form_Handler::process_registration()).
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			if ( '' === $value ) {
				$errors->add( 'gen_health_' . $field, $message );
			}
		}
		return $errors;
	}

	/**
	 * New account created. If intake fields came in with this same registration submission
	 * (the normal case once validate_registration_fields() has required them), submit intake
	 * immediately. A failure here never rolls back the account - the customer can retry from
	 * the My Account tab fallback, which will simply show no consult on file (Compound is the
	 * only source of truth; there's nothing local to leave inconsistent).
	 *
	 * @param int $customer_id WordPress user id of the new customer.
	 */
	public function on_customer_created( int $customer_id ): void {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class doc comment.
		$product_sku = isset( $_POST['product_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['product_sku'] ) ) : '';
		if ( '' === $product_sku ) {
			return;
		}
		$user = get_userdata( $customer_id );
		$this->submit_intake( $user ? $user->user_email : '', $product_sku );
	}

	public function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			echo '<p>' . esc_html__( 'Telemedicine is not currently enabled.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		$email    = wp_get_current_user()->user_email;
		$consults = WC_Gen_Health_Settings::api()->telemedicine_consults( $email );
		if ( ! is_wp_error( $consults ) && ! empty( $consults['consults'] ) ) {
			echo '<p>' . esc_html__( 'Your health intake is on file.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		if ( isset( $_GET['gen_health_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="woocommerce-error">' . esc_html( sanitize_text_field( wp_unslash( $_GET['gen_health_error'] ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gen_health_submit_intake" />
			<?php wp_nonce_field( 'gen_health_submit_intake' ); ?>
			<?php $this->render_fields( $this->gated_products() ); ?>
			<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Submit intake', 'compound-woocommerce' ); ?></button>
		</form>
		<?php
	}

	/**
	 * The intake fields themselves - shared markup between the My Account tab (wrapped in its
	 * own `<form>` above) and the registration form (already inside WooCommerce's own `<form>`,
	 * via render_registration_fields()). The product select's value is the WooCommerce SKU
	 * (product_sku) - this plugin resolves it to the opaque consult type server-side when
	 * building the request, so nothing Gen-Health-shaped ever appears in markup or $_POST.
	 *
	 * @param WC_Product[] $products Gated products to offer in the "what are you interested in" select.
	 */
	private function render_fields( array $products ): void {
		// compound-wc-field: the hook the plugin's own base styles (100% width, box-sizing)
		// and a merchant's custom CSS (Settings -> Payments -> Compound -> Custom CSS) both
		// target - see class-wc-compound-styles.php.
		?>
		<p class="compound-wc-field">
			<label for="gen_health_product_sku"><?php esc_html_e( 'What are you interested in?', 'compound-woocommerce' ); ?></label><br />
			<select name="product_sku" id="gen_health_product_sku" required>
				<option value=""><?php esc_html_e( 'Select a product', 'compound-woocommerce' ); ?></option>
				<?php foreach ( $products as $product ) : ?>
					<option value="<?php echo esc_attr( $product->get_sku() ); ?>">
						<?php echo esc_html( $product->get_name() ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="compound-wc-field"><label><?php esc_html_e( 'First name', 'compound-woocommerce' ); ?><br /><input type="text" name="first_name" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'Last name', 'compound-woocommerce' ); ?><br /><input type="text" name="last_name" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'Phone', 'compound-woocommerce' ); ?><br /><input type="tel" name="phone" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'Date of birth', 'compound-woocommerce' ); ?><br /><input type="date" name="date_of_birth" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'Street address', 'compound-woocommerce' ); ?><br /><input type="text" name="street1" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'City', 'compound-woocommerce' ); ?><br /><input type="text" name="city" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'State', 'compound-woocommerce' ); ?><br /><input type="text" name="state" maxlength="2" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'ZIP', 'compound-woocommerce' ); ?><br /><input type="text" name="zip" required /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'Known allergies (comma-separated, optional)', 'compound-woocommerce' ); ?><br /><input type="text" name="allergies" /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'Current medications (comma-separated, optional)', 'compound-woocommerce' ); ?><br /><input type="text" name="current_medications" /></label></p>
		<p class="compound-wc-field"><label><?php esc_html_e( 'Medical conditions (comma-separated, optional)', 'compound-woocommerce' ); ?><br /><input type="text" name="medical_conditions" /></label></p>
		<?php
	}

	public function handle_submit(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in.', 'compound-woocommerce' ) );
		}
		check_admin_referer( 'gen_health_submit_intake' );
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			wp_die( esc_html__( 'Telemedicine is not currently enabled.', 'compound-woocommerce' ) );
		}

		$product_sku = isset( $_POST['product_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['product_sku'] ) ) : '';
		if ( '' === $product_sku ) {
			$this->redirect_with_error( __( 'Choose what you are interested in.', 'compound-woocommerce' ) );
		}

		$result = $this->submit_intake( wp_get_current_user()->user_email, $product_sku );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Resolve the product's consult type and forward the current $_POST as an intake to
	 * Compound. Shared by the registration-time path (on_customer_created()) and the My
	 * Account tab fallback (handle_submit()) - both read the same field names. Compound is
	 * pointer-only: nothing from this submission is stored back here.
	 *
	 * @param string $email       The account's email (patient creation requires it directly -
	 *                            not read from $_POST, since at registration time the account
	 *                            may not be the logged-in user yet).
	 * @param string $product_sku WooCommerce SKU the intake is for.
	 * @return true|WP_Error
	 */
	private function submit_intake( string $email, string $product_sku ) {
		$product_id = wc_get_product_id_by_sku( $product_sku );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return new WP_Error( 'gen_health_unknown_product', __( 'That product could not be found. Please contact support.', 'compound-woocommerce' ) );
		}
		$consult_type = WC_Gen_Health_Product_Meta::consult_type( $product );
		$consult_kind = WC_Gen_Health_Product_Meta::consult_kind( $product );

		$session_token   = wp_get_session_token();
		$idempotency_key = 'gen-health-intake-' . md5( $email . '|' . $product_sku . '|' . ( $session_token ? $session_token : (string) time() ) );
		$result          = WC_Gen_Health_Settings::api()->telemedicine_intake( $this->payload_from_post( $email, $product_sku, $consult_type, $consult_kind ), $idempotency_key );
		if ( is_wp_error( $result ) ) {
			WC_Compound_Sentry::report(
				'telemedicine_intake failed: ' . $result->get_error_message(),
				array(
					'email'       => $email,
					'product_sku' => $product_sku,
				)
			);
			return new WP_Error( 'gen_health_intake_failed', __( 'We could not submit your intake. Please try again.', 'compound-woocommerce' ) );
		}
		return true;
	}

	/**
	 * Builds the intake payload for Compound from the current $_POST. Called only after a
	 * nonce has already been verified by the caller (WooCommerce's own woocommerce-register-nonce
	 * for registration, or gen_health_submit_intake for the My Account tab) - see class doc
	 * comment - hence the phpcs:ignores below rather than re-checking a nonce here.
	 *
	 * @param string $email        The patient's email.
	 * @param string $product_sku  WooCommerce SKU the intake is for.
	 * @param string $consult_type Opaque consult-type id resolved from the product.
	 * @param string $consult_kind Whether this is a good faith exam or an exam + prescription.
	 * @return array
	 */
	private function payload_from_post( string $email, string $product_sku, string $consult_type, string $consult_kind ): array {
		return array(
			'customer'      => array( 'email' => $email ),
			'product_sku'   => $product_sku,
			'consult_type'  => $consult_type,
			'consult_kind'  => $consult_kind,
			'first_name'    => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'last_name'     => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'phone'         => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'date_of_birth' => sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'address'       => array(
				'street1' => sanitize_text_field( wp_unslash( $_POST['street1'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'city'    => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'state'   => sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'zip'     => sanitize_text_field( wp_unslash( $_POST['zip'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			),
			'allergies'     => $this->csv_to_list( sanitize_text_field( wp_unslash( $_POST['allergies'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'medications'   => $this->csv_to_list( sanitize_text_field( wp_unslash( $_POST['current_medications'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'conditions'    => $this->csv_to_list( sanitize_text_field( wp_unslash( $_POST['medical_conditions'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
	}

	private function redirect_with_error( string $message ): void {
		wp_safe_redirect( add_query_arg( 'gen_health_error', rawurlencode( $message ), wc_get_account_endpoint_url( self::ENDPOINT ) ) );
		exit;
	}

	/**
	 * Every published product - telemedicine has no per-product opt-in, the brand-level
	 * toggle (checked by every caller of this method before calling it) is the only gate.
	 *
	 * @return WC_Product[]
	 */
	private function gated_products(): array {
		$ids = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
			)
		);
		return array_filter( array_map( 'wc_get_product', $ids ) );
	}

	/**
	 * Splits an already-sanitized comma-separated string into a trimmed, non-empty list.
	 *
	 * @param string $clean A value already passed through sanitize_text_field()/wp_unslash().
	 */
	private function csv_to_list( string $clean ): array {
		if ( '' === $clean ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $clean ) ) ) );
	}
}

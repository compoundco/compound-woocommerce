<?php
/**
 * Health intake, collected once at signup, and the start of the first Gen Health consult.
 * Deliberately does NOT gate checkout or fulfillment in any way - a customer can buy a
 * telehealth-gated product before intake is complete or before a consult resolves; the only
 * consequence of a denied consult is a refund (class-wc-gen-health-cron.php), never a block.
 *
 * New territory for this plugin: no existing hook into account creation, no existing My
 * Account custom endpoint (see the plan's exploration notes) - both built fresh here,
 * following WooCommerce's own extension points rather than inventing new ones.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Intake {

	const ENDPOINT           = 'gen-health-intake';
	const PATIENT_ID_META    = '_gen_health_patient_id';
	const INTAKE_STATUS_META = '_gen_health_intake_status'; // One of: required, submitted.

	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'flag_intake_required' ), 10, 1 );
		add_action( 'admin_post_gen_health_submit_intake', array( $this, 'handle_submit' ) );
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
	 * New account created - mark intake as required. Registration itself only collects
	 * email/username/password, never clinical data; the actual intake happens on the
	 * My Account tab above, in its own request.
	 *
	 * @param int $customer_id WordPress user id of the new customer.
	 */
	public function flag_intake_required( int $customer_id ): void {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return;
		}
		if ( ! get_user_meta( $customer_id, self::PATIENT_ID_META, true ) ) {
			update_user_meta( $customer_id, self::INTAKE_STATUS_META, 'required' );
		}
	}

	public function render(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			echo '<p>' . esc_html__( 'Telemedicine is not currently enabled.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		$patient_id = get_user_meta( $user_id, self::PATIENT_ID_META, true );
		if ( $patient_id ) {
			echo '<p>' . esc_html__( 'Your health intake is on file.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		if ( isset( $_GET['gen_health_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="woocommerce-error">' . esc_html( sanitize_text_field( wp_unslash( $_GET['gen_health_error'] ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$products = $this->gated_products();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gen_health_submit_intake" />
			<?php wp_nonce_field( 'gen_health_submit_intake' ); ?>

			<p>
				<label for="gen_health_client_product_id"><?php esc_html_e( 'What are you interested in?', 'compound-woocommerce' ); ?></label><br />
				<select name="client_product_id" id="gen_health_client_product_id" required>
					<option value=""><?php esc_html_e( 'Select a product', 'compound-woocommerce' ); ?></option>
					<?php foreach ( $products as $product ) : ?>
						<option value="<?php echo esc_attr( WC_Gen_Health_Product_Meta::client_product_id( $product ) ); ?>">
							<?php echo esc_html( $product->get_name() ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p><label><?php esc_html_e( 'First name', 'compound-woocommerce' ); ?><br /><input type="text" name="first_name" required /></label></p>
			<p><label><?php esc_html_e( 'Last name', 'compound-woocommerce' ); ?><br /><input type="text" name="last_name" required /></label></p>
			<p><label><?php esc_html_e( 'Phone', 'compound-woocommerce' ); ?><br /><input type="tel" name="phone" required /></label></p>
			<p><label><?php esc_html_e( 'Date of birth', 'compound-woocommerce' ); ?><br /><input type="date" name="date_of_birth" required /></label></p>
			<p><label><?php esc_html_e( 'Street address', 'compound-woocommerce' ); ?><br /><input type="text" name="street1" required /></label></p>
			<p><label><?php esc_html_e( 'City', 'compound-woocommerce' ); ?><br /><input type="text" name="city" required /></label></p>
			<p><label><?php esc_html_e( 'State', 'compound-woocommerce' ); ?><br /><input type="text" name="state" maxlength="2" required /></label></p>
			<p><label><?php esc_html_e( 'ZIP', 'compound-woocommerce' ); ?><br /><input type="text" name="zip" required /></label></p>
			<p><label><?php esc_html_e( 'Known allergies (comma-separated, optional)', 'compound-woocommerce' ); ?><br /><input type="text" name="allergies" /></label></p>
			<p><label><?php esc_html_e( 'Current medications (comma-separated, optional)', 'compound-woocommerce' ); ?><br /><input type="text" name="current_medications" /></label></p>
			<p><label><?php esc_html_e( 'Medical conditions (comma-separated, optional)', 'compound-woocommerce' ); ?><br /><input type="text" name="medical_conditions" /></label></p>

			<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Submit intake', 'compound-woocommerce' ); ?></button>
		</form>
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

		$user_id           = get_current_user_id();
		$client_product_id = isset( $_POST['client_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_product_id'] ) ) : '';
		if ( '' === $client_product_id ) {
			$this->redirect_with_error( __( 'Choose what you are interested in.', 'compound-woocommerce' ) );
		}

		$patient = array(
			'email'              => wp_get_current_user()->user_email,
			'firstName'          => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
			'lastName'           => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
			'phone'              => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'dateOfBirth'        => sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ?? '' ) ),
			'address'            => array(
				'street1' => sanitize_text_field( wp_unslash( $_POST['street1'] ?? '' ) ),
				'city'    => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
				'state'   => sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ),
				'zip'     => sanitize_text_field( wp_unslash( $_POST['zip'] ?? '' ) ),
			),
			'allergies'          => $this->csv_to_list( sanitize_text_field( wp_unslash( $_POST['allergies'] ?? '' ) ) ),
			'currentMedications' => $this->csv_to_list( sanitize_text_field( wp_unslash( $_POST['current_medications'] ?? '' ) ) ),
			'medicalConditions'  => array_map(
				static fn( string $name ) => array( 'name' => $name ),
				$this->csv_to_list( sanitize_text_field( wp_unslash( $_POST['medical_conditions'] ?? '' ) ) )
			),
		);

		$api    = WC_Gen_Health_Settings::api();
		$result = $api->create_patient( $patient );
		if ( is_wp_error( $result ) ) {
			WC_Compound_Sentry::report( 'Gen Health create_patient failed: ' . $result->get_error_message(), array( 'user_id' => $user_id ) );
			$this->redirect_with_error( __( 'We could not submit your intake. Please try again.', 'compound-woocommerce' ) );
		}

		$patient_id = (string) ( $result['patientId'] ?? '' );
		if ( '' === $patient_id ) {
			$this->redirect_with_error( __( 'We could not submit your intake. Please try again.', 'compound-woocommerce' ) );
		}

		update_user_meta( $user_id, self::PATIENT_ID_META, $patient_id );
		update_user_meta( $user_id, self::INTAKE_STATUS_META, 'submitted' );

		WC_Gen_Health_Rx::start_consult( $user_id, $patient_id, $client_product_id );

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	private function redirect_with_error( string $message ): void {
		wp_safe_redirect( add_query_arg( 'gen_health_error', rawurlencode( $message ), wc_get_account_endpoint_url( self::ENDPOINT ) ) );
		exit;
	}

	/**
	 * Published products flagged as requiring a consult.
	 *
	 * @return WC_Product[]
	 */
	private function gated_products(): array {
		$ids = wc_get_products(
			array(
				'status'     => 'publish',
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => WC_Gen_Health_Product_Meta::REQUIRES_CONSULT_META,
						'value' => 'yes',
					),
				),
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

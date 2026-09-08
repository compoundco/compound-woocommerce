<?php
/**
 * Sandbox-only manual controls to move a pending consult along by hand, instead of waiting on
 * real clinical review. Calls Compound's own sandbox dev/resolve endpoint
 * (WC_Compound_API::telemedicine_dev_resolve()) - the exact same fills/refund logic the real
 * poll uses runs on the Compound side, not a local copy of it. This plugin no longer knows
 * medication names or prescription ids (Compound is pointer-only - see the telemedicine
 * plan), so unlike v1 there's no "medication" field to fill in here; refills is still
 * settable since Compound tracks fills count.
 *
 * Gated on the Compound gateway's own `environment` setting (woocommerce_compound_settings),
 * the same sandbox/live signal the rest of this plugin already uses - never a second,
 * independently-toggled flag that could drift out of sync and leak into a live store.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Dev_Tools {

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ), 11 );
		add_action( 'edit_user_profile', array( $this, 'render' ), 11 );
		add_action( 'admin_post_gen_health_dev_simulate', array( $this, 'handle_simulate' ) );
	}

	public static function is_sandbox(): bool {
		$settings = get_option( 'woocommerce_compound_settings', array() );
		$env      = is_array( $settings ) ? (string) ( $settings['environment'] ?? 'sandbox' ) : 'sandbox';
		return 'live' !== $env;
	}

	public function render( WP_User $user ): void {
		if ( ! WC_Gen_Health_Settings::is_active() || ! self::is_sandbox() || ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$pending = $this->pending_consults_for( $user->user_email );
		echo '<h2>' . esc_html__( 'Telemedicine dev tools (sandbox only)', 'compound-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Move a pending consult along by hand instead of waiting on real clinical review. Runs the exact same approve/deny logic Compound uses for real, including the real refund on denial.', 'compound-woocommerce' ) . '</p>';

		if ( is_wp_error( $pending ) ) {
			echo '<p>' . esc_html( $pending->get_error_message() ) . '</p>';
			return;
		}
		if ( empty( $pending ) ) {
			echo '<p>' . esc_html__( 'No pending consult requests for this customer.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		foreach ( $pending as $consult ) {
			$this->render_controls( $consult );
		}
	}

	private function render_controls( array $consult ): void {
		$consult_id = (string) ( $consult['id'] ?? '' );
		?>
		<div style="border:1px solid #ccd0d4;border-radius:4px;padding:12px 14px;margin-bottom:10px;max-width:640px;">
			<strong><?php echo esc_html( (string) ( $consult['product_sku'] ?? '' ) ); ?></strong>
			&mdash; <?php esc_html_e( 'pending', 'compound-woocommerce' ); ?>
			<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $this->hidden_fields( $consult_id, 'approved' ); ?>
					<label>
						<?php esc_html_e( 'Refills', 'compound-woocommerce' ); ?><br />
						<input type="number" name="refills" value="2" min="0" style="width:70px;" />
					</label>
					<button type="submit" class="button" style="margin-left:8px;"><?php esc_html_e( 'Simulate approval', 'compound-woocommerce' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $this->hidden_fields( $consult_id, 'denied' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Simulate denial (refunds linked orders)', 'compound-woocommerce' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	private function hidden_fields( string $consult_id, string $outcome ): void {
		wp_nonce_field( 'gen_health_dev_simulate' );
		echo '<input type="hidden" name="action" value="gen_health_dev_simulate" />';
		echo '<input type="hidden" name="outcome" value="' . esc_attr( $outcome ) . '" />';
		echo '<input type="hidden" name="consult_id" value="' . esc_attr( $consult_id ) . '" />';
		// Carries the profile we came from back to handle_simulate() for the redirect - not
		// the field being validated; the nonce above covers the actual submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$profile_user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : get_current_user_id();
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $profile_user_id ) . '" />';
	}

	public function handle_simulate(): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'compound-woocommerce' ) );
		}
		check_admin_referer( 'gen_health_dev_simulate' );
		if ( ! self::is_sandbox() ) {
			wp_die( esc_html__( 'Dev tools are only available when the Compound gateway environment is sandbox.', 'compound-woocommerce' ) );
		}

		$consult_id = isset( $_POST['consult_id'] ) ? sanitize_text_field( wp_unslash( $_POST['consult_id'] ) ) : '';
		$outcome    = isset( $_POST['outcome'] ) ? sanitize_text_field( wp_unslash( $_POST['outcome'] ) ) : '';
		$refills    = isset( $_POST['refills'] ) ? max( 0, (int) $_POST['refills'] ) : 2;
		$user_id    = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

		if ( '' !== $consult_id && in_array( $outcome, array( 'approved', 'denied' ), true ) ) {
			WC_Gen_Health_Settings::api()->telemedicine_dev_resolve( $consult_id, $outcome, $refills );
		}

		wp_safe_redirect( add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) ) );
		exit;
	}

	/**
	 * This customer's pending consults, per Compound.
	 *
	 * @param string $email Customer's account email.
	 * @return array|WP_Error
	 */
	private function pending_consults_for( string $email ) {
		if ( '' === $email ) {
			return array();
		}
		$result = WC_Gen_Health_Settings::api()->telemedicine_consults( $email );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$consults = is_array( $result['consults'] ?? null ) ? $result['consults'] : array();
		return array_values( array_filter( $consults, static fn( $c ) => 'pending' === ( $c['status'] ?? '' ) ) );
	}
}

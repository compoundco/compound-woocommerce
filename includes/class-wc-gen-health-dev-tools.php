<?php
/**
 * Sandbox-only manual controls to move a pending Gen Health consult along by hand, instead of
 * waiting on real clinical review plus the hourly cron poll (class-wc-gen-health-cron.php).
 * Calls the exact same WC_Gen_Health_Cron::approve()/deny() the real poll uses - a simulated
 * approval/denial exercises the real fills setup and refund-on-denial path, not a copy of it.
 *
 * Gated on the Compound gateway's own `environment` setting (woocommerce_compound_settings),
 * the same sandbox/live signal the rest of this plugin already uses - never a second,
 * independently-toggled flag that could drift out of sync and leak into a live store.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Dev_Tools {

	private WC_Gen_Health_Cron $cron;

	public function __construct( WC_Gen_Health_Cron $cron ) {
		$this->cron = $cron;
	}

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

		$pending = $this->pending_requests_for( $user->ID );
		echo '<h2>' . esc_html__( 'Gen Health dev tools (sandbox only)', 'compound-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Move a pending consult along by hand instead of waiting on real clinical review and the hourly cron poll. Runs the exact same approve/deny logic the cron uses, including the real Compound refund on denial.', 'compound-woocommerce' ) . '</p>';

		if ( empty( $pending ) ) {
			echo '<p>' . esc_html__( 'No pending consult requests for this customer.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		foreach ( $pending as $client_product_id => $label ) {
			$this->render_controls( $user->ID, $client_product_id, $label );
		}
	}

	private function render_controls( int $user_id, string $client_product_id, string $label ): void {
		?>
		<div style="border:1px solid #ccd0d4;border-radius:4px;padding:12px 14px;margin-bottom:10px;max-width:640px;">
			<strong><?php echo esc_html( $label ); ?></strong>
			&mdash; <?php esc_html_e( 'pending', 'compound-woocommerce' ); ?>
			<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $this->hidden_fields( $user_id, $client_product_id, 'approve' ); ?>
					<label>
						<?php esc_html_e( 'Medication', 'compound-woocommerce' ); ?><br />
						<input type="text" name="medication" value="Simulated medication" />
					</label>
					<label style="margin-left:8px;">
						<?php esc_html_e( 'Refills', 'compound-woocommerce' ); ?><br />
						<input type="number" name="refills" value="2" min="0" style="width:70px;" />
					</label>
					<button type="submit" class="button" style="margin-left:8px;"><?php esc_html_e( 'Simulate approval', 'compound-woocommerce' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $this->hidden_fields( $user_id, $client_product_id, 'deny' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Simulate denial (refunds linked orders)', 'compound-woocommerce' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	private function hidden_fields( int $user_id, string $client_product_id, string $outcome ): void {
		wp_nonce_field( 'gen_health_dev_simulate' );
		echo '<input type="hidden" name="action" value="gen_health_dev_simulate" />';
		echo '<input type="hidden" name="outcome" value="' . esc_attr( $outcome ) . '" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '" />';
		echo '<input type="hidden" name="client_product_id" value="' . esc_attr( $client_product_id ) . '" />';
	}

	public function handle_simulate(): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'compound-woocommerce' ) );
		}
		check_admin_referer( 'gen_health_dev_simulate' );
		if ( ! self::is_sandbox() ) {
			wp_die( esc_html__( 'Dev tools are only available when the Compound gateway environment is sandbox.', 'compound-woocommerce' ) );
		}

		$user_id           = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$client_product_id = isset( $_POST['client_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_product_id'] ) ) : '';
		$outcome           = isset( $_POST['outcome'] ) ? sanitize_text_field( wp_unslash( $_POST['outcome'] ) ) : '';

		$request = $user_id && '' !== $client_product_id ? WC_Gen_Health_Rx::get_request( $user_id, $client_product_id ) : null;
		if ( $request && 'pending' === $request['status'] ) {
			if ( 'approve' === $outcome ) {
				$this->cron->approve(
					$user_id,
					$client_product_id,
					$request,
					array(
						'refills'        => isset( $_POST['refills'] ) ? max( 0, (int) $_POST['refills'] ) : 0,
						'prescriptionId' => 'sim_' . wp_generate_password( 10, false ),
						'medicationName' => isset( $_POST['medication'] ) ? sanitize_text_field( wp_unslash( $_POST['medication'] ) ) : '',
						'providerName'   => __( 'Dev simulation', 'compound-woocommerce' ),
					)
				);
			} elseif ( 'deny' === $outcome ) {
				$this->cron->deny( $user_id, $client_product_id, $request );
			}
		}

		wp_safe_redirect( add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) ) );
		exit;
	}

	/**
	 * This customer's pending requests, keyed by clientProductId, labeled by product name.
	 *
	 * @param int $user_id WordPress user id of the customer.
	 * @return array<string, string>
	 */
	private function pending_requests_for( int $user_id ): array {
		$out = array();
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
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$client_product_id = WC_Gen_Health_Product_Meta::client_product_id( $product );
			if ( '' === $client_product_id ) {
				continue;
			}
			$request = WC_Gen_Health_Rx::get_request( $user_id, $client_product_id );
			if ( null !== $request && 'pending' === $request['status'] ) {
				$out[ $client_product_id ] = $product->get_name();
			}
		}
		return $out;
	}
}

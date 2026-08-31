<?php
/**
 * WP-CLI commands. `wp compound sync_coupons` pulls the brand's active coupons from
 * Compound and upserts them as WooCommerce coupons, so brand-defined discounts show
 * up in the WooCommerce checkout flow.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_CLI {

	/**
	 * Sync active Compound coupons into WooCommerce.
	 *
	 * ## EXAMPLES
	 *     wp compound sync_coupons
	 *
	 * @param array $args       Positional arguments. WP-CLI always passes these; unused.
	 * @param array $assoc_args Associative arguments. WP-CLI always passes these; unused.
	 */
	public function sync_coupons( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$settings = get_option( 'woocommerce_compound_settings', array() );
		// Falls back to the pre-migration keys directly (rather than relying on the gateway's
		// own migrate_legacy_settings() having already run) since a CLI command can execute
		// before WooCommerce has ever instantiated WC_Gateway_Compound this request.
		$api_base = $settings['api_base'] ?? ( $settings['orders_url'] ?? ( $settings['payments_url'] ?? '' ) );
		$api      = new WC_Compound_API( $api_base, $settings['api_key'] ?? '' );

		$coupons = $api->get_coupons();
		if ( is_wp_error( $coupons ) ) {
			WP_CLI::error( $coupons->get_error_message() );
			return;
		}

		$synced = 0;
		foreach ( $coupons as $c ) {
			$code = strtolower( (string) ( $c['code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$coupon = new WC_Coupon( $code ); // Loads an existing coupon by code, or a new one.
			if ( 'percent' === ( $c['discount_type'] ?? '' ) ) {
				$coupon->set_discount_type( 'percent' );
				$coupon->set_amount( (float) ( $c['value'] ?? 0 ) );
			} else {
				$coupon->set_discount_type( 'fixed_cart' );
				$coupon->set_amount( (float) ( $c['value'] ?? 0 ) / 100 ); // Cents to dollars.
			}
			$coupon->set_description( 'Synced from Compound' );
			$coupon->save();
			++$synced;
			WP_CLI::log( "  {$code} ({$c['discount_type']} {$c['value']})" );
		}
		WP_CLI::success( "Synced {$synced} coupon(s) from Compound." );
	}

	/**
	 * Simulate a Gen Health consult being approved, without waiting on real clinical review
	 * or the hourly cron poll. Runs the exact same code the poll uses
	 * (WC_Gen_Health_Cron::approve()) - scriptable counterpart to the sandbox-only dev-tools
	 * buttons on the user-profile screen (class-wc-gen-health-dev-tools.php).
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user id of the customer.
	 *
	 * <client_product_id>
	 * : Gen Health clientProductId the pending request is for.
	 *
	 * [--medication=<medication>]
	 * : Medication name to record. Default: "Simulated medication".
	 *
	 * [--refills=<refills>]
	 * : Refills to authorize (fills_total becomes 1 + refills). Default: 2.
	 *
	 * ## EXAMPLES
	 *     wp compound gen_health_approve 42 clientA_network1_prodX --medication="Semaglutide" --refills=3
	 *
	 * @param array $args       Positional: [user_id, client_product_id].
	 * @param array $assoc_args Associative: medication, refills.
	 */
	public function gen_health_approve( $args, $assoc_args ) {
		[$user_id, $client_product_id] = array( (int) ( $args[0] ?? 0 ), (string) ( $args[1] ?? '' ) );
		$request                       = $this->gen_health_pending_request( $user_id, $client_product_id );

		( new WC_Gen_Health_Cron() )->approve(
			$user_id,
			$client_product_id,
			$request,
			array(
				'refills'        => max( 0, (int) ( $assoc_args['refills'] ?? 2 ) ),
				'prescriptionId' => 'sim_' . wp_generate_password( 10, false ),
				'medicationName' => (string) ( $assoc_args['medication'] ?? 'Simulated medication' ),
				'providerName'   => 'Dev simulation (WP-CLI)',
			)
		);
		WP_CLI::success( "Approved {$client_product_id} for user {$user_id}." );
	}

	/**
	 * Simulate a Gen Health consult being denied - refunds every linked, unshipped WooCommerce
	 * order through the real Compound refund endpoint, exactly like a real denial would.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user id of the customer.
	 *
	 * <client_product_id>
	 * : Gen Health clientProductId the pending request is for.
	 *
	 * ## EXAMPLES
	 *     wp compound gen_health_deny 42 clientA_network1_prodX
	 *
	 * @param array $args       Positional: [user_id, client_product_id].
	 * @param array $assoc_args Associative arguments. WP-CLI always passes these; unused.
	 */
	public function gen_health_deny( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		[$user_id, $client_product_id] = array( (int) ( $args[0] ?? 0 ), (string) ( $args[1] ?? '' ) );
		$request                       = $this->gen_health_pending_request( $user_id, $client_product_id );

		( new WC_Gen_Health_Cron() )->deny( $user_id, $client_product_id, $request );
		WP_CLI::success( "Denied {$client_product_id} for user {$user_id} - linked unshipped orders refunded through Compound." );
	}

	/**
	 * Look up a pending Gen Health RX request, or halt the CLI command with a clear error.
	 *
	 * @param int    $user_id           WordPress user id of the customer.
	 * @param string $client_product_id Gen Health clientProductId.
	 * @return array The pending request record, or a WP_CLI::error() (never returns) if
	 *               there isn't one to act on.
	 */
	private function gen_health_pending_request( int $user_id, string $client_product_id ) {
		if ( ! $user_id || '' === $client_product_id ) {
			WP_CLI::error( 'Usage: wp compound gen_health_approve|gen_health_deny <user_id> <client_product_id>' );
		}
		$request = WC_Gen_Health_Rx::get_request( $user_id, $client_product_id );
		if ( null === $request ) {
			WP_CLI::error( "No Gen Health request found for user {$user_id} / {$client_product_id}." );
		}
		if ( 'pending' !== $request['status'] ) {
			WP_CLI::error( "Request is already {$request['status']}, not pending." );
		}
		return $request;
	}
}

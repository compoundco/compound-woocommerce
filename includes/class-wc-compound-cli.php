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
	/**
	 * Drops this store's cached reads of the telemedicine toggle and the intake questions, so
	 * the next page load asks Compound again.
	 *
	 * Both are cached to keep every registration page render from calling out to Compound.
	 * That means a change made in the Compound dashboard is otherwise invisible here until the
	 * cache expires, which is the fastest thing to trip over while testing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp compound refresh
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args (unused).
	 */
	public function refresh( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		WC_Gen_Health_Settings::clear_cache();
		WP_CLI::success( 'Cleared cached telemedicine settings and intake questions.' );
	}

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
	 * Simulate a consult being approved, without waiting on real clinical review. Calls
	 * Compound's own sandbox dev/resolve endpoint (WC_Compound_API::telemedicine_dev_resolve())
	 * - the exact same fills/refund logic the real poll uses runs on the Compound side, not a
	 * local copy of it. Scriptable counterpart to the sandbox-only dev-tools buttons on the
	 * user-profile screen (class-wc-gen-health-dev-tools.php).
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Customer's account email.
	 *
	 * <product_sku>
	 * : SKU the pending consult was started for.
	 *
	 * [--refills=<refills>]
	 * : Refills to authorize (Compound records fills_total as 1 + refills). Default: 2.
	 *
	 * ## EXAMPLES
	 *     wp compound gen_health_approve patient@example.com glp1-starter --refills=3
	 *
	 * @param array $args       Positional: [email, product_sku].
	 * @param array $assoc_args Associative: refills.
	 */
	public function gen_health_approve( $args, $assoc_args ) {
		$consult = $this->gen_health_pending_consult( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) );
		WC_Gen_Health_Settings::api()->telemedicine_dev_resolve(
			(string) $consult['id'],
			'approved',
			max( 0, (int) ( $assoc_args['refills'] ?? 2 ) )
		);
		WP_CLI::success( "Approved consult {$consult['id']}." );
	}

	/**
	 * Simulate a consult being denied - refunds every linked, unshipped WooCommerce order
	 * through the real Compound refund endpoint, exactly like a real denial would.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Customer's account email.
	 *
	 * <product_sku>
	 * : SKU the pending consult was started for.
	 *
	 * ## EXAMPLES
	 *     wp compound gen_health_deny patient@example.com glp1-starter
	 *
	 * @param array $args       Positional: [email, product_sku].
	 * @param array $assoc_args Associative arguments. WP-CLI always passes these; unused.
	 */
	public function gen_health_deny( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$consult = $this->gen_health_pending_consult( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) );
		WC_Gen_Health_Settings::api()->telemedicine_dev_resolve( (string) $consult['id'], 'denied' );
		WP_CLI::success( "Denied consult {$consult['id']} - linked unshipped orders refunded through Compound." );
	}

	/**
	 * Look up a customer's pending consult for a SKU via Compound, or halt the CLI command
	 * with a clear error.
	 *
	 * @param string $email       Customer's account email.
	 * @param string $product_sku SKU the consult was started for.
	 * @return array The pending consult record - never returns if there isn't one to act on.
	 */
	private function gen_health_pending_consult( string $email, string $product_sku ): array {
		if ( '' === $email || '' === $product_sku ) {
			WP_CLI::error( 'Usage: wp compound gen_health_approve|gen_health_deny <email> <product_sku>' );
		}
		$result = WC_Gen_Health_Settings::api()->telemedicine_consults( $email );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$consults = is_array( $result['consults'] ?? null ) ? $result['consults'] : array();
		foreach ( $consults as $consult ) {
			if ( ( $consult['product_sku'] ?? '' ) === $product_sku && 'pending' === ( $consult['status'] ?? '' ) ) {
				return $consult;
			}
		}
		WP_CLI::error( "No pending consult found for {$email} / {$product_sku}." );
	}
}

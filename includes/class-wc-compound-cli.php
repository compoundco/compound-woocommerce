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
	 */
	public function sync_coupons( $args, $assoc_args ) {
		$settings = get_option( 'woocommerce_compound_settings', array() );
		$api      = new WC_Compound_API(
			$settings['orders_url'] ?? '',
			$settings['payments_url'] ?? '',
			$settings['api_key'] ?? ''
		);

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
			$coupon = new WC_Coupon( $code ); // loads an existing coupon by code, or a new one
			if ( 'percent' === ( $c['discount_type'] ?? '' ) ) {
				$coupon->set_discount_type( 'percent' );
				$coupon->set_amount( (float) ( $c['value'] ?? 0 ) );
			} else {
				$coupon->set_discount_type( 'fixed_cart' );
				$coupon->set_amount( (float) ( $c['value'] ?? 0 ) / 100 ); // cents -> dollars
			}
			$coupon->set_description( 'Synced from Compound' );
			$coupon->save();
			$synced++;
			WP_CLI::log( "  {$code} ({$c['discount_type']} {$c['value']})" );
		}
		WP_CLI::success( "Synced {$synced} coupon(s) from Compound." );
	}
}

<?php
/**
 * Links a WooCommerce order back to its record in the Compound brand portal, wherever the
 * order edit screen already shows order-level details. HPOS-safe: hooks WooCommerce's own
 * order-data-panel action rather than reading/writing custom order tables directly.
 *
 * No separate "brand portal URL" setting: the portal is always the API host with its "api"
 * label swapped for "app" (stg.api.thepeptides.company -> stg.app.thepeptides.company,
 * api.thepeptides.company -> app.thepeptides.company) - Compound's own Terraform derives
 * both hostnames from the same domain + prefix, so it's always inferable from api_base
 * rather than a second value that could quietly drift out of sync with it.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Order_Admin {

	public function register(): void {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_link' ) );
	}

	/**
	 * Print the "View in Compound" link, if this order has a linked Compound order and its
	 * portal URL can be inferred from the configured API base.
	 *
	 * @param WC_Order $order The order being viewed in wp-admin.
	 */
	public function render_link( WC_Order $order ): void {
		$compound_order_id = (string) $order->get_meta( '_compound_order_id' );
		if ( '' === $compound_order_id ) {
			return;
		}

		$settings    = get_option( 'woocommerce_compound_settings', array() );
		$portal_base = self::portal_base_from_api_base( (string) ( $settings['api_base'] ?? '' ) );
		if ( '' === $portal_base ) {
			return;
		}

		$url = $portal_base . '/orders/' . rawurlencode( $compound_order_id );
		printf(
			'<p class="form-field form-field-wide"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'View in Compound →', 'compound-woocommerce' )
		);
	}

	/**
	 * '' when api_base is empty/unparseable, or has no "api" host label to swap - never
	 * guesses wrong, just omits the link.
	 *
	 * @param string $api_base The gateway's configured API base URL.
	 */
	private static function portal_base_from_api_base( string $api_base ): string {
		$parts = wp_parse_url( $api_base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$labels  = explode( '.', $parts['host'] );
		$swapped = false;
		foreach ( $labels as $i => $label ) {
			if ( 'api' === $label ) {
				$labels[ $i ] = 'app';
				$swapped      = true;
				break;
			}
		}
		if ( ! $swapped ) {
			return '';
		}
		$scheme = $parts['scheme'] ?? 'https';
		return $scheme . '://' . implode( '.', $labels );
	}
}

<?php
/**
 * Telemedicine config helpers. This plugin no longer holds a Gen Health credential or base
 * URL anywhere - Compound is the only party that talks to Gen Health, and this plugin talks
 * to Compound with the SAME api_base/api_key already configured for orders and charges
 * (`woocommerce_compound_settings`, read by class-wc-gateway-compound.php). "Enable
 * telemedicine" is a brand-level setting in the Compound admin portal now, not a local
 * WordPress option - is_active() reads it from Compound (cached briefly, since every
 * registration-page load would otherwise call out to Compound just to decide whether to
 * show a field).
 *
 * Deliberately NOT a WC_Settings_Page (that pattern is documented in the settings-page.php
 * this file used to pair with - see git history - and is no longer needed: there is nothing
 * left to configure here beyond what Compound's admin portal itself now owns).
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Settings {

	const CACHE_TRANSIENT = 'compound_telemedicine_enabled';
	const CACHE_TTL       = 15 * MINUTE_IN_SECONDS;

	public static function api(): WC_Compound_API {
		$opts = get_option( 'woocommerce_compound_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();
		return new WC_Compound_API( (string) ( $opts['api_base'] ?? '' ), (string) ( $opts['api_key'] ?? '' ) );
	}

	/**
	 * Whether telemedicine is enabled for this brand, per Compound. Cached briefly so the
	 * registration page and every gated-product check don't call out to Compound on every
	 * load; call clear_cache() after anything that should make a change visible immediately
	 * (there's currently no such local action - the admin portal is the only place this
	 * changes - so the transient's own TTL is the only refresh path).
	 */
	public static function is_active(): bool {
		$cached = get_transient( self::CACHE_TRANSIENT );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$opts = get_option( 'woocommerce_compound_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();
		// No Compound credential configured yet - not active, and not worth a network call.
		if ( empty( $opts['api_key'] ) || empty( $opts['api_base'] ) ) {
			return false;
		}
		$result  = self::api()->telemedicine_config();
		$enabled = ! is_wp_error( $result ) && ! empty( $result['enabled'] );
		set_transient( self::CACHE_TRANSIENT, $enabled ? 'yes' : 'no', self::CACHE_TTL );
		return $enabled;
	}
}

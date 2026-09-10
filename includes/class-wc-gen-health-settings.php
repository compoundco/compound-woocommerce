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
	const FORM_TRANSIENT  = 'compound_wc_intake_form';

	/**
	 * How long the telemedicine toggle and the intake questions are cached for.
	 *
	 * Short in sandbox, because sandbox is where someone is actively changing these settings
	 * in the Compound portal and reloading the storefront to see the result: a fifteen-minute
	 * wait there reads as "the change did not work". Longer in live, where these settings
	 * change rarely and every registration page render would otherwise call out to Compound.
	 *
	 * Either way clear_cache() makes a change visible immediately, and the plugin settings
	 * screen exposes it as a button.
	 */
	public static function cache_ttl(): int {
		$opts = get_option( 'woocommerce_compound_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();
		return 'sandbox' === ( $opts['environment'] ?? 'sandbox' ) ? 15 : 15 * MINUTE_IN_SECONDS;
	}

	/**
	 * Drops both cached reads so the next page load asks Compound again. Called from the
	 * settings screen's refresh button, from `wp compound refresh`, and whenever the Compound
	 * credentials change (a different key may be a different brand entirely).
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_TRANSIENT );
		delete_transient( self::FORM_TRANSIENT );
		// Per-SKU questionnaires are cached one transient per product, so they are dropped as
		// a family. Missing this would leave the screening questions stale after a refresh
		// that appeared to work, which is the exact confusion the refresh button exists to end.
		if ( class_exists( 'WC_Compound_Screening' ) ) {
			WC_Compound_Screening::clear_cache();
		}
	}

	public static function api(): WC_Compound_API {
		$opts = get_option( 'woocommerce_compound_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();
		return new WC_Compound_API( (string) ( $opts['api_base'] ?? '' ), (string) ( $opts['api_key'] ?? '' ) );
	}

	/**
	 * Whether telemedicine is enabled for this brand, per Compound. Cached briefly so the
	 * registration page and every gated-product check don't call out to Compound on every
	 * load. clear_cache() makes a change visible immediately; in sandbox the TTL is short
	 * enough (see cache_ttl()) that a reload is usually all it takes.
	 */
	/**
	 * The brand's configured intake questions, cached on the same basis as is_active(): every
	 * registration page render would otherwise call out to Compound. A failed fetch returns an
	 * empty list, and callers fall back to the built-in question set rather than rendering an
	 * empty form.
	 *
	 * @return array[] Question rows as Compound returns them.
	 */
	public static function intake_questions(): array {
		$cached = get_transient( self::FORM_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$opts = get_option( 'woocommerce_compound_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();
		if ( empty( $opts['api_key'] ) || empty( $opts['api_base'] ) ) {
			return array();
		}
		$result = self::api()->telemedicine_intake_form();
		if ( is_wp_error( $result ) || ! isset( $result['questions'] ) || ! is_array( $result['questions'] ) ) {
			return array();
		}
		set_transient( self::FORM_TRANSIENT, $result['questions'], self::cache_ttl() );
		return $result['questions'];
	}

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
		set_transient( self::CACHE_TRANSIENT, $enabled ? 'yes' : 'no', self::cache_ttl() );
		return $enabled;
	}
}

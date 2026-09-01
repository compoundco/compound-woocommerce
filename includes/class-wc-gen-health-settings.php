<?php
/**
 * Gen Health config helpers - deliberately NOT a WC_Settings_Page (that lives in
 * class-wc-gen-health-settings-page.php, loaded lazily - see its file header for why). This
 * class only reads the option and builds an API client, so every other Gen Health class can
 * depend on it unconditionally from the moment `plugins_loaded` fires, same as any other
 * plain PHP class in this plugin.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Settings {

	const OPTION_KEY = 'woocommerce_gen_health_settings';

	/**
	 * The current settings, with defaults filled in.
	 *
	 * @return array{enabled: bool, api_key: string, api_base: string}
	 */
	public static function config(): array {
		$opts = get_option( self::OPTION_KEY, array() );
		$opts = is_array( $opts ) ? $opts : array();
		return array(
			'enabled'  => 'yes' === ( $opts['enabled'] ?? 'no' ),
			'api_key'  => (string) ( $opts['api_key'] ?? '' ),
			'api_base' => (string) ( $opts['api_base'] ?? 'https://api.gen-health.app' ),
		);
	}

	/** Enabled AND has an API key - i.e. actually usable, not just switched on. */
	public static function is_active(): bool {
		$c = self::config();
		return $c['enabled'] && '' !== $c['api_key'];
	}

	public static function api(): WC_Gen_Health_API {
		$c = self::config();
		return new WC_Gen_Health_API( $c['api_base'], $c['api_key'] );
	}
}

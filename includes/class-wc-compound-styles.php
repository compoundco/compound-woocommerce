<?php
/**
 * Frontend CSS for everything this plugin renders (telemedicine intake fields, the sandbox
 * checkout fields). Two layers, printed together in one <style> block on every frontend page:
 * a small base stylesheet fixing the plugin's own known layout quirk (bare <input>/<select>
 * elements inherit the active theme's default width - often a fixed, narrow one, not the full
 * width of their column), and a "Custom CSS" field (Settings -> Payments -> Compound) a
 * merchant can use to make the rest match their site, without needing a child theme or a
 * separate CSS deploy. Custom CSS always prints after the base rules, so it can override them.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Styles {

	public function register(): void {
		add_action( 'wp_head', array( $this, 'output' ) );
	}

	public function output(): void {
		if ( is_admin() ) {
			return;
		}
		$settings = get_option( 'woocommerce_compound_settings', array() );
		$custom   = is_array( $settings ) ? (string) ( $settings['custom_css'] ?? '' ) : '';

		echo '<style id="compound-wc-styles">';
		// Text-like controls stretch to their column. Checkboxes and radios are excluded:
		// a 100%-wide checkbox is what pushed the box away from the left edge, and how far
		// depended on whatever the active theme does with a stretched one.
		echo '.compound-wc-field input:not([type="checkbox"]):not([type="radio"]),';
		echo '.compound-wc-field select, .compound-wc-field textarea,';
		echo '.compound-sandbox-fields input:not([type="checkbox"]):not([type="radio"]),';
		echo '.compound-sandbox-fields select {';
		echo 'width: 100%; max-width: 100%; box-sizing: border-box;';
		echo '}';
		// Question, then help text, then the box, each on its own line and hard left.
		echo '.compound-wc-field__label, .compound-wc-field__help { display: block; }';
		echo '.compound-wc-field--checkbox input[type="checkbox"] {';
		echo 'width: auto; margin: 0.35em 0 0; display: block; float: none;';
		echo '}';
		// The screening block sits inside the add-to-cart form, above the quantity and button,
		// so it needs its own separation rather than inheriting whatever the theme does with a
		// bare <div> there.
		echo '.compound-screening { margin: 0 0 1.5em; }';
		echo '.compound-screening__intro { font-weight: 600; margin: 0 0 0.75em; }';
		echo '.compound-screening__field { margin: 0 0 1em; }';
		echo '</style>' . "\n";

		if ( '' !== trim( $custom ) ) {
			// Same sanitization WordPress core's own Customizer "Additional CSS" field uses -
			// strips tags (a legitimate stylesheet has none) so custom_css can never become a
			// </style> breakout, while leaving real CSS untouched.
			echo '<style id="compound-wc-custom-css">' . wp_strip_all_tags( $custom ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}

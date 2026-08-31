<?php
/**
 * A new, independent WooCommerce Settings tab ("Telemedicine") for the Gen Health
 * integration. Kept separate from woocommerce_compound_settings (the payment gateway's own
 * option, WC_Gateway_Compound) - the feature toggle here is conceptually unrelated to
 * payment config, and there is no existing settings-tab class in this plugin to extend.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Settings extends WC_Settings_Page {

	const OPTION_KEY = 'woocommerce_gen_health_settings';

	public function __construct() {
		$this->id    = 'gen_health';
		$this->label = __( 'Telemedicine', 'compound-woocommerce' );
		parent::__construct();
	}

	public function get_settings( $current_section = '' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		return array(
			array(
				'title' => __( 'Telemedicine (Gen Health)', 'compound-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Collects a health intake at signup and starts a clinical consult with Gen Health. Compound still collects payment and routes fulfillment - Gen Health is used only for intake, review, and the resulting prescription.', 'compound-woocommerce' ),
				'id'    => 'gen_health_options',
			),
			array(
				'title'   => __( 'Enable telemedicine', 'compound-woocommerce' ),
				'desc'    => __( 'Require a health intake at signup and start a consult for products marked "Requires telehealth consult".', 'compound-woocommerce' ),
				'id'      => self::OPTION_KEY . '[enabled]',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'title'    => __( 'Client API key', 'compound-woocommerce' ),
				'desc'     => __( 'A Gen Health Client API key (X-API-Key). Never exposed to the browser.', 'compound-woocommerce' ),
				'id'       => self::OPTION_KEY . '[api_key]',
				'type'     => 'password',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'API base URL', 'compound-woocommerce' ),
				'id'       => self::OPTION_KEY . '[api_base]',
				'type'     => 'text',
				'default'  => 'https://api.gen-health.app',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'gen_health_options',
			),
		);
	}

	public function output() {
		WC_Admin_Settings::output_fields( $this->get_settings() );
	}

	public function save() {
		WC_Admin_Settings::save_fields( $this->get_settings() );
	}

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

<?php
/**
 * The "Telemedicine" WooCommerce Settings tab itself - split out from
 * class-wc-gen-health-settings.php specifically because this one extends WC_Settings_Page.
 * That base class is NOT loaded eagerly by WooCommerce (unlike WC_Payment_Gateway, which is):
 * WooCommerce only `include_once`s it lazily, from inside
 * WC_Admin_Settings::get_settings_pages(), immediately before firing the
 * `woocommerce_get_settings_pages` filter. Requiring this file - and so declaring `extends
 * WC_Settings_Page` - any earlier than that filter firing is a fatal "Class WC_Settings_Page
 * not found" on every single page load, not just wp-admin (verified against WooCommerce's own
 * includes/admin/class-wc-admin-settings.php). So this file is require_once'd from INSIDE that
 * filter's callback in compound-gateway.php, never from the top-level plugins_loaded block -
 * matching exactly how WooCommerce's own core settings pages (class-wc-settings-general.php
 * etc.) are loaded.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Settings_Page extends WC_Settings_Page {

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
				'id'      => WC_Gen_Health_Settings::OPTION_KEY . '[enabled]',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'title'    => __( 'Client API key', 'compound-woocommerce' ),
				'desc'     => __( 'A Gen Health Client API key (X-API-Key). Never exposed to the browser.', 'compound-woocommerce' ),
				'id'       => WC_Gen_Health_Settings::OPTION_KEY . '[api_key]',
				'type'     => 'password',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'API base URL', 'compound-woocommerce' ),
				'id'       => WC_Gen_Health_Settings::OPTION_KEY . '[api_base]',
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
}

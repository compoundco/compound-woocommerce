<?php
/**
 * The "Telemedicine" WooCommerce Settings tab - now a read-only status display, not a
 * configuration form. There is nothing left to configure here: no Gen Health credential, no
 * base URL, and "enable telemedicine" itself is set from the Compound admin portal, not
 * WordPress (see class-wc-gen-health-settings.php's header for why). This tab exists so a
 * merchant looking under Settings -> Telemedicine (where it always was) finds an explanation
 * and a link out, instead of a tab that quietly vanished.
 *
 * Split out from class-wc-gen-health-settings.php specifically because this one extends
 * WC_Settings_Page. That base class is NOT loaded eagerly by WooCommerce (unlike
 * WC_Payment_Gateway, which is): WooCommerce only `include_once`s it lazily, from inside
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
		return array();
	}

	public function output() {
		$configured = self::compound_configured();
		echo '<h2>' . esc_html__( 'Telemedicine (Gen Health)', 'compound-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Collects a health intake at signup and starts a clinical consult for products that require one. Compound routes this to its telehealth provider on your behalf - it is not something this plugin configures directly.', 'compound-woocommerce' ) . '</p>';

		if ( ! $configured ) {
			echo '<p>' . esc_html__( 'Connect your Compound API key under Payments (Compound) -> API before turning telemedicine on.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		// A refresh clears the cached reads so the next page load asks Compound again. Without
		// this, a change made in the Compound dashboard is invisible here until the cache
		// expires, which reads as the change not having worked.
		if ( isset( $_GET['compound_refresh'] ) && check_admin_referer( 'compound_refresh' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			WC_Gen_Health_Settings::clear_cache();
			echo '<div class="notice notice-success inline"><p>'
				. esc_html__( 'Refreshed from Compound.', 'compound-woocommerce' )
				. '</p></div>';
		}

		$enabled = WC_Gen_Health_Settings::is_active();
		echo '<p><strong>' . esc_html__( 'Status:', 'compound-woocommerce' ) . '</strong> ';
		echo $enabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static strings only.
			? esc_html__( 'On', 'compound-woocommerce' )
			: esc_html__( 'Off', 'compound-woocommerce' );
		echo '</p>';

		$questions = WC_Gen_Health_Settings::intake_questions();
		echo '<p>' . sprintf(
			/* translators: %d: number of intake questions configured in the Compound dashboard. */
			esc_html( _n( '%d intake question configured.', '%d intake questions configured.', count( $questions ), 'compound-woocommerce' ) ),
			count( $questions )
		) . '</p>';

		echo '<p>' . esc_html__( 'Turn telemedicine on or off, and edit the intake questions, from your Compound dashboard. This store caches both; refresh to pick up a change straight away.', 'compound-woocommerce' ) . '</p>';
		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( wp_nonce_url( add_query_arg( 'compound_refresh', '1' ), 'compound_refresh' ) ),
			esc_html__( 'Refresh from Compound', 'compound-woocommerce' )
		);
	}

	public function save() {
		// Nothing to save - see class doc comment.
	}

	private static function compound_configured(): bool {
		$opts = get_option( 'woocommerce_compound_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();
		return ! empty( $opts['api_key'] ) && ! empty( $opts['api_base'] );
	}
}

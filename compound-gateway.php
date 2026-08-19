<?php
/**
 * Plugin Name:       Compound for WooCommerce
 * Plugin URI:        https://compound.dev
 * Description:       Route WooCommerce checkout and orders through Compound - payments orchestration + pharmacy fulfillment for DTC peptide brands.
 * Version:           0.1.5
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Compound
 * License:           GPL-2.0-or-later
 * Text Domain:       compound-woocommerce
 * WC requires at least: 8.0
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'COMPOUND_WC_VERSION', '0.1.5' );
define( 'COMPOUND_WC_FILE', __FILE__ );
define( 'COMPOUND_WC_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Boot the plugin once all plugins are loaded, so we can check WooCommerce is present.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Compound for WooCommerce requires WooCommerce to be installed and active.', 'compound-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once COMPOUND_WC_PATH . 'includes/class-wc-compound-api.php';
		require_once COMPOUND_WC_PATH . 'includes/class-wc-compound-compliance.php';
		require_once COMPOUND_WC_PATH . 'includes/class-wc-gateway-compound.php';
		require_once COMPOUND_WC_PATH . 'includes/class-wc-compound-webhooks.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once COMPOUND_WC_PATH . 'includes/class-wc-compound-cli.php';
			WP_CLI::add_command( 'compound', 'WC_Compound_CLI' );
		}

		// Register the payment gateway.
		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'WC_Gateway_Compound';
				return $gateways;
			}
		);

		// Inbound webhooks from Compound (order status updates).
		( new WC_Compound_Webhooks() )->register();
		( new WC_Compound_Compliance() )->register();

		// Register the gateway with the Cart/Checkout blocks (classic gateways are otherwise
		// invisible there - the checkout shows "no payment methods available").
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				require_once COMPOUND_WC_PATH . 'includes/class-wc-compound-blocks.php';
				$registry->register( new WC_Compound_Blocks() );
			}
		);
	}
);

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', COMPOUND_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', COMPOUND_WC_FILE, true );
		}
	}
);

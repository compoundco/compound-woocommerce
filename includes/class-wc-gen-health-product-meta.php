<?php
/**
 * Per-product telemedicine gating: which SKUs require a Gen Health consult before they can
 * be prescribed, and which Gen Health clientProductId (medication) each one maps to. Standard
 * WooCommerce product-data-panel hooks - there is no existing product-meta admin UI in this
 * plugin to extend (the COA meta in class-wc-compound-compliance.php is seeded via wp-cli,
 * never edited in wp-admin), so this is a small, self-contained addition.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Product_Meta {

	const REQUIRES_CONSULT_META = '_gen_health_requires_consult';
	const CLIENT_PRODUCT_META   = '_gen_health_client_product_id';

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ) );
	}

	public function render_fields(): void {
		global $post;
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::REQUIRES_CONSULT_META,
				'label'       => __( 'Requires telehealth consult', 'compound-woocommerce' ),
				'description' => __( 'A customer needs an approved Gen Health prescription before this product can be prescribed/fulfilled.', 'compound-woocommerce' ),
				'value'       => $product->get_meta( self::REQUIRES_CONSULT_META ) ? 'yes' : 'no',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::CLIENT_PRODUCT_META,
				'label'       => __( 'Gen Health clientProductId', 'compound-woocommerce' ),
				'description' => __( 'The Gen Health product this SKU\'s consult is started against.', 'compound-woocommerce' ),
				'value'       => $product->get_meta( self::CLIENT_PRODUCT_META ),
			)
		);
		echo '</div>';
	}

	public function save_fields( int $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		// WooCommerce verifies its own product-save nonce before woocommerce_process_product_meta fires.
		$requires = isset( $_POST[ self::REQUIRES_CONSULT_META ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( self::REQUIRES_CONSULT_META, $requires );
		if ( isset( $_POST[ self::CLIENT_PRODUCT_META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->update_meta_data( self::CLIENT_PRODUCT_META, sanitize_text_field( wp_unslash( $_POST[ self::CLIENT_PRODUCT_META ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$product->save();
	}

	public static function requires_consult( WC_Product $product ): bool {
		return 'yes' === $product->get_meta( self::REQUIRES_CONSULT_META );
	}

	public static function client_product_id( WC_Product $product ): string {
		return (string) $product->get_meta( self::CLIENT_PRODUCT_META );
	}
}

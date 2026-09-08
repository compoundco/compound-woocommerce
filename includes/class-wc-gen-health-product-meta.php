<?php
/**
 * Per-product telemedicine consult-type override. Gating itself is not per-product: when a
 * brand turns telemedicine on (the Compound admin portal's Settings toggle), EVERY published
 * product is gated - there is no per-product opt-in. This file exists only for the optional
 * override below.
 *
 * The "consult type" is an opaque identifier Compound gives you when a product is set up for
 * telemedicine - this plugin never talks to, names, or knows about the telehealth provider
 * behind it (see the telemedicine plan). Left blank, the product's own SKU is used as the
 * consult type (WC_Gen_Health_Product_Meta::consult_type()) - only set this field when a
 * product's consult type needs to be something other than its SKU.
 *
 * The "consult kind" says what the consult actually is: a good faith exam, or an exam that
 * may result in a prescription. Compound routes on it, and the two are not interchangeable -
 * a good faith exam yields no prescription and stays valid for a period, whereas an exam+Rx
 * is consumed by fills. It defaults to exam+Rx, which is the safer default: a product that
 * genuinely only needs a good faith exam is an explicit choice, never an accident of a blank
 * field.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Product_Meta {

	const CLIENT_PRODUCT_META = '_gen_health_client_product_id';
	const CONSULT_KIND_META   = '_compound_consult_kind';

	const KIND_EXAM_RX = 'exam_rx';
	const KIND_GFE     = 'gfe';

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
		woocommerce_wp_text_input(
			array(
				'id'          => self::CLIENT_PRODUCT_META,
				'label'       => __( 'Consult type override', 'compound-woocommerce' ),
				'description' => __( 'Optional. When telemedicine is on, every product is gated and uses its SKU as the consult type by default - only set this if this product\'s consult type needs to be something else.', 'compound-woocommerce' ),
				'value'       => $product->get_meta( self::CLIENT_PRODUCT_META ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => self::CONSULT_KIND_META,
				'label'       => __( 'Consult kind', 'compound-woocommerce' ),
				'description' => __( 'What this product\'s consult is. A good faith exam produces no prescription and stays valid for a period; an exam + prescription is consumed by fills.', 'compound-woocommerce' ),
				'desc_tip'    => true,
				'value'       => self::consult_kind_from_product( $product ),
				'options'     => array(
					self::KIND_EXAM_RX => __( 'Exam + prescription', 'compound-woocommerce' ),
					self::KIND_GFE     => __( 'Good faith exam', 'compound-woocommerce' ),
				),
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
		if ( isset( $_POST[ self::CLIENT_PRODUCT_META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->update_meta_data( self::CLIENT_PRODUCT_META, sanitize_text_field( wp_unslash( $_POST[ self::CLIENT_PRODUCT_META ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		// Only ever store a value this plugin recognises: an unexpected one would be forwarded
		// to Compound and rejected at intake, when a customer is already waiting.
		if ( isset( $_POST[ self::CONSULT_KIND_META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$kind = sanitize_text_field( wp_unslash( $_POST[ self::CONSULT_KIND_META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->update_meta_data( self::CONSULT_KIND_META, self::KIND_GFE === $kind ? self::KIND_GFE : self::KIND_EXAM_RX );
		}
		$product->save();
	}

	/**
	 * This product's consult type: an explicit override if set, otherwise its own SKU. Never
	 * empty for a real product with a SKU - checkout already refuses a line item with no SKU
	 * (class-wc-gateway-compound.php), so telemedicine tagging can rely on that.
	 *
	 * @param WC_Product $product Product to resolve a consult type for.
	 */
	public static function consult_type( WC_Product $product ): string {
		$override = (string) $product->get_meta( self::CLIENT_PRODUCT_META );
		return '' !== $override ? $override : $product->get_sku();
	}

	/**
	 * This product's consult kind. Defaults to exam + prescription, which is the safer
	 * default: a stored value is only ever one of the two recognised kinds (save_fields
	 * normalises), and anything unset or unrecognised falls back rather than being forwarded.
	 *
	 * @param WC_Product $product Product to resolve a consult kind for.
	 */
	public static function consult_kind( WC_Product $product ): string {
		return self::consult_kind_from_product( $product );
	}

	private static function consult_kind_from_product( WC_Product $product ): string {
		$stored = (string) $product->get_meta( self::CONSULT_KIND_META );
		return self::KIND_GFE === $stored ? self::KIND_GFE : self::KIND_EXAM_RX;
	}
}

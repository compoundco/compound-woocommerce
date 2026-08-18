<?php
/**
 * Storefront controls required by the Compound merchant compliance program.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Compliance {

	private const COA_META_KEY = '_compound_coa_url';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 99 );
		add_action( 'storefront_before_header', array( $this, 'render_announcement' ) );
		add_action( 'storefront_before_content', array( $this, 'render_shop_hero' ), 5 );
		add_action( 'storefront_before_footer', array( $this, 'render_trust_strip' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_age_gate' ), 1 );
		add_action( 'wp_footer', array( $this, 'render_card_marks' ), 50 );
		add_filter( 'woocommerce_checkout_registration_required', '__return_true' );
		add_filter( 'woocommerce_enable_guest_checkout', '__return_false' );
		add_filter( 'comments_open', array( $this, 'disable_product_reviews' ), 20, 2 );
		add_filter( 'woocommerce_product_tabs', array( $this, 'add_coa_tab' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_coa_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_coa_field' ) );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_products' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_account' ), 10, 2 );
	}

	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'wc-compound-compliance',
			plugins_url( 'assets/css/compliance.css', COMPOUND_WC_FILE ),
			array(),
			COMPOUND_WC_VERSION
		);
		wp_enqueue_script(
			'wc-compound-compliance',
			plugins_url( 'assets/js/compliance.js', COMPOUND_WC_FILE ),
			array(),
			COMPOUND_WC_VERSION,
			true
		);
	}

	public function render_announcement(): void {
		?>
		<div class="compound-announcement">
			<div class="col-full">
				<span><?php esc_html_e( 'Independent analytical documentation for every product', 'compound-woocommerce' ); ?></span>
				<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php esc_html_e( 'My account', 'compound-woocommerce' ); ?></a>
			</div>
		</div>
		<?php
	}

	public function render_shop_hero(): void {
		if ( ! is_shop() && ! is_front_page() ) {
			return;
		}
		?>
		<section class="compound-hero" aria-labelledby="compound-hero-title">
			<div class="col-full compound-hero__inner">
				<div class="compound-hero__content">
					<span class="compound-eyebrow"><?php esc_html_e( 'Scientific catalog', 'compound-woocommerce' ); ?></span>
					<h1 id="compound-hero-title"><?php esc_html_e( 'Clarity in every compound.', 'compound-woocommerce' ); ?></h1>
					<p><?php esc_html_e( 'Explore a focused catalog presented with transparent identity data and product-specific analytical documentation.', 'compound-woocommerce' ); ?></p>
					<a class="button compound-hero__button" href="#compound-catalog"><?php esc_html_e( 'View catalog', 'compound-woocommerce' ); ?></a>
				</div>
				<div class="compound-hero__visual" aria-hidden="true">
					<div class="compound-orbit compound-orbit--one"><span></span><span></span><span></span></div>
					<div class="compound-orbit compound-orbit--two"><span></span><span></span></div>
					<div class="compound-molecule">C<small>187</small>H<small>291</small>N<small>45</small>O<small>59</small></div>
				</div>
			</div>
		</section>
		<div id="compound-catalog" class="compound-catalog-anchor" aria-hidden="true"></div>
		<?php
	}

	public function render_trust_strip(): void {
		?>
		<section class="compound-trust" aria-label="<?php esc_attr_e( 'Store standards', 'compound-woocommerce' ); ?>">
			<div class="col-full compound-trust__grid">
				<div><span class="compound-trust__icon">01</span><p><strong><?php esc_html_e( 'Documented', 'compound-woocommerce' ); ?></strong><?php esc_html_e( 'Product-specific COAs', 'compound-woocommerce' ); ?></p></div>
				<div><span class="compound-trust__icon">02</span><p><strong><?php esc_html_e( 'Transparent', 'compound-woocommerce' ); ?></strong><?php esc_html_e( 'Scientific naming', 'compound-woocommerce' ); ?></p></div>
				<div><span class="compound-trust__icon">03</span><p><strong><?php esc_html_e( 'Account protected', 'compound-woocommerce' ); ?></strong><?php esc_html_e( 'Secure member checkout', 'compound-woocommerce' ); ?></p></div>
			</div>
		</section>
		<?php
	}

	public function render_age_gate(): void {
		if ( is_admin() ) {
			return;
		}
		?>
		<div class="compound-age-gate" data-compound-age-gate hidden role="dialog" aria-modal="true" aria-labelledby="compound-age-title">
			<div class="compound-age-gate__panel">
				<h2 id="compound-age-title"><?php esc_html_e( 'Age verification required', 'compound-woocommerce' ); ?></h2>
				<p><?php esc_html_e( 'You must be 21 years of age or older to access this website.', 'compound-woocommerce' ); ?></p>
				<div class="compound-age-gate__actions">
					<button type="button" class="button alt" data-compound-age-confirm><?php esc_html_e( 'I am 21 or older', 'compound-woocommerce' ); ?></button>
					<button type="button" class="button" data-compound-age-deny><?php esc_html_e( 'I am under 21', 'compound-woocommerce' ); ?></button>
				</div>
				<p class="compound-age-gate__denied" data-compound-age-denied hidden><?php esc_html_e( 'Access is restricted to adults age 21 and older.', 'compound-woocommerce' ); ?></p>
			</div>
		</div>
		<?php
	}

	public function render_card_marks(): void {
		if ( is_admin() ) {
			return;
		}
		?>
		<div class="compound-card-marks" aria-label="<?php esc_attr_e( 'Accepted card networks', 'compound-woocommerce' ); ?>">
			<span><?php esc_html_e( 'Accepted cards:', 'compound-woocommerce' ); ?></span>
			<strong>Visa</strong><strong>Mastercard</strong><strong>American Express</strong><strong>Discover</strong>
		</div>
		<?php
	}

	public function disable_product_reviews( bool $open, int $post_id ): bool {
		return 'product' === get_post_type( $post_id ) ? false : $open;
	}

	public function render_coa_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'          => self::COA_META_KEY,
				'label'       => __( 'Certificate of Analysis URL', 'compound-woocommerce' ),
				'description' => __( 'Required public lab report URL for this individual product.', 'compound-woocommerce' ),
				'desc_tip'    => true,
				'type'        => 'url',
			)
		);
	}

	public function save_coa_field( WC_Product $product ): void {
		// WooCommerce verifies the product editor nonce before this hook runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ self::COA_META_KEY ] ) ? esc_url_raw( wp_unslash( $_POST[ self::COA_META_KEY ] ) ) : '';
		$product->update_meta_data( self::COA_META_KEY, $value );
	}

	public function add_coa_tab( array $tabs ): array {
		global $product;
		if ( ! $product instanceof WC_Product || ! $product->get_meta( self::COA_META_KEY ) ) {
			return $tabs;
		}

		$tabs['compound_coa'] = array(
			'title'    => __( 'Lab Report (COA)', 'compound-woocommerce' ),
			'priority' => 25,
			'callback' => array( $this, 'render_coa_tab' ),
		);
		return $tabs;
	}

	public function render_coa_tab(): void {
		global $product;
		$url = $product instanceof WC_Product ? $product->get_meta( self::COA_META_KEY ) : '';
		if ( $url ) {
			printf(
				'<p><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url( $url ),
				esc_html__( 'View Certificate of Analysis', 'compound-woocommerce' )
			);
		}
	}

	public function validate_cart_products(): void {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$missing = array();
			if ( ! $product->get_image_id() ) {
				$missing[] = __( 'image', 'compound-woocommerce' );
			}
			if ( '' === trim( wp_strip_all_tags( $product->get_description() . $product->get_short_description() ) ) ) {
				$missing[] = __( 'description', 'compound-woocommerce' );
			}
			if ( '' === $product->get_price() ) {
				$missing[] = __( 'price', 'compound-woocommerce' );
			}
			if ( ! $product->get_meta( self::COA_META_KEY ) ) {
				$missing[] = __( 'lab report', 'compound-woocommerce' );
			}

			if ( $missing ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: product name, 2: missing requirements. */
						__( '%1$s is unavailable because its required %2$s is missing. Please contact support.', 'compound-woocommerce' ),
						$product->get_name(),
						implode( ', ', $missing )
					),
					'error'
				);
			}
		}
	}

	public function validate_checkout_account( array $data, WP_Error $errors ): void {
		if ( is_user_logged_in() || ! empty( $data['createaccount'] ) ) {
			return;
		}

		$errors->add( 'compound_account_required', __( 'You must create an account or sign in before completing your purchase.', 'compound-woocommerce' ) );
	}
}

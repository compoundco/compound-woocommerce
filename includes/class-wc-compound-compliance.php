<?php
/**
 * Storefront controls required by the Compound merchant compliance program.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Compliance {

	private const COA_META_KEY = '_compound_coa_url';
	private const LOT_META_KEY = '_compound_lot_number';
	private const LAB_META_KEY = '_compound_lab_name';
	private const COA_DATE_META_KEY = '_compound_coa_date';
	private const ANALYSIS_META_KEY = '_compound_analysis_type';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 99 );
		add_action( 'storefront_before_footer', array( $this, 'render_trust_strip' ), 5 );
		add_action( 'storefront_footer', array( $this, 'render_footer_navigation' ), 8 );
		add_action( 'wp', array( $this, 'customize_shop_loop' ) );
		add_action( 'woocommerce_archive_description', array( $this, 'render_catalog_intro' ), 5 );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_catalog_standards' ), 5 );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_product_card_metadata' ), 7 );
		add_action( 'wp_footer', array( $this, 'render_age_gate' ), 1 );
		add_action( 'wp_footer', array( $this, 'render_card_marks' ), 50 );
		add_filter( 'wp_nav_menu_args', array( $this, 'configure_primary_navigation' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'filter_primary_navigation' ), 10, 2 );
		add_filter( 'woocommerce_checkout_registration_required', '__return_true' );
		add_filter( 'woocommerce_enable_guest_checkout', '__return_false' );
		add_filter( 'comments_open', array( $this, 'disable_product_reviews' ), 20, 2 );
		add_filter( 'woocommerce_product_tabs', array( $this, 'add_coa_tab' ) );
		add_filter( 'woocommerce_page_title', array( $this, 'filter_catalog_title' ) );
		add_filter( 'storefront_credit_link', '__return_false' );
		add_filter( 'storefront_privacy_policy_link', '__return_false' );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_coa_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_coa_field' ) );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_products' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_account' ), 10, 2 );
		add_shortcode( 'compound_certificates', array( $this, 'render_certificates_directory' ) );
	}

	/**
	 * Remove the repeated controls Storefront renders below the product grid.
	 */
	public function customize_shop_loop(): void {
		remove_action( 'woocommerce_after_shop_loop', 'woocommerce_catalog_ordering', 10 );
		remove_action( 'woocommerce_after_shop_loop', 'woocommerce_result_count', 20 );
	}

	/**
	 * Give the shop archive a specific, scientific heading.
	 */
	public function filter_catalog_title( string $title ): string {
		return is_shop() ? __( 'Research compounds', 'compound-woocommerce' ) : $title;
	}

	/**
	 * Add a compact catalog introduction without delaying access to products.
	 */
	public function render_catalog_intro(): void {
		if ( ! is_shop() ) {
			return;
		}

		$certificates = get_page_by_path( 'certificates' );
		$url          = $certificates ? get_permalink( $certificates ) : home_url( '/certificates/' );
		?>
		<div class="compound-catalog-intro">
			<p><?php esc_html_e( 'Scientific identity, composition, pricing, and product-specific analytical documentation.', 'compound-woocommerce' ); ?></p>
			<a class="compound-text-link" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Browse certificates', 'compound-woocommerce' ); ?> <span aria-hidden="true">&rarr;</span></a>
		</div>
		<?php
	}

	/**
	 * Put verifiable store standards next to the catalog decision point.
	 */
	public function render_catalog_standards(): void {
		if ( ! is_shop() ) {
			return;
		}
		?>
		<section class="compound-catalog-standards" aria-label="<?php esc_attr_e( 'Catalog standards', 'compound-woocommerce' ); ?>">
			<span><?php esc_html_e( 'Product-specific COAs', 'compound-woocommerce' ); ?></span>
			<span><?php esc_html_e( 'Scientific identity information', 'compound-woocommerce' ); ?></span>
			<span><?php esc_html_e( 'Account-required checkout', 'compound-woocommerce' ); ?></span>
		</section>
		<?php
	}

	/**
	 * Show availability and direct analytical-document access on product cards.
	 */
	public function render_product_card_metadata(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$coa_url = $product->get_meta( self::COA_META_KEY );
		?>
		<div class="compound-product-evidence">
			<span class="compound-product-status <?php echo $product->is_in_stock() ? 'is-available' : 'is-unavailable'; ?>">
				<?php echo esc_html( $product->is_in_stock() ? __( 'Available', 'compound-woocommerce' ) : __( 'Unavailable', 'compound-woocommerce' ) ); ?>
			</span>
			<?php if ( $coa_url ) : ?>
				<a href="<?php echo esc_url( $coa_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View COA', 'compound-woocommerce' ); ?></a>
			<?php else : ?>
				<span><?php esc_html_e( 'COA unavailable', 'compound-woocommerce' ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Keep Storefront's desktop and mobile navigation limited to the customer journeys.
	 *
	 * @param array $args Navigation arguments.
	 * @return array
	 */
	public function configure_primary_navigation( array $args ): array {
		$location = $args['theme_location'] ?? '';
		if ( in_array( $location, array( 'primary', 'handheld' ), true ) ) {
			$args['fallback_cb'] = array( $this, 'render_primary_navigation_fallback' );
		}

		return $args;
	}

	/**
	 * Replace assigned primary menu items with the storefront's four-item navigation.
	 *
	 * @param string   $items Rendered menu items.
	 * @param stdClass $args  Navigation arguments.
	 * @return string
	 */
	public function filter_primary_navigation( string $items, stdClass $args ): string {
		if ( in_array( $args->theme_location ?? '', array( 'primary', 'handheld' ), true ) ) {
			return $this->get_primary_navigation_items();
		}

		return $items;
	}

	/**
	 * Render the same navigation when no WordPress menu has been assigned.
	 *
	 * @param array $args Navigation arguments.
	 * @return string|void
	 */
	public function render_primary_navigation_fallback( array $args ) {
		$menu_id = ! empty( $args['menu_id'] ) ? $args['menu_id'] : 'menu-' . ( $args['theme_location'] ?? 'primary' );
		$menu    = sprintf(
			'<ul id="%1$s" class="%2$s">%3$s</ul>',
			esc_attr( $menu_id ),
			esc_attr( $args['menu_class'] ?? 'menu' ),
			$this->get_primary_navigation_items()
		);
		if ( ! empty( $args['container_class'] ) ) {
			$menu = sprintf(
				'<div class="%1$s">%2$s</div>',
				esc_attr( $args['container_class'] ),
				$menu
			);
		}

		if ( ! empty( $args['echo'] ) ) {
			echo wp_kses_post( $menu );
			return;
		}

		return $menu;
	}

	/**
	 * Build the primary navigation shared by desktop and mobile menus.
	 */
	private function get_primary_navigation_items(): string {
		$certificates = get_page_by_path( 'certificates' );
		$links        = array(
			__( 'Catalog', 'compound-woocommerce' )      => home_url( '/shop/' ),
			__( 'Certificates', 'compound-woocommerce' ) => $certificates ? get_permalink( $certificates ) : home_url( '/certificates/' ),
			__( 'My Account', 'compound-woocommerce' )   => wc_get_page_permalink( 'myaccount' ),
		);
		$items        = '';

		foreach ( $links as $label => $url ) {
			$items .= sprintf(
				'<li class="menu-item"><a href="%1$s">%2$s</a></li>',
				esc_url( $url ),
				esc_html( $label )
			);
		}

		return $items;
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

	public function render_trust_strip(): void {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return;
		}
		?>
		<section class="compound-trust" aria-label="<?php esc_attr_e( 'Store standards', 'compound-woocommerce' ); ?>">
			<div class="col-full compound-trust__grid">
				<div><span class="compound-trust__icon">01</span><p><strong><?php esc_html_e( 'Documented', 'compound-woocommerce' ); ?></strong><?php esc_html_e( 'Product-specific COAs', 'compound-woocommerce' ); ?></p></div>
				<div><span class="compound-trust__icon">02</span><p><strong><?php esc_html_e( 'Transparent', 'compound-woocommerce' ); ?></strong><?php esc_html_e( 'Scientific naming', 'compound-woocommerce' ); ?></p></div>
				<div><span class="compound-trust__icon">03</span><p><strong><?php esc_html_e( 'Account required', 'compound-woocommerce' ); ?></strong><?php esc_html_e( 'Sign-in required at checkout', 'compound-woocommerce' ); ?></p></div>
			</div>
		</section>
		<?php
	}

	/**
	 * Keep policy and contact destinations visible without crowding primary navigation.
	 */
	public function render_footer_navigation(): void {
		$groups = array(
			__( 'Support', 'compound-woocommerce' ) => array(
				'certificates' => __( 'Certificates', 'compound-woocommerce' ),
				'contact'      => __( 'Contact', 'compound-woocommerce' ),
			),
			__( 'Policies', 'compound-woocommerce' ) => array(
				'terms-and-conditions' => __( 'Terms & Conditions', 'compound-woocommerce' ),
				'privacy-policy'       => __( 'Privacy Policy', 'compound-woocommerce' ),
				'shipping-policy'      => __( 'Shipping Policy', 'compound-woocommerce' ),
				'refunds-and-returns'  => __( 'Refunds & Returns', 'compound-woocommerce' ),
				'chargeback-policy'    => __( 'Chargeback Policy', 'compound-woocommerce' ),
			),
		);
		?>
		<nav class="compound-footer-navigation" aria-label="<?php esc_attr_e( 'Store information', 'compound-woocommerce' ); ?>">
			<?php foreach ( $groups as $group_label => $pages ) : ?>
				<div class="compound-footer-group">
					<h2><?php echo esc_html( $group_label ); ?></h2>
					<ul>
						<?php foreach ( $pages as $slug => $label ) : ?>
							<?php $page = get_page_by_path( $slug ); ?>
							<?php if ( $page && 'publish' === $page->post_status ) : ?>
								<li><a href="<?php echo esc_url( get_permalink( $page ) ); ?>"><?php echo esc_html( $label ); ?></a></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render a public directory of product-specific analytical documents.
	 */
	public function render_certificates_directory(): string {
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);

		if ( ! $products ) {
			return '<p>' . esc_html__( 'Product-specific analytical documents will appear here when products are published.', 'compound-woocommerce' ) . '</p>';
		}

		$rows = '';
		foreach ( $products as $product ) {
			$url      = $product->get_meta( self::COA_META_KEY );
			$lot      = $product->get_meta( self::LOT_META_KEY );
			$lab      = $product->get_meta( self::LAB_META_KEY );
			$date     = $product->get_meta( self::COA_DATE_META_KEY );
			$analysis = $product->get_meta( self::ANALYSIS_META_KEY );
			if ( $url ) {
				$document = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $url ),
					esc_html__( 'View certificate', 'compound-woocommerce' )
				);
			} else {
				$document = '<span>' . esc_html__( 'Certificate unavailable; this product cannot be purchased.', 'compound-woocommerce' ) . '</span>';
			}

			$rows .= sprintf(
				'<tr data-certificate-row><th scope="row"><a href="%1$s">%2$s</a></th><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( $product->get_permalink() ),
				esc_html( $product->get_name() ),
				esc_html( $lot ?: __( 'Not listed', 'compound-woocommerce' ) ),
				esc_html( $lab ?: __( 'Not listed', 'compound-woocommerce' ) ),
				esc_html( $analysis ?: __( 'Analytical report', 'compound-woocommerce' ) ),
				esc_html( $date ?: __( 'Not listed', 'compound-woocommerce' ) ),
				$document
			);
		}

		return sprintf(
			'<div class="compound-certificate-library"><p class="compound-certificate-library__intro">%1$s</p><label for="compound-certificate-search">%2$s</label><input id="compound-certificate-search" type="search" data-certificate-search placeholder="%3$s" autocomplete="off"><p class="screen-reader-text" data-certificate-status aria-live="polite"></p><div class="compound-certificate-table-wrap"><table class="compound-certificates"><thead><tr><th scope="col">%4$s</th><th scope="col">%5$s</th><th scope="col">%6$s</th><th scope="col">%7$s</th><th scope="col">%8$s</th><th scope="col">%9$s</th></tr></thead><tbody>%10$s</tbody></table></div><p class="compound-certificate-empty" data-certificate-empty hidden>%11$s</p></div>',
			esc_html__( 'Search published analytical documents by product, lot, laboratory, or analysis.', 'compound-woocommerce' ),
			esc_html__( 'Search certificates', 'compound-woocommerce' ),
			esc_attr__( 'Product, lot, laboratory, or analysis', 'compound-woocommerce' ),
			esc_html__( 'Product', 'compound-woocommerce' ),
			esc_html__( 'Lot', 'compound-woocommerce' ),
			esc_html__( 'Laboratory', 'compound-woocommerce' ),
			esc_html__( 'Analysis', 'compound-woocommerce' ),
			esc_html__( 'Report date', 'compound-woocommerce' ),
			esc_html__( 'Document', 'compound-woocommerce' ),
			$rows,
			esc_html__( 'No certificate records match this search.', 'compound-woocommerce' )
		);
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
			<strong>Visa</strong><strong>American Express</strong><strong>Discover</strong>
			<span class="compound-card-marks__restriction"><?php esc_html_e( 'Mastercard is not accepted.', 'compound-woocommerce' ); ?></span>
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
		woocommerce_wp_text_input(
			array(
				'id'          => self::LOT_META_KEY,
				'label'       => __( 'Lot or batch number', 'compound-woocommerce' ),
				'description' => __( 'Lot identifier shown in the public certificate library.', 'compound-woocommerce' ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => self::LAB_META_KEY,
				'label' => __( 'Laboratory', 'compound-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => self::ANALYSIS_META_KEY,
				'label' => __( 'Analysis type', 'compound-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => self::COA_DATE_META_KEY,
				'label' => __( 'Report date', 'compound-woocommerce' ),
				'type'  => 'date',
			)
		);
	}

	public function save_coa_field( WC_Product $product ): void {
		// WooCommerce verifies the product editor nonce before this hook runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ self::COA_META_KEY ] ) ? esc_url_raw( wp_unslash( $_POST[ self::COA_META_KEY ] ) ) : '';
		$product->update_meta_data( self::COA_META_KEY, $value );

		foreach ( array( self::LOT_META_KEY, self::LAB_META_KEY, self::COA_DATE_META_KEY, self::ANALYSIS_META_KEY ) as $meta_key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) : '';
			$product->update_meta_data( $meta_key, $value );
		}
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

<?php
/**
 * The per-SKU screening questionnaire, asked at add-to-cart.
 *
 * The registration intake establishes the patient once. This is the other half: questions
 * tied to what is being bought, asked every time it goes in the cart, because a screening
 * answer is a fact about this purchase rather than a durable fact about the person.
 *
 * The cart is only ever populated with answered items. WooCommerce lets a filter refuse an
 * add-to-cart, so an unanswered gated product never lands in the cart at all, rather than
 * sitting there in a half-valid state that checkout has to police later.
 *
 * Answers ride on the cart item and are posted with the order. Compound resolves them against
 * the saved questionnaire: it supplies the labels and decides which answers hold the order,
 * so nothing here can relabel a question or claim an answer is unremarkable.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Screening {

	/** Cart item key holding this line's answers. */
	const CART_KEY = 'compound_intake_answers';

	/**
	 * Order line meta key. Underscore-prefixed on purpose: WooCommerce treats that as private
	 * and keeps it out of the admin line-item display, customer emails, and the order-received
	 * page. These are clinical answers, and they belong in Compound's audited surface rather
	 * than in every email the store sends.
	 */
	const ORDER_META = '_compound_intake_answers';

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'require_answers' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'attach_answers' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'show_answers_in_cart' ), 10, 2 );
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_fields' ) );
		// Cart item data does not become order line meta on its own; this is what carries the
		// answers across checkout so the gateway can send them.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_answers' ), 10, 3 );
	}

	/**
	 * The questionnaire for a product, or an empty list. Cached by the settings layer on the
	 * same TTL as the rest of the Compound config (seconds in sandbox, minutes in live), so
	 * an edit in the brand portal reaches the storefront without a plugin release.
	 *
	 * @param WC_Product $product The product being added.
	 * @return array[] Questions as Compound returned them.
	 */
	public static function questions_for( WC_Product $product ): array {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return array();
		}
		$sku = $product->get_sku();
		if ( '' === $sku ) {
			return array();
		}
		$cached = get_transient( self::transient_key( $sku ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = WC_Gen_Health_Settings::api()->telemedicine_questionnaire( $sku );
		if ( is_wp_error( $result ) || ! isset( $result['questions'] ) || ! is_array( $result['questions'] ) ) {
			// Fail open on the FORM only: an advisory read being down must not stop an
			// otherwise-valid purchase. Nothing about the gate depends on this - Compound
			// decides what holds an order, from the answers it actually received.
			return array();
		}
		set_transient( self::transient_key( $sku ), $result['questions'], WC_Gen_Health_Settings::cache_ttl() );
		return $result['questions'];
	}

	/**
	 * The transient one SKU's questionnaire is cached under.
	 *
	 * @param string $sku Product SKU.
	 */
	private static function transient_key( string $sku ): string {
		return 'compound_qnr_' . md5( $sku );
	}

	/** Drops every cached questionnaire. Called from the plugin's refresh paths. */
	public static function clear_cache(): void {
		global $wpdb;
		// Transients are option rows; this is the only way to drop a whole family of them.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_compound_qnr_%'
			    OR option_name LIKE '_transient_timeout_compound_qnr_%'"
		);
	}

	/**
	 * The questionnaire, under the add-to-cart button. Rendered inline rather than in a modal
	 * so it works without JavaScript and on a 360px screen, which is most of this traffic.
	 */
	public function render_fields(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$questions = self::questions_for( $product );
		if ( empty( $questions ) ) {
			return;
		}
		$previous = $this->previous_answers( $product->get_sku() );
		echo '<div class="compound-screening">';
		echo '<p class="compound-screening__intro">'
			. esc_html__( 'A few questions before you add this.', 'compound-woocommerce' ) . '</p>';
		foreach ( $questions as $question ) {
			$this->render_question( $question, $previous );
		}
		echo '</div>';
	}

	/**
	 * This customer's last answers for a SKU, so a reorder is confirmed rather than retyped.
	 * A guest has none, and that is fine: the questions simply start blank.
	 *
	 * @param string $sku Product SKU.
	 * @return array<string,string> Answers keyed by question_key.
	 */
	private function previous_answers( string $sku ): array {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->user_email ) {
			return array();
		}
		$result = WC_Gen_Health_Settings::api()->telemedicine_questionnaire( $sku, $user->user_email );
		if ( is_wp_error( $result ) || empty( $result['previous_answers'] ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $result['previous_answers'] as $a ) {
			if ( isset( $a['question_key'], $a['value'] ) ) {
				$out[ (string) $a['question_key'] ] = (string) $a['value'];
			}
		}
		return $out;
	}

	/**
	 * One question, pre-filled with what this customer last answered.
	 *
	 * @param array                $question Question row as Compound returned it.
	 * @param array<string,string> $previous This customer's last answers.
	 */
	private function render_question( array $question, array $previous ): void {
		$key = isset( $question['question_key'] ) ? (string) $question['question_key'] : '';
		if ( '' === $key ) {
			return;
		}
		$label   = isset( $question['label'] ) ? (string) $question['label'] : $key;
		$type    = isset( $question['input_type'] ) ? (string) $question['input_type'] : 'text';
		$help    = isset( $question['help_text'] ) ? (string) $question['help_text'] : '';
		$req     = ! empty( $question['required'] );
		$options = ( isset( $question['options'] ) && is_array( $question['options'] ) ) ? $question['options'] : array();
		$name    = 'compound_screening[' . $key . ']';
		$id      = 'compound-screening-' . $key;
		$value   = $previous[ $key ] ?? '';
		?>
		<p class="compound-wc-field compound-screening__field">
			<label class="compound-wc-field__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<?php if ( '' !== $help ) : ?>
				<span class="compound-wc-field__help"><?php echo esc_html( $help ); ?></span>
			<?php endif; ?>
			<?php if ( 'select' === $type ) : ?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $req ? 'required' : ''; ?>>
					<option value=""><?php esc_html_e( 'Choose...', 'compound-woocommerce' ); ?></option>
					<?php foreach ( $options as $option ) : ?>
						<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $value, (string) $option ); ?>>
							<?php echo esc_html( (string) $option ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'checkbox' === $type ) : ?>
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
					value="yes" <?php checked( 'yes', $value ); ?> <?php echo $req ? 'required' : ''; ?> />
			<?php elseif ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3"
					<?php echo $req ? 'required' : ''; ?>><?php echo esc_textarea( $value ); ?></textarea>
			<?php else : ?>
				<input type="<?php echo esc_attr( self::html_input_type( $type ) ); ?>" id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
					<?php echo $req ? 'required' : ''; ?> />
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Configured answer types mapped to HTML input types. Anything unrecognised renders as
	 * plain text rather than as an attribute the browser would not understand.
	 *
	 * @param string $type Configured answer type.
	 */
	private static function html_input_type( string $type ): string {
		$allowed = array( 'text', 'tel', 'email', 'date', 'number' );
		return in_array( $type, $allowed, true ) ? $type : 'text';
	}

	/**
	 * Refuses the add when a required question is unanswered, so the cart never holds an item
	 * that checkout would have to reject later.
	 *
	 * @param bool $passed     Whether the add is allowed so far.
	 * @param int  $product_id The product being added.
	 * @param int  $quantity   Quantity (unused; required by the filter signature).
	 * @return bool
	 */
	public function require_answers( $passed, $product_id, $quantity ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return $passed;
		}
		$questions = self::questions_for( $product );
		if ( empty( $questions ) ) {
			return $passed;
		}
		$answers = self::submitted_answers();
		foreach ( $questions as $question ) {
			if ( empty( $question['required'] ) ) {
				continue;
			}
			$key = isset( $question['question_key'] ) ? (string) $question['question_key'] : '';
			if ( '' === $key || '' !== trim( (string) ( $answers[ $key ] ?? '' ) ) ) {
				continue;
			}
			$label = isset( $question['label'] ) ? (string) $question['label'] : $key;
			/* translators: %s: the screening question's label, as configured by the brand. */
			wc_add_notice( sprintf( __( '%s is required before you can add this.', 'compound-woocommerce' ), $label ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Carries the answers on the cart item.
	 *
	 * They are also what makes two adds of the same product distinct lines: a customer buying
	 * for two different reasons should not have one set of answers silently cover both.
	 *
	 * @param array $data       Existing cart item data.
	 * @param int   $product_id The product being added.
	 * @return array
	 */
	public function attach_answers( $data, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || empty( self::questions_for( $product ) ) ) {
			return $data;
		}
		$answers = self::submitted_answers();
		if ( ! empty( $answers ) ) {
			$data[ self::CART_KEY ] = $answers;
		}
		return $data;
	}

	/**
	 * Copies a cart line's answers onto the order line at checkout.
	 *
	 * @param WC_Order_Item_Product $item          The order line being created.
	 * @param string                $cart_item_key Cart key (unused; required by the signature).
	 * @param array                 $values        The cart item.
	 */
	public function persist_answers( $item, $cart_item_key, $values ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! empty( $values[ self::CART_KEY ] ) && is_array( $values[ self::CART_KEY ] ) ) {
			$item->add_meta_data( self::ORDER_META, $values[ self::CART_KEY ], true );
		}
	}

	/**
	 * Shows the answers on the cart line, so a customer can see what they said before paying.
	 *
	 * @param array $item_data Existing display rows.
	 * @param array $cart_item The cart item.
	 * @return array
	 */
	public function show_answers_in_cart( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}
		$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;
		// Labels come from the questionnaire, not from the cart, so what a customer reviews is
		// what they were asked.
		$labels = array();
		if ( $product ) {
			foreach ( self::questions_for( $product ) as $q ) {
				if ( isset( $q['question_key'], $q['label'] ) ) {
					$labels[ (string) $q['question_key'] ] = (string) $q['label'];
				}
			}
		}
		foreach ( $cart_item[ self::CART_KEY ] as $key => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$item_data[] = array(
				'key'   => $labels[ $key ] ?? $key,
				'value' => (string) $value,
			);
		}
		return $item_data;
	}

	/**
	 * The answers posted with an add-to-cart, sanitized.
	 *
	 * @return array<string,string>
	 */
	public static function submitted_answers(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the add-to-cart request before these filters run.
		if ( empty( $_POST['compound_screening'] ) || ! is_array( $_POST['compound_screening'] ) ) {
			return array();
		}
		$raw = map_deep( wp_unslash( $_POST['compound_screening'] ), 'sanitize_textarea_field' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$out = array();
		foreach ( (array) $raw as $key => $value ) {
			$out[ sanitize_key( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $out;
	}
}

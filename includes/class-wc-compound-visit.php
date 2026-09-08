<?php
/**
 * The patient-facing "join your visit" surface.
 *
 * Some telehealth providers run a live video visit and hand back a link the patient has to
 * open; others review an intake asynchronously and hand back nothing. This plugin is not told
 * which provider Compound routed to, and does not need to be: a consult either carries a
 * meeting_url or it does not, and that single fact drives everything here. That keeps the two
 * flows working without this plugin ever learning, or leaking, who the provider is.
 *
 * The visit deliberately opens in a NEW TAB rather than an iframe. Providers serve their
 * meeting pages with `X-Frame-Options: SAMEORIGIN`, so a cross-origin embed is refused by the
 * browser, and a video call embedded in a merchant theme would in any case be at the mercy of
 * that theme's CSS and its own camera/microphone permissions policy.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Visit {

	public function register(): void {
		add_action( 'woocommerce_thankyou', array( $this, 'render_on_order_received' ), 20 );
	}

	/**
	 * Order-received page. This is where a patient lands straight after paying, so a visit
	 * they need to join has to be here and not only in My Account, which they may never open.
	 *
	 * Reads the customer from the ORDER rather than the session: guest checkout is possible,
	 * and the billing email is the one the consult was created against.
	 *
	 * @param int $order_id WooCommerce order id.
	 */
	public function render_on_order_received( $order_id ): void {
		if ( ! WC_Gen_Health_Settings::is_active() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email = (string) $order->get_billing_email();
		if ( '' === $email ) {
			return;
		}
		self::render_join( self::joinable_for( $email ) );
	}

	/**
	 * Pull the joinable consult for a customer, if there is one.
	 *
	 * "Joinable" means pending with a meeting link: an approved or denied consult is over, and
	 * a consult with no link never had a live visit to join. Returns null rather than an error
	 * when Compound cannot be reached, because this is a convenience surface and must never be
	 * the reason a page fails to render.
	 *
	 * @param string $email Customer's account email.
	 * @return array|null {consult_id, meeting_url, status, product_sku}
	 */
	public static function joinable_for( string $email ): ?array {
		$result = WC_Gen_Health_Settings::api()->telemedicine_consults( $email );
		if ( is_wp_error( $result ) || empty( $result['consults'] ) ) {
			return null;
		}
		foreach ( $result['consults'] as $consult ) {
			$url = isset( $consult['meeting_url'] ) ? (string) $consult['meeting_url'] : '';
			if ( '' === $url ) {
				continue;
			}
			if ( 'pending' !== ( $consult['status'] ?? '' ) ) {
				continue;
			}
			// Only ever hand the browser an http(s) link we got from our own API. A provider
			// returning something else (or a javascript: URL) must not become a link here.
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			return array(
				'consult_id'  => (string) ( $consult['id'] ?? '' ),
				'meeting_url' => $url,
				'status'      => (string) ( $consult['status'] ?? '' ),
				'product_sku' => (string) ( $consult['product_sku'] ?? '' ),
			);
		}
		return null;
	}

	/**
	 * The join call-to-action. Echoes nothing when there is no live visit to join, so callers
	 * can drop it anywhere without checking first.
	 *
	 * @param array|null $visit Result of joinable_for(), or null.
	 */
	public static function render_join( ?array $visit ): void {
		if ( null === $visit ) {
			return;
		}
		?>
		<div class="compound-wc-visit">
			<p class="compound-wc-visit__lead">
				<?php esc_html_e( 'A clinician is ready to see you. Your visit opens in a new tab.', 'compound-woocommerce' ); ?>
			</p>
			<p>
				<a
					class="button compound-wc-visit__button"
					href="<?php echo esc_url( $visit['meeting_url'] ); ?>"
					target="_blank"
					rel="noopener noreferrer"
				><?php esc_html_e( 'Join your visit', 'compound-woocommerce' ); ?></a>
			</p>
			<p class="compound-wc-visit__note">
				<?php esc_html_e( 'You can come back to this page and rejoin if the tab closes.', 'compound-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The message for a consult with no live visit: an asynchronous review. Stated as fact,
	 * with no promise about timing this plugin cannot keep.
	 */
	public static function render_async_notice(): void {
		?>
		<p class="compound-wc-visit__pending">
			<?php esc_html_e( 'A clinician is reviewing your intake. You will get an email when there is an update.', 'compound-woocommerce' ); ?>
		</p>
		<?php
	}
}

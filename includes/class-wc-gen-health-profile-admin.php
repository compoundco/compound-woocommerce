<?php
/**
 * A "Telemedicine" panel on the WP user-profile screen: consult status and fills remaining
 * for each of this customer's gated products, read live from Compound
 * (WC_Compound_API::telemedicine_consults()) - this plugin keeps no local copy of any of it.
 * The prescription PDF is never stored - "View prescription" fetches a fresh, short-lived
 * link from Compound (which itself fetches fresh from the telehealth provider) on click and
 * redirects straight to it.
 *
 * No existing precedent in this plugin for a user-profile-screen panel (the closest analog,
 * class-wc-compound-order-admin.php, is order-screen); built fresh here.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gen_Health_Profile_Admin {

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'admin_post_gen_health_fetch_rx_pdf', array( $this, 'fetch_pdf' ) );
	}

	public function render( WP_User $user ): void {
		if ( ! WC_Gen_Health_Settings::is_active() || ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$result   = WC_Gen_Health_Settings::api()->telemedicine_consults( $user->user_email );
		$consults = is_wp_error( $result ) ? array() : ( is_array( $result['consults'] ?? null ) ? $result['consults'] : array() );

		echo '<h2>' . esc_html__( 'Telemedicine', 'compound-woocommerce' ) . '</h2>';
		if ( is_wp_error( $result ) ) {
			echo '<p>' . esc_html__( 'Could not reach Compound to load telemedicine status.', 'compound-woocommerce' ) . '</p>';
			return;
		}
		if ( empty( $consults ) ) {
			echo '<p>' . esc_html__( 'No intake on file.', 'compound-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $consults as $consult ) {
			$this->render_row( $consult );
		}
		echo '</tbody></table>';
	}

	private function render_row( array $consult ): void {
		echo '<tr><th>' . esc_html( (string) ( $consult['product_sku'] ?? '' ) ) . '</th><td>';
		printf( '%s: <strong>%s</strong>', esc_html__( 'Status', 'compound-woocommerce' ), esc_html( (string) ( $consult['status'] ?? '' ) ) );
		if ( 'approved' === ( $consult['status'] ?? '' ) ) {
			printf(
				' &mdash; %s: %d/%d',
				esc_html__( 'fills remaining', 'compound-woocommerce' ),
				(int) ( $consult['fills_remaining'] ?? 0 ),
				(int) ( $consult['fills_total'] ?? 0 )
			);
			$consult_id = (string) ( $consult['id'] ?? '' );
			if ( '' !== $consult_id ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action'     => 'gen_health_fetch_rx_pdf',
							'consult_id' => $consult_id,
						),
						admin_url( 'admin-post.php' )
					),
					'gen_health_fetch_rx_pdf'
				);
				echo ' &mdash; <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View prescription', 'compound-woocommerce' ) . '</a>';
			}
		}
		echo '</td></tr>';
	}

	public function fetch_pdf(): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'compound-woocommerce' ) );
		}
		check_admin_referer( 'gen_health_fetch_rx_pdf' );
		$consult_id = isset( $_GET['consult_id'] ) ? sanitize_text_field( wp_unslash( $_GET['consult_id'] ) ) : '';
		if ( '' === $consult_id ) {
			wp_die( esc_html__( 'Missing consult id.', 'compound-woocommerce' ) );
		}
		$result = WC_Gen_Health_Settings::api()->telemedicine_consult_detail( $consult_id );
		if ( is_wp_error( $result ) || empty( $result['pdf_url'] ) ) {
			wp_die( esc_html__( 'Could not retrieve the prescription.', 'compound-woocommerce' ) );
		}
		wp_safe_redirect( esc_url_raw( $result['pdf_url'] ) );
		exit;
	}
}

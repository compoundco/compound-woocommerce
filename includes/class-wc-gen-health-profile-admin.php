<?php
/**
 * A "Telemedicine (Gen Health)" panel on the WP user-profile screen: intake status,
 * patientId, and per-medication RX status + fills remaining. The Rx PDF is never stored -
 * "View Rx PDF" fetches a fresh, short-lived signed URL from Gen Health on click and
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

		$patient_id = get_user_meta( $user->ID, WC_Gen_Health_Intake::PATIENT_ID_META, true );
		echo '<h2>' . esc_html__( 'Telemedicine (Gen Health)', 'compound-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Patient ID', 'compound-woocommerce' ) . '</th><td>';
		echo $patient_id ? '<code>' . esc_html( $patient_id ) . '</code>' : esc_html__( 'No intake on file.', 'compound-woocommerce' );
		echo '</td></tr>';

		foreach ( $this->gated_products() as $client_product_id => $label ) {
			$request = WC_Gen_Health_Rx::get_request( $user->ID, $client_product_id );
			if ( null === $request ) {
				continue;
			}
			echo '<tr><th>' . esc_html( $label ) . '</th><td>';
			printf( '%s: <strong>%s</strong>', esc_html__( 'Status', 'compound-woocommerce' ), esc_html( $request['status'] ) );
			if ( 'approved' === $request['status'] ) {
				printf(
					' &mdash; %s &mdash; %s: %d/%d',
					esc_html( $request['medication'] ),
					esc_html__( 'fills remaining', 'compound-woocommerce' ),
					(int) $request['fills_remaining'],
					(int) $request['fills_total']
				);
				if ( '' !== $request['prescription_id'] ) {
					$url = wp_nonce_url(
						add_query_arg(
							array(
								'action'          => 'gen_health_fetch_rx_pdf',
								'prescription_id' => $request['prescription_id'],
							),
							admin_url( 'admin-post.php' )
						),
						'gen_health_fetch_rx_pdf'
					);
					echo ' &mdash; <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Rx PDF', 'compound-woocommerce' ) . '</a>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function fetch_pdf(): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'compound-woocommerce' ) );
		}
		check_admin_referer( 'gen_health_fetch_rx_pdf' );
		$prescription_id = isset( $_GET['prescription_id'] ) ? sanitize_text_field( wp_unslash( $_GET['prescription_id'] ) ) : '';
		if ( '' === $prescription_id ) {
			wp_die( esc_html__( 'Missing prescription id.', 'compound-woocommerce' ) );
		}
		$result = WC_Gen_Health_Settings::api()->get_prescription( $prescription_id );
		if ( is_wp_error( $result ) || empty( $result['pdfUrl'] ) ) {
			wp_die( esc_html__( 'Could not retrieve the prescription PDF.', 'compound-woocommerce' ) );
		}
		wp_safe_redirect( esc_url_raw( $result['pdfUrl'] ) );
		exit;
	}

	/**
	 * Published products flagged as requiring a consult.
	 *
	 * @return array<string, string> client_product_id => product name.
	 */
	private function gated_products(): array {
		$ids = wc_get_products(
			array(
				'status'     => 'publish',
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => WC_Gen_Health_Product_Meta::REQUIRES_CONSULT_META,
						'value' => 'yes',
					),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$client_product_id = WC_Gen_Health_Product_Meta::client_product_id( $product );
			if ( '' === $client_product_id ) {
				continue;
			}
			$out[ $client_product_id ] = $product->get_name();
		}
		return $out;
	}
}

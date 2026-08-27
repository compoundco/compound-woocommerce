<?php
/**
 * Minimal, dependency-free Sentry error reporting. The plugin ships with no vendor/
 * runtime dependencies by design (composer's require-dev tools are lint-only - see
 * bin/build-zip.sh) - the full Sentry PHP SDK pulls in symfony/http-client and would
 * be the first runtime dependency this plugin ever shipped, in a shared WordPress
 * environment where that's a real collision risk. This hand-rolls the minimum Sentry
 * envelope format instead, using the same wp_remote_post() idiom already used for the
 * Compound API client (class-wc-compound-api.php).
 *
 * Off by default: only reports when COMPOUND_WC_SENTRY_DSN is defined in wp-config.php,
 * same opt-in shape as COMPOUND_WC_ENABLE_COMPLIANCE.
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Sentry {

	/**
	 * Report an exception-shaped error to Sentry. Fire-and-forget (non-blocking
	 * wp_remote_post) so a Sentry outage or slow DNS can never add latency to a real
	 * checkout or webhook request - the exact class of failure this exists to catch.
	 *
	 * @param string $message Human-readable error message (no PHI, no card data).
	 * @param array  $context Extra key/value context, merged into the event's `extra`.
	 */
	public static function report( string $message, array $context = array() ): void {
		$dsn = defined( 'COMPOUND_WC_SENTRY_DSN' ) ? (string) COMPOUND_WC_SENTRY_DSN : '';
		if ( '' === $dsn ) {
			return;
		}

		$parsed = self::parse_dsn( $dsn );
		if ( null === $parsed ) {
			return;
		}

		$environment = defined( 'COMPOUND_WC_SENTRY_ENVIRONMENT' ) ? (string) COMPOUND_WC_SENTRY_ENVIRONMENT : 'production';
		$event_id    = str_replace( '-', '', wp_generate_uuid4() );
		$timestamp   = gmdate( 'Y-m-d\TH:i:s\Z' );

		$event = array(
			'event_id'    => $event_id,
			'timestamp'   => $timestamp,
			'platform'    => 'php',
			'environment' => $environment,
			'release'     => 'compound-woocommerce@' . COMPOUND_WC_VERSION,
			'server_name' => wp_parse_url( home_url(), PHP_URL_HOST ),
			'exception'   => array(
				'values' => array(
					array(
						'type'  => 'CompoundWooCommerceError',
						'value' => $message,
					),
				),
			),
			'extra'       => $context,
		);

		$envelope_header = wp_json_encode(
			array(
				'event_id' => $event_id,
				'sent_at'  => $timestamp,
			)
		);
		$item_header     = wp_json_encode( array( 'type' => 'event' ) );
		$body            = $envelope_header . "\n" . $item_header . "\n" . wp_json_encode( $event );

		$auth = sprintf(
			'Sentry sentry_version=7, sentry_client=compound-woocommerce/%s, sentry_key=%s',
			COMPOUND_WC_VERSION,
			$parsed['public_key']
		);

		wp_remote_post(
			sprintf( 'https://%s/api/%s/envelope/', $parsed['host'], $parsed['project_id'] ),
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'  => 'application/x-sentry-envelope',
					'X-Sentry-Auth' => $auth,
				),
				'body'     => $body,
			)
		);
	}

	/**
	 * Break a Sentry DSN (https://<public_key>@<host>/<project_id>) into its parts.
	 *
	 * @param string $dsn The DSN.
	 * @return array{host: string, public_key: string, project_id: string}|null
	 */
	private static function parse_dsn( string $dsn ): ?array {
		$parts = wp_parse_url( $dsn );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['user'] ) || empty( $parts['path'] ) ) {
			return null;
		}
		return array(
			'host'       => $parts['host'],
			'public_key' => $parts['user'],
			'project_id' => ltrim( $parts['path'], '/' ),
		);
	}
}

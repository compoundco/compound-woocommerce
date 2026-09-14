<?php
/**
 * Keeps a WooCommerce order's refunds in sync with Compound's, in both directions.
 *
 * A refund can be initiated from either side: the merchant clicks "Refund" in wp-admin
 * (WC_Gateway_Compound::process_refund(), which calls Compound), or an operator refunds the
 * charge from the Compound admin portal (which reaches this store only as an inbound webhook,
 * WC_Compound_Webhooks). Compound is authoritative either way - its refund is the one that
 * actually moved money - so both paths funnel through the same idempotency guard here rather
 * than each maintaining its own: a refund created on one side must not be recreated when the
 * other side later hears about the exact same event.
 *
 * The guard is Compound's own refund_id, recorded twice: as meta on the WC_Order_Refund object
 * itself (so a human looking at the refund can see it came from Compound), and as an entry in
 * an array on the parent order (so checking "have we already applied this one" is a single
 * meta read, not a scan of every refund object on the order).
 *
 * @package Compound\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Compound_Refunds {

	/** Order meta: JSON array of Compound refund ids already reflected on this order. */
	const SYNCED_META = '_compound_synced_refund_ids';

	/** Refund-object meta: which Compound refund this WC_Order_Refund corresponds to. */
	const REFUND_ID_META = '_compound_refund_id';

	/**
	 * Whether this exact Compound refund is already reflected on the order, from either
	 * direction. Checked before creating anything, so a webhook echoing back a refund the
	 * merchant just made via wp-admin is a no-op, and a refund made via the Compound portal is
	 * never applied twice if its webhook is redelivered.
	 *
	 * @param WC_Order $order     The order.
	 * @param string   $refund_id Compound's refund id.
	 */
	public static function already_synced( WC_Order $order, string $refund_id ): bool {
		return in_array( $refund_id, self::synced_ids( $order ), true );
	}

	/**
	 * Records that a Compound refund is now reflected, without touching any refund object.
	 * Called by process_refund() once Compound confirms, in case tagging the specific
	 * WC_Order_Refund object (see tag_refund()) cannot find one to attach to.
	 *
	 * @param WC_Order $order     The order.
	 * @param string   $refund_id Compound's refund id.
	 */
	public static function mark_synced( WC_Order $order, string $refund_id ): void {
		$ids = self::synced_ids( $order );
		if ( in_array( $refund_id, $ids, true ) ) {
			return;
		}
		$ids[] = $refund_id;
		$order->update_meta_data( self::SYNCED_META, wp_json_encode( $ids ) );
		$order->save_meta_data();
	}

	/**
	 * Tags a specific WC_Order_Refund object as the WooCommerce-side record of a given
	 * Compound refund, and marks it synced on the parent order in the same call.
	 *
	 * @param WC_Order_Refund $refund    The WooCommerce refund object.
	 * @param WC_Order        $order     Its parent order.
	 * @param string          $refund_id Compound's refund id.
	 */
	public static function tag_refund( WC_Order_Refund $refund, WC_Order $order, string $refund_id ): void {
		$refund->update_meta_data( self::REFUND_ID_META, $refund_id );
		$refund->save_meta_data();
		self::mark_synced( $order, $refund_id );
		// Checked here rather than only in apply_incoming(), so a full refund made via
		// process_refund() (the merchant's own "Refund" button) reaches the same "Refunded"
		// status as one that arrived from the Compound portal.
		self::maybe_mark_fully_refunded( $order );
	}

	/**
	 * Creates the WooCommerce-side refund record for a refund that happened on Compound's
	 * side first (the Compound admin portal, not this store). Used only by the inbound
	 * webhook handler - a refund that originated here already has its WC_Order_Refund from
	 * WooCommerce's own "Refund" button, before process_refund() ever ran.
	 *
	 * @param WC_Order $order        The order.
	 * @param string   $refund_id    Compound's refund id.
	 * @param int      $amount_cents Refunded amount, in cents.
	 * @param string   $reason       Compound's recorded reason, if any.
	 * @return WC_Order_Refund|WP_Error
	 */
	public static function apply_incoming( WC_Order $order, string $refund_id, int $amount_cents, string $reason ) {
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => round( $amount_cents / 100, 2 ),
				'reason'   => $reason ? $reason : __( 'Refunded via Compound.', 'compound-woocommerce' ),
			)
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		self::tag_refund( $refund, $order, $refund_id );
		$order->add_order_note(
			sprintf(
				/* translators: 1: refunded amount, 2: Compound refund id */
				__( 'Refunded %1$s via Compound (refund %2$s).', 'compound-woocommerce' ),
				wc_price( $amount_cents / 100 ),
				$refund_id
			)
		);
		return $refund;
	}

	/**
	 * Finds the WC_Order_Refund WooCommerce already created for an in-progress
	 * process_refund() call - the merchant's "Refund" button creates that record BEFORE
	 * calling the gateway, so by the time process_refund() runs it already exists locally and
	 * only needs to be tagged, not created again.
	 *
	 * Identified as the newest refund on the order carrying no Compound tag yet: every refund
	 * already synced (from either direction) is tagged the moment it is, so an untagged one is
	 * necessarily the one WooCommerce just made for this call.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function find_untagged_refund( WC_Order $order ): ?WC_Order_Refund {
		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund->get_meta( self::REFUND_ID_META ) ) {
				return $refund;
			}
		}
		return null;
	}

	/**
	 * Moves the order to WooCommerce's native "Refunded" status once nothing remains to
	 * refund. Left alone otherwise - a partial refund is still a fact worth a note, not a
	 * status change.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function maybe_mark_fully_refunded( WC_Order $order ): void {
		if ( $order->get_remaining_refund_amount() > 0 ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'refunded', 'cancelled' ), true ) ) {
			return;
		}
		$order->update_status( 'refunded', __( 'Fully refunded via Compound.', 'compound-woocommerce' ) );
	}

	/**
	 * Compound refund ids already reflected on this order.
	 *
	 * @param WC_Order $order The order.
	 * @return string[]
	 */
	private static function synced_ids( WC_Order $order ): array {
		$raw = $order->get_meta( self::SYNCED_META );
		$ids = $raw ? json_decode( (string) $raw, true ) : array();
		return is_array( $ids ) ? array_map( 'strval', $ids ) : array();
	}
}

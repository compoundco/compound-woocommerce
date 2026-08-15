#!/usr/bin/env bash
# Simulate a pharmacy fulfillment event so you can watch the order advance in Compound
# AND the outbound webhook update the WooCommerce order status.
#
# Usage:  bin/sim-fulfillment.sh <shipped|delivered|exception> <compound_order_id>
#
# Find the <compound_order_id> in the Compound admin portal (Orders -> the order, its
# mono id) or in the WooCommerce order notes ("Compound order ord_...").
set -euo pipefail

EVENT="${1:-}"
ORDER_ID="${2:-}"
SUPPLY_URL="${COMPOUND_SUPPLY_URL:-http://localhost:4006}"
INTERNAL_SECRET="${INTERNAL_EVENT_SECRET:-dev-internal-secret}"

if [ -z "$EVENT" ] || [ -z "$ORDER_ID" ]; then
  echo "usage: bin/sim-fulfillment.sh <shipped|delivered|exception> <compound_order_id>"
  exit 1
fi

case "$EVENT" in
  shipped)   BODY="{\"order_id\":\"$ORDER_ID\",\"type\":\"fulfillment.shipped\",\"carrier\":\"UPS\",\"tracking_number\":\"1Z-TEST-$(date +%s)\"}" ;;
  delivered) BODY="{\"order_id\":\"$ORDER_ID\",\"type\":\"fulfillment.delivered\"}" ;;
  exception) BODY="{\"order_id\":\"$ORDER_ID\",\"type\":\"fulfillment.exception\",\"exception_type\":\"cold_chain_excursion\",\"detail\":\"temperature excursion\"}" ;;
  *) echo "unknown event: $EVENT (use shipped|delivered|exception)"; exit 1 ;;
esac

echo "==> $EVENT for $ORDER_ID"
curl -s -X POST "$SUPPLY_URL/internal/sim/fulfillment" \
  -H "x-internal-secret: $INTERNAL_SECRET" -H 'Content-Type: application/json' \
  -d "$BODY"
echo ""
echo "The order advances in Compound; the outbound worker delivers order.$EVENT to the store"
echo "within a couple of seconds - refresh the WooCommerce order to see the status/notes update."

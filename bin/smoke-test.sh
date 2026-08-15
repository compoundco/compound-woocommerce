#!/usr/bin/env bash
# Headless end-to-end check: build a WooCommerce order for a seeded product and run
# the Compound gateway's process_payment against your local Compound services (no
# browser). Prereqs: the store is up (make dev) + seeded (make seed), Compound is up
# (make dev && make seed in the compound repo).
#
# Prints the WC status + the Compound order/charge ids. Then advance fulfillment with:
#   make sim-shipped   ORDER=<compound_order>
#   make sim-delivered ORDER=<compound_order>
set -euo pipefail
cd "$(dirname "$0")/.."

CLI="$(docker ps --format '{{.Names}}' | grep -E -- '-cli-1$' | grep -v tests | head -1 || true)"
if [ -z "$CLI" ]; then
  echo "No WordPress test site running. Start one first:  make dev"
  exit 1
fi

docker exec "$CLI" wp --allow-root eval '
$pid = wc_get_product_id_by_sku("glp1-starter");
if (!$pid) { fwrite(STDERR, "no product for sku glp1-starter; run: make seed\n"); exit(1); }
$order = wc_create_order();
$order->add_product(wc_get_product($pid), 1);
$order->set_billing_email("tester@example.com");
$order->set_shipping_first_name("Test");
$order->set_shipping_last_name("Patient");
$order->set_shipping_address_1("123 Main St");
$order->set_shipping_city("San Francisco");
$order->set_shipping_state("CA");
$order->set_shipping_postcode("94016");
$order->set_shipping_country("US");
$order->calculate_totals();
$gw = new WC_Gateway_Compound();
$res = $gw->process_payment($order->get_id());
$order = wc_get_order($order->get_id());
echo json_encode(array(
  "result"          => isset($res["result"]) ? $res["result"] : null,
  "wc_status"       => $order->get_status(),
  "compound_order"  => $order->get_meta("_compound_order_id"),
  "compound_charge" => $order->get_meta("_compound_charge_id"),
)) . "\n";
'

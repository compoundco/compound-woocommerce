#!/usr/bin/env bash
# Headless end-to-end check: build a WooCommerce order for a seeded product and run
# the Compound gateway's process_payment against your local Compound services (no
# browser). Prereqs: `wp-env start` + `bin/setup-test-store.sh` done, Compound up.
set -euo pipefail

npx wp-env run cli -- wp eval '
$pid = wc_get_product_id_by_sku("glp1-starter");
if (!$pid) { fwrite(STDERR, "no product for sku glp1-starter; run setup-test-store.sh\n"); exit(1); }
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

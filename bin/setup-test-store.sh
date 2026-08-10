#!/usr/bin/env bash
# Configure the wp-env WooCommerce test store: dummy products (SKUs that match the
# Compound catalog seed), and the Compound gateway pointed at your local services.
#
# Prereqs: `npx wp-env start` is running, and the Compound stack is up (`make dev`)
# with the demo brand's catalog seeded + payments lanes provisioned + an API key.
#
# Usage: COMPOUND_API_KEY=sk_sandbox_... bin/setup-test-store.sh
set -euo pipefail

KEY="${COMPOUND_API_KEY:-}"
ORDERS_URL="${COMPOUND_ORDERS_URL:-http://host.docker.internal:4003}"
PAYMENTS_URL="${COMPOUND_PAYMENTS_URL:-http://host.docker.internal:4005}"
WEBHOOK_SECRET="${COMPOUND_WEBHOOK_SECRET:-dev-webhook-secret}"

if [ -z "$KEY" ]; then
  echo "Set COMPOUND_API_KEY to a Compound secret key (sk_...) with orders:write + charges:write."
  echo "Mint one in the admin portal Developers page (or via the identity API)."
  exit 1
fi

wpcli() { npx wp-env run cli -- wp "$@"; }

echo "Configuring store settings..."
wpcli option update woocommerce_currency USD >/dev/null
wpcli option update woocommerce_default_country "US:CA" >/dev/null
wpcli rewrite structure '/%postname%/' >/dev/null || true

create_product() {
  # $1 name, $2 sku, $3 price. SKUs MUST exist in the brand's Compound catalog.
  if wpcli wc product create --user=admin --name="$1" --sku="$2" --regular_price="$3" --type=simple --manage_stock=false >/dev/null 2>&1; then
    echo "  created product $2"
  else
    echo "  product $2 already exists (skipped)"
  fi
}

echo "Creating dummy products (SKUs match the Compound catalog seed)..."
create_product "GLP-1 Starter" "glp1-starter" "249"
create_product "Tirzepatide Pro" "tirz-pro" "399"
create_product "Recovery BPC-157" "recovery-bpc" "149"

echo "Configuring the Compound gateway..."
SETTINGS=$(cat <<JSON
{"enabled":"yes","title":"Card (Compound)","description":"Test checkout via the Compound sandbox.","environment":"sandbox","api_key":"${KEY}","orders_url":"${ORDERS_URL}","payments_url":"${PAYMENTS_URL}","webhook_secret":"${WEBHOOK_SECRET}"}
JSON
)
wpcli option update woocommerce_compound_settings "$SETTINGS" --format=json >/dev/null

echo ""
echo "Done."
echo "  Store:  http://localhost:8888"
echo "  Admin:  http://localhost:8888/wp-admin  (user: admin  pass: password)"
echo "  Gateway 'Card (Compound)' is enabled, pointed at:"
echo "    orders   -> ${ORDERS_URL}"
echo "    payments -> ${PAYMENTS_URL}"
echo "Place a test order at the store to exercise order-first intake + charge."

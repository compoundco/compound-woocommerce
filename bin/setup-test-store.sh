#!/usr/bin/env bash
# One-command setup for the WooCommerce test store, coordinated with the Compound
# demo brand so a new developer is testing in minutes.
#
# Prereqs:
#   1. Compound stack running:  (in the monorepo) make dev
#   2. A WordPress test site running (either works):
#        docker compose -f docker-compose.test.yml up -d      # prebuilt images
#        npx wp-env start                                     # standard tool
#
# Then:  bin/setup-test-store.sh
#
# It logs into the seeded demo brand, provisions payment lanes, mints an API key,
# seeds the SAME SKUs into the Compound catalog AND WooCommerce, applies the
# Storefront theme + branding, and points the gateway at your local services.
# Override any URL/credential via the COMPOUND_* env vars below.
set -euo pipefail
cd "$(dirname "$0")/.."

IDENTITY_URL="${COMPOUND_IDENTITY_URL:-http://localhost:4001}"
CATALOG_URL="${COMPOUND_CATALOG_URL:-http://localhost:4002}"
PAYMENTS_URL="${COMPOUND_PAYMENTS_URL:-http://localhost:4005}"
DEMO_EMAIL="${COMPOUND_DEMO_EMAIL:-demo@acmepeptides.com}"
DEMO_PASS="${COMPOUND_DEMO_PASS:-compound-demo-2026}"
# URLs the WordPress container uses to reach the host services:
GW_ORDERS_URL="${GW_ORDERS_URL:-http://host.docker.internal:4003}"
GW_PAYMENTS_URL="${GW_PAYMENTS_URL:-http://host.docker.internal:4005}"
WEBHOOK_SECRET="${COMPOUND_WEBHOOK_SECRET:-dev-webhook-secret}"

# The one canonical demo catalog. Each entry: sku|name|formulation|price|blurb.
# These SKUs match the Compound admin catalog seed (apps/admin-portal .../seed/catalog).
PRODUCTS=(
  "glp1-starter|GLP-1 Starter|form_sema_5|199|Semaglutide 5 mg/mL - a gentle starting dose."
  "glp1-plus|GLP-1 Plus|form_sema_10|299|Semaglutide 10 mg/mL - for established protocols."
  "tirz-pro|Tirzepatide Pro|form_tirz_10|399|Tirzepatide 10 mg/mL - dual-agonist."
  "recovery-bpc|Recovery BPC-157|form_bpc_5|149|BPC-157 5 mg - recovery support."
)

# --- WP-CLI via the running test-site container (wp-env or docker-compose) ------
CLI="$(docker ps --format '{{.Names}}' | grep -E -- '-cli-1$' | grep -v tests | head -1 || true)"
if [ -z "$CLI" ]; then
  echo "No WordPress test site running. Start one first:"
  echo "  docker compose -f docker-compose.test.yml up -d    (or: npx wp-env start)"
  exit 1
fi
wp() { docker exec "$CLI" wp "$@" --allow-root; }

echo "==> Compound: sign in as the demo brand + provision lanes + mint an API key"
BRAND="$(curl -s -X POST "$IDENTITY_URL/v1/sessions" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$DEMO_EMAIL\",\"password\":\"$DEMO_PASS\"}" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["user"]["brandId"])')"
curl -s -o /dev/null -X POST "$PAYMENTS_URL/v1/brands/$BRAND/processor_configs"
KEY="${COMPOUND_API_KEY:-$(curl -s -X POST "$IDENTITY_URL/v1/brands/$BRAND/apikeys" -H 'Content-Type: application/json' \
  -d '{"name":"woo-test-store","environment":"sandbox","scopes":["orders:write","charges:write","orders:read"]}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["key"])')}"
echo "    brand=$BRAND  key=${KEY:0:16}..."

echo "==> Compound catalog + WooCommerce: seed the canonical products (matched SKUs)"
# wipe existing Woo products so re-runs stay clean
for id in $(wp wc product list --user=admin --field=id 2>/dev/null); do wp wc product delete "$id" --force --user=admin >/dev/null 2>&1; done
for row in "${PRODUCTS[@]}"; do
  IFS='|' read -r sku name form price blurb <<< "$row"
  curl -s -o /dev/null -X POST "$CATALOG_URL/v1/products" -H 'Content-Type: application/json' \
    -d "{\"brand_id\":\"$BRAND\",\"sku\":\"$sku\",\"formulation_id\":\"$form\"}"
  wp wc product create --user=admin --name="$name" --sku="$sku" --regular_price="$price" \
    --short_description="$blurb" --type=simple --manage_stock=false >/dev/null 2>&1
  echo "    $sku  $name  \$$price"
done

echo "==> WooCommerce: theme + branding (Storefront)"
wp theme install storefront --activate >/dev/null 2>&1 || true
wp option update blogname "Acme Peptides" >/dev/null 2>&1
wp option update blogdescription "Research peptides, shipped fast" >/dev/null 2>&1
wp option update woocommerce_currency USD >/dev/null 2>&1
wp rewrite structure '/%postname%/' >/dev/null 2>&1 || true
SHOP="$(wp option get woocommerce_shop_page_id 2>/dev/null || echo '')"
[ -n "$SHOP" ] && { wp option update show_on_front page >/dev/null 2>&1; wp option update page_on_front "$SHOP" >/dev/null 2>&1; }
wp theme mod set storefront_header_background_color "#0f172a" >/dev/null 2>&1 || true
wp theme mod set storefront_header_text_color "#e2e8f0" >/dev/null 2>&1 || true
wp theme mod set storefront_header_link_color "#ffffff" >/dev/null 2>&1 || true
wp theme mod set storefront_accent_color "#2563eb" >/dev/null 2>&1 || true
wp theme mod set button_background_color "#2563eb" >/dev/null 2>&1 || true
wp theme mod set button_text_color "#ffffff" >/dev/null 2>&1 || true
wp theme mod set storefront_footer_background_color "#0f172a" >/dev/null 2>&1 || true

echo "==> WooCommerce: enable + configure the Compound gateway"
wp option update woocommerce_compound_settings \
  "{\"enabled\":\"yes\",\"title\":\"Card (Compound)\",\"description\":\"Test checkout via the Compound sandbox.\",\"environment\":\"sandbox\",\"api_key\":\"$KEY\",\"orders_url\":\"$GW_ORDERS_URL\",\"payments_url\":\"$GW_PAYMENTS_URL\",\"webhook_secret\":\"$WEBHOOK_SECRET\"}" \
  --format=json >/dev/null 2>&1
wp compound sync_coupons >/dev/null 2>&1 || true
wp cache flush >/dev/null 2>&1 || true

echo ""
echo "Done. Store:  http://localhost:8888   (admin: /wp-admin, user 'admin' / pass 'password')"
echo "Add a product to the cart and check out with 'Card (Compound)'."

#!/usr/bin/env bash
# One-command setup for the WooCommerce test store: seeds the storefront products,
# theme, and gateway so a new developer is checking out in minutes.
#
# This seeds the STORE only. The Compound side (the brand's payment lanes + the
# matching catalog SKUs) is seeded from the compound repo:
#
#   Prereqs:
#     1. Compound stack + demo data:  (in the compound repo)  make dev  &&  make seed
#     2. This store running:          (in this repo)          make dev
#
#   Then:  make seed   (or: bin/setup-test-store.sh)
#
# It signs into the seeded demo brand to mint an API key, seeds the WooCommerce
# products (SKUs matched to the Compound catalog), applies the Storefront theme +
# branding, and points the gateway at your local services. Override any
# URL/credential via the COMPOUND_* / GW_* env vars below.
set -euo pipefail
cd "$(dirname "$0")/.."

IDENTITY_URL="${COMPOUND_IDENTITY_URL:-http://localhost:4001}"
DEMO_EMAIL="${COMPOUND_DEMO_EMAIL:-demo@acmepeptides.com}"
DEMO_PASS="${COMPOUND_DEMO_PASS:-compound-demo-2026}"
# URLs the WordPress container uses to reach the host services:
GW_ORDERS_URL="${GW_ORDERS_URL:-http://host.docker.internal:4003}"
GW_PAYMENTS_URL="${GW_PAYMENTS_URL:-http://host.docker.internal:4005}"
WEBHOOK_SECRET="${COMPOUND_WEBHOOK_SECRET:-dev-webhook-secret}"

# Storefront products. Each entry: sku|name|price|blurb. The SKUs match the Compound
# catalog seeded by the compound repo's `make seed` (scripts/seed-demo.sh) so a
# checkout resolves; keep the two lists in sync.
PRODUCTS=(
  "glp1-starter|GLP-1 Starter|199|Semaglutide 5 mg/mL - a gentle starting dose."
  "glp1-plus|GLP-1 Plus|299|Semaglutide 10 mg/mL - for established protocols."
  "tirz-pro|Tirzepatide Pro|399|Tirzepatide 10 mg/mL - dual-agonist."
  "recovery-bpc|Recovery BPC-157|149|BPC-157 5 mg - recovery support."
)

# --- WP-CLI via the running test-site container (wp-env or docker-compose) ------
CLI="$(docker ps --format '{{.Names}}' | grep -E -- '-cli-1$' | grep -v tests | head -1 || true)"
if [ -z "$CLI" ]; then
  echo "No WordPress test site running. Start one first:  make dev"
  exit 1
fi
wp() { docker exec "$CLI" wp "$@" --allow-root; }

# Preflight: the Compound stack must be running. `make dev` compiles each Go service
# with `go run` (several seconds), so wait for identity to actually serve rather than
# checking once and racing its startup. Clear message instead of a JSON traceback.
ready=""
for _ in $(seq 1 30); do
  if curl -sf -o /dev/null "$IDENTITY_URL/healthz"; then ready=1; break; fi
  sleep 1
done
if [ -z "$ready" ]; then
  echo "Compound identity service isn't reachable at $IDENTITY_URL."
  echo "Start the Compound stack first: (in the compound repo) make dev && make seed"
  exit 1
fi

echo "==> Compound: sign in as the demo brand + mint an API key for the store"
SESSION="$(curl -s -X POST "$IDENTITY_URL/v1/sessions" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$DEMO_EMAIL\",\"password\":\"$DEMO_PASS\"}")"
BRAND="$(printf '%s' "$SESSION" | python3 -c 'import sys,json
try:
    print(json.load(sys.stdin)["user"]["brandId"])
except Exception:
    pass')"
if [ -z "$BRAND" ]; then
  echo "Could not sign in as $DEMO_EMAIL. Is the demo brand seeded (compound make dev sets SEED_DEMO=true)?"
  echo "Response was: ${SESSION:-<empty>}"
  exit 1
fi
KEY="${COMPOUND_API_KEY:-$(curl -s -X POST "$IDENTITY_URL/v1/brands/$BRAND/apikeys" -H 'Content-Type: application/json' \
  -d '{"name":"woo-test-store","environment":"sandbox","scopes":["orders:write","charges:write","orders:read"]}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["key"])')}"
echo "    brand=$BRAND  key=${KEY:0:16}..."

echo "==> WooCommerce: seed the storefront products (SKUs matched to the Compound catalog)"
# wipe existing Woo products so re-runs stay clean
for id in $(wp wc product list --user=admin --field=id 2>/dev/null); do wp wc product delete "$id" --force --user=admin >/dev/null 2>&1; done
for row in "${PRODUCTS[@]}"; do
  IFS='|' read -r sku name price blurb <<< "$row"
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
echo "Checkout needs the Compound catalog + payment lanes seeded: (in the compound repo) make seed."

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
# Host-side orders URL (this script runs on the host; registers the outbound webhook endpoint).
ORDERS_URL="${COMPOUND_ORDERS_URL:-http://localhost:4003}"
DEMO_EMAIL="${COMPOUND_DEMO_EMAIL:-demo@acmepeptides.com}"
DEMO_PASS="${COMPOUND_DEMO_PASS:-compound-demo-2026}"
# URLs the WordPress container uses to reach the host services:
GW_ORDERS_URL="${GW_ORDERS_URL:-http://host.docker.internal:4003}"
GW_PAYMENTS_URL="${GW_PAYMENTS_URL:-http://host.docker.internal:4005}"
# The store's webhook URL as Compound's outbound worker (on the host) reaches it:
STORE_WEBHOOK_URL="${COMPOUND_STORE_WEBHOOK_URL:-http://localhost:8888/wp-json/compound/v1/webhook}"
WEBHOOK_SECRET="${COMPOUND_WEBHOOK_SECRET:-dev-webhook-secret}"

# Storefront products. Each entry: sku|name|price|scientific description. The SKUs match the Compound
# catalog seeded by the compound repo's `make seed` (scripts/seed-demo.sh) so a
# checkout resolves; keep the two lists in sync.
PRODUCTS=(
  "glp1-starter|Semaglutide 5 mg/mL|199|Semaglutide is a synthetic peptide with the molecular formula C187H291N45O59. This listing provides product identity and analytical information only."
  "glp1-plus|Semaglutide 10 mg/mL|299|Semaglutide is a synthetic peptide with the molecular formula C187H291N45O59. This listing provides product identity and analytical information only."
  "tirz-pro|Tirzepatide 10 mg/mL|399|Tirzepatide is a synthetic peptide with the molecular formula C225H348N48O68. This listing provides product identity and analytical information only."
  "recovery-bpc|Pentadecapeptide BPC-157 5 mg|149|BPC-157 is a synthetic pentadecapeptide with the molecular formula C62H98N16O22. This listing provides product identity and analytical information only."
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

echo "==> Compound: register the outbound webhook endpoint -> this store"
# Compound's outbound worker (on the host) delivers order.* here as fulfillment progresses.
# Deactivate any prior endpoints (re-runs) so exactly one is active, then register a fresh one
# and use its signing secret for the gateway - so signatures match.
for id in $(curl -s "$ORDERS_URL/v1/brands/$BRAND/webhook_endpoints" \
  | python3 -c 'import sys,json;[print(e["id"]) for e in json.load(sys.stdin).get("endpoints",[])]' 2>/dev/null); do
  curl -s -X PATCH "$ORDERS_URL/v1/brands/$BRAND/webhook_endpoints/$id" \
    -H 'Content-Type: application/json' -d '{"active":false}' >/dev/null 2>&1 || true
done
EP="$(curl -s -X POST "$ORDERS_URL/v1/brands/$BRAND/webhook_endpoints" \
  -H 'Content-Type: application/json' -d "{\"url\":\"$STORE_WEBHOOK_URL\"}")"
EP_SECRET="$(printf '%s' "$EP" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("endpoint",{}).get("secret",""))' 2>/dev/null || true)"
if [ -n "$EP_SECRET" ]; then
  WEBHOOK_SECRET="$EP_SECRET"
  echo "    endpoint -> $STORE_WEBHOOK_URL  secret=${WEBHOOK_SECRET:0:14}..."
else
  echo "    WARN: could not register a webhook endpoint (is the orders service up at $ORDERS_URL?)."
  echo "          Outbound status updates to WooCommerce won't verify until this is set."
fi

echo "==> WooCommerce: seed the storefront products (SKUs matched to the Compound catalog)"
# wipe existing Woo products so re-runs stay clean
for id in $(wp wc product list --user=admin --field=id 2>/dev/null); do wp wc product delete "$id" --force --user=admin >/dev/null 2>&1; done
for row in "${PRODUCTS[@]}"; do
  IFS='|' read -r sku name price blurb <<< "$row"
  coa_id="$(wp post create --post_type=page --post_status=publish --post_title="Certificate of Analysis - $name" \
    --post_content="<h2>Certificate of Analysis</h2><table><tr><th>Analyte</th><td>$name</td></tr><tr><th>Method</th><td>HPLC identity and purity analysis</td></tr><tr><th>Result</th><td>Demo laboratory record - replace with the lot-specific third-party report before live sale.</td></tr></table>" --porcelain)"
  coa_url="$(wp post url "$coa_id")"
  image_id="$(wp eval '$upload=wp_upload_dir();$name="compound-product-".wp_generate_uuid4().".png";$path=$upload["path"]."/".$name;$image=imagecreatetruecolor(800,800);$bg=imagecolorallocate($image,241,245,249);$ink=imagecolorallocate($image,15,23,42);imagefill($image,0,0,$bg);imagestring($image,5,300,390,"Scientific Product",$ink);imagepng($image,$path);imagedestroy($image);$attachment=array("post_mime_type"=>"image/png","post_title"=>"Scientific product image","post_status"=>"inherit");$id=wp_insert_attachment($attachment,$path);require_once ABSPATH."wp-admin/includes/image.php";wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$path));echo $id;')"
  product_id="$(wp wc product create --user=admin --name="$name" --sku="$sku" --regular_price="$price" \
    --description="$blurb" --short_description="$blurb" --type=simple --manage_stock=false \
    --images="[{\"id\":$image_id}]" --meta_data="[{\"key\":\"_compound_coa_url\",\"value\":\"$coa_url\"}]" --porcelain)"
  echo "    $sku  $name  \$$price"
done

echo "==> WooCommerce: theme + branding (Storefront)"
wp theme install storefront --activate >/dev/null 2>&1 || true
wp option update blogname "Acme Peptides" >/dev/null 2>&1
wp option update blogdescription "Scientific product information and analytical documentation" >/dev/null 2>&1
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

echo "==> WordPress: publish required policies and contact information"
create_page() {
  local title="$1" slug="$2" content="$3"
  local existing
  existing="$(wp post list --post_type=page --name="$slug" --field=ID --format=ids)"
  if [ -n "$existing" ]; then
    wp post update "$existing" --post_title="$title" --post_content="$content" --post_status=publish >/dev/null
    printf '%s' "$existing"
  else
    wp post create --post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --post_content="$content" --porcelain
  fi
}
terms_id="$(create_page "Terms & Conditions" "terms-and-conditions" "Customers must be 21 or older and maintain an account. Products are presented with scientific information only. By ordering, the customer accepts these terms and all posted store policies.")"
privacy_id="$(create_page "Privacy Policy" "privacy-policy" "We collect account, contact, shipping, and transaction information needed to operate the store, fulfill orders, prevent fraud, and meet legal obligations. Contact privacy@example.com for privacy requests.")"
create_page "Shipping Policy" "shipping-policy" "Orders are processed after payment and compliance review. Available destinations, carriers, delivery estimates, and any temperature-control requirements are shown during fulfillment. Delays may occur and delivery dates are not guaranteed." >/dev/null
create_page "Refunds & Returns Policy" "refunds-and-returns" "Contact support@example.com before requesting a return. Eligibility depends on product condition, chain of custody, applicable law, and fulfillment status. Approved refunds return to the original payment method." >/dev/null
create_page "Chargeback Policy" "chargeback-policy" "Contact support@example.com or +1 (555) 010-2026 first so we can investigate billing or fulfillment concerns. Fraudulent or abusive disputes may be contested using order and delivery records." >/dev/null
create_page "Contact" "contact" "Email: <a href=\"mailto:support@example.com\">support@example.com</a><br>Phone: <a href=\"tel:+15550102026\">+1 (555) 010-2026</a>" >/dev/null
wp option update woocommerce_terms_page_id "$terms_id" >/dev/null
wp option update wp_page_for_privacy_policy "$privacy_id" >/dev/null
wp option update woocommerce_enable_guest_checkout no >/dev/null
wp option update woocommerce_enable_checkout_login_reminder yes >/dev/null
wp option update woocommerce_enable_signup_and_login_from_checkout yes >/dev/null

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

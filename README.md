# Compound for WooCommerce

A WooCommerce payment gateway that routes checkout and orders through
[Compound](https://compound.dev) - payments orchestration + pharmacy fulfillment for DTC peptide
brands. This is a standalone WordPress plugin (PHP); it lives in its own repo and is not part of the
Compound TS/Go monorepo.

## What it does

At checkout, on Place Order, the gateway:

1. **Creates a Compound order (order-first)** via `POST /v1/orders`, sending only `{sku, quantity}`
   per line item plus the amount - never price, name, or images (Compound's data-minimization rule).
   An unmapped SKU is rejected before any money moves.
2. **Creates a charge** via `POST /v1/charges` against that order; Compound's routing engine picks
   the processor and returns the outcome.
3. **Completes the WooCommerce order** on capture (stores the Compound order + charge ids), or fails
   cleanly with the real reason.

Both calls are authenticated by the brand's **secret API key** (`sk_...`, server-side only; the key
never reaches the browser). Inbound webhooks (`order.shipped` / `delivered` / `cancelled`) update the
WooCommerce order at `POST /wp-json/compound/v1/webhook` (HMAC-verified).

**Coupons.** Brands define coupons in the Compound admin portal (Discounts). Sync them into
WooCommerce so they apply at checkout:

```
wp compound sync_coupons        # pulls active Compound coupons -> WooCommerce coupons
```

**Attribution.** Every order sends `channel: "woocommerce"`, WooCommerce Order Attribution
(source/utm/referrer/device), and any applied coupon + discount, which Compound records on the order.

> PCI note: the current Compound sandbox captures without a card token, so this MVP does not tokenize
> card data in the browser. Client-side tokenization via `@compound/checkout-sdk` (so PAN never
> touches WordPress) is the next increment, gated on the charge API accepting a token.

## Files

```
compound-gateway.php                     plugin bootstrap (guards WooCommerce, registers the gateway)
includes/class-wc-compound-api.php       HTTP client for the Compound external API
includes/class-wc-gateway-compound.php   the WC_Payment_Gateway (settings + process_payment)
includes/class-wc-compound-webhooks.php  inbound Compound webhooks -> WC order updates
includes/class-wc-compound-cli.php       `wp compound sync_coupons` command
Makefile                                 `make dev` (store up) / `make seed` (store data) / `make down`
.wp-env.json / docker-compose.test.yml   Dockerized WordPress + WooCommerce test site (two ways)
bin/setup-test-store.sh                  one command: key + matched products + theme + gateway
bin/smoke-test.sh                        headless order -> process_payment against local Compound
bin/aws-deploy.sh                        push plugin + config changes to the live AWS store
terraform/                               the same store on AWS as a live HTTPS site
```

## Run a test store

Requires Docker. Each repo runs its own two commands; they stay independent.

**Step 1 - Compound stack + demo data** (in the compound repo):

```
make dev      # services + portals
make seed     # the demo brand's payment lanes + canonical catalog SKUs
```

**Step 2 - the store** (in this repo):

```
make dev      # brings up + provisions WordPress + WooCommerce at http://localhost:8888
make seed     # storefront products (SKUs matched to the Compound catalog) + theme + gateway
```

`make dev` here uses prebuilt images (no build step, so it avoids the getcomposer.org fetch that
blocks wp-env in restricted networks); it installs WordPress + WooCommerce + this plugin and is
idempotent. `make seed` runs `bin/setup-test-store.sh`, which signs into the seeded demo brand,
**mints an API key**, seeds the storefront products, applies the Storefront theme + branding, and
configures the gateway. (Pass your own key via `COMPOUND_API_KEY=sk_... make seed` to skip minting.)
Checkout resolves against the Compound catalog seeded in step 1, so run that first.

Prefer the standard tool and your network can reach getcomposer.org? `npm install && npx wp-env
start` also leaves a store at http://localhost:8888, then run `make seed`.

Now shop at http://localhost:8888 and check out with "Card (Compound)", or run the headless check:

```
bin/smoke-test.sh
# -> {"result":"success","wc_status":"processing","compound_order":"order_...","compound_charge":"chg_..."}
```

### Demo products (SKUs shared with the Compound catalog seed)

| SKU | Product | Price |
|---|---|---|
| `glp1-starter` | GLP-1 Starter | $199 |
| `glp1-plus` | GLP-1 Plus | $299 |
| `tirz-pro` | Tirzepatide Pro | $399 |
| `recovery-bpc` | Recovery BPC-157 | $149 |

These match `apps/admin-portal/.../api/dev/seed/catalog` so a storefront checkout works out of the box.

The order should appear in Compound (orders service) as captured/pending_routing, and its charge in
payments.

### Verified

End to end against a live local Compound stack: a WooCommerce order for `glp1-starter ×2` ($498)
created a Compound order (order-first), the charge captured via a routed processor, and - through the
event bus - the order advanced to `captured / pending_routing`; the WooCommerce order moved to
`processing`. A product whose SKU is not in the brand's Compound catalog is rejected (gateway
failure, WooCommerce order stays unpaid) - order-first + data-minimization holding at the plugin
boundary.

## Run a live store on AWS

`localhost` can't receive Compound's webhooks and can't be opened from a phone or
shared with anyone. `terraform/` stands the same stack up on one EC2 instance with a real
Let's Encrypt certificate, at `https://<elastic-ip>.sslip.io` - no domain purchase, no
Route 53 zone. About $15-20/month; `make aws-down` takes it to zero.

```
cp terraform/terraform.tfvars.example terraform/terraform.tfvars
$EDITOR terraform/terraform.tfvars   # Compound API key + the API base URLs
make aws-up                          # ~4 min, prints the store URL
make aws-creds                       # admin password + webhook signing secret
make aws-deploy                      # push a plugin change to the running store
```

The one thing that does not carry over from local: the gateway's Orders/Payments URLs.
`host.docker.internal:4003` doesn't resolve from AWS, so point `compound_orders_url` /
`compound_payments_url` at a deployed Compound stack or a tunnel to your machine. Details
and the full resource list are in [terraform/README.md](terraform/README.md).

## Settings (WooCommerce -> Settings -> Payments -> Compound)

| Setting | Notes |
|---|---|
| Environment | `sandbox` (test, no real money) or `live` |
| Secret API key | `sk_...` with `orders:write` + `charges:write` |
| Orders / Payments API base URL | Compound API (local: the two service URLs) |
| Webhook signing secret | verifies inbound Compound webhooks |

## Development

No host PHP needed; lint in Docker:

```
docker run --rm -v "$PWD":/app -w /app php:8.2-cli bash -c 'for f in $(find . -name "*.php" -not -path "./vendor/*"); do php -l "$f"; done'
```

WordPress Coding Standards (optional): `composer install && composer run lint`.

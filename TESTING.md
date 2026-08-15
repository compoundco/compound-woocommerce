# Testing the whole flow (WooCommerce → Compound → pharmacy → back to WooCommerce)

This walks a real order end to end, locally: a shopper checks out on the WooCommerce store,
Compound charges and **routes the order to a licensed compounding pharmacy**, the pharmacy reports
status, and Compound **pushes the status back** onto the WooCommerce order.

```
WooCommerce checkout (Card - Compound)
  → Compound order + charge (captured)
  → routed to a state-licensed, capable pharmacy   (or held as an exception, fail-safe)
  → pharmacy status: shipped → delivered
  → Compound delivers order.* webhooks (signed)
  → the WooCommerce order status + notes update
```

You watch it in two places: the **WooCommerce order** (`/wp-admin`) and the **Compound admin
portal** (`http://localhost:3000`, brand login `demo@acmepeptides.com` / `compound-demo-2026`).

## Prerequisites

Two repos side by side: the Compound monorepo (`../compound`) and this one. Docker running.

## 1. Start Compound (terminal A, in the compound repo)

```
make dev     # Postgres + all services (identity/catalog/orders/events/payments/supply-chain)
             # + both portals; seeds the demo brand, ops accounts, and 2 sandbox pharmacies
make seed    # the demo brand's payment lanes + canonical catalog SKUs (in another terminal)
```

This seeds two sandbox pharmacies: **Cornerstone** (cold-chain, licensed in CA/NY/TX/FL/WA/…) and
**Meridian** (non-cold-chain, CA/TX/AZ). So a cold-chain SKU (GLP-1/Tirzepatide) shipping to CA
routes to Cornerstone; a state neither is licensed in (e.g. **MT**) → exception (nothing ships).

## 2. Start the store (terminal B, in this repo)

```
make dev     # WordPress + WooCommerce + the Compound gateway on http://localhost:8888
make seed    # products (SKUs matched to the Compound catalog) + theme + gateway, AND registers
             # the Compound → store webhook endpoint and wires its signing secret into the gateway
```

`make seed` mints a brand API key, points the gateway at your host services
(`host.docker.internal:4003/4005`), and registers the outbound webhook endpoint
(`http://localhost:8888/wp-json/compound/v1/webhook`) so status flows back.

## 3. Place an order (happy path)

**Browser:** open `http://localhost:8888`, add **GLP-1 Starter** to the cart, check out with
**Card (Compound)**, and ship to a licensed state — e.g. **San Francisco, CA**. The order completes.

**or headless:**
```
make smoke   # creates a CA order + runs the gateway; prints wc_status + the Compound order id
```

**What you should see:**
- The WooCommerce order is paid; a note reads `Compound order ord_… - charge … captured via …`.
- In the **Compound admin portal → Orders**, the order appears and moves to **routed_to_pharmacy**
  with a pharmacy + a fulfillment **timeline**. Copy its id (`ord_…`) for the next step.

## 4. Advance fulfillment (simulate the pharmacy)

There's no real pharmacy in the loop yet, so simulate its status webhooks (uses the compound order
id from step 3):

```
make sim-shipped   ORDER=ord_xxxxxxxx     # pharmacy shipped (carrier + tracking)
make sim-delivered ORDER=ord_xxxxxxxx     # delivered
```

Within a couple of seconds, refresh the **WooCommerce order**:
- after `sim-shipped`: a "Shipped via UPS (tracking …)" note; status **processing**.
- after `sim-delivered`: status **completed**.

## 5. Fail-safe path (unlicensed state / excursion)

- **Unlicensed state:** check out shipping to **MT** (no seeded pharmacy is licensed there). The
  Compound order goes to **exception** (reason `no_licensed_pharmacy_in_state`) — nothing ships —
  and the WooCommerce order flips to **on-hold** (needs review).
- **Cold-chain excursion:** on a shipped order, `make sim-exception ORDER=ord_…` → the order goes
  to **exception**, a `delivered` afterward is **blocked**, and the WooCommerce order is **on-hold**.

## Where to look

| Signal | Where |
|---|---|
| WooCommerce order status + notes | `http://localhost:8888/wp-admin` → WooCommerce → Orders |
| Compound order state + fulfillment timeline | Compound admin portal `http://localhost:3000` → Orders |
| Routing decision (pharmacy chosen + why) | supply-chain logs; `routing_decisions` table |
| Outbound webhook deliveries | `outbound_webhook_deliveries` table (status `delivered`) |
| Nothing silently dropped | `dead_letters` table should stay empty for these events |

## Troubleshooting

- **Store can't reach Compound** (checkout errors): the gateway must use
  `host.docker.internal:4003/4005` (that's what `make seed` sets); the Compound services must be up.
- **WooCommerce status doesn't update after `sim-*`**: the gateway's `webhook_secret` must equal the
  registered endpoint's secret — re-run `make seed` (it re-registers and re-wires it). Confirm the
  endpoint URL is `http://localhost:8888/...` (reachable from the host, where Compound's worker runs).
- **Order goes to exception unexpectedly:** ship to a licensed state (CA/NY/TX/…); MT/others aren't
  seeded.
- **`ORDER=` id:** it's the Compound order id (`ord_…`) from the admin portal Orders page or the
  WooCommerce order note, not the WooCommerce order number.

## Reset

```
# this repo
make reset               # wipe the WooCommerce store
# compound repo
make reset-all           # wipe all Compound local data
```

# Merchant compliance checklist

The plugin enforces the storefront controls that can be implemented technically. This document is
an operating checklist, not legal advice. The merchant remains responsible for reviewing every live
page, product, document, jurisdiction, and processor requirement before accepting orders.

## Enforced by the plugin

- A blocking 21+ age-verification popup appears before storefront access and remembers acceptance
  in the browser.
- Guest checkout is disabled and checkout requires sign-in or account creation.
- WooCommerce's separate Terms & Conditions acceptance is required once a terms page is assigned.
- Each product has a Certificate of Analysis URL field and a public **Lab Report (COA)** product tab.
- Checkout is blocked when a cart product lacks an image, description, price, or COA URL.
- Product reviews are disabled so outcome/effect testimonials cannot be published through reviews.
- Accepted card-network marks appear in the footer.

## Seeded for development

`make seed` creates scientific-name-only demo products, scientific descriptions, product images,
individual demo COA pages, the required policies, and a contact page with email and phone. Demo COA
records and placeholder contact details must be replaced before any live sale.

## Merchant evidence required outside the plugin

Keep these files in the merchant's controlled compliance repository and provide them to the payment
or underwriting team when requested. Do not commit confidential financial or supplier documents to
this source repository.

- Processing statements for the most recent three months, when available.
- Signed supplier/fulfillment agreement, or owned-inventory invoices plus dated inventory photos.
- Lot-specific third-party lab report for every individual product; update each product's COA URL
  whenever its offered lot changes.
- Current support email and phone number monitored by the merchant.

## Manual pre-publication review

Before publishing or updating any product, bundle, page, image, metadata, SEO copy, email, or ad:

- Use scientific names only; remove drug brand names such as Wegovy, Ozempic, and Zepbound.
- Limit content to neutral scientific identity and analytical information.
- Name multi-product offerings using scientific conventions; do not frame them as usage stacks.
- Remove health, anti-aging, performance, wellness, weight-loss, and recovery claims.
- Remove dosage guidance, protocols, cycles, or instructions for use.
- Remove testimonials or quotations that describe outcomes or effects.
- Confirm Terms & Conditions, Privacy, Shipping, Refunds/Returns, and Chargeback policies remain
  accurate for actual operations and applicable law.

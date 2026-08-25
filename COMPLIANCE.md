# Merchant storefront compliance SOP

**Scope: this SOP governs the chefspeps.com reference storefront specifically, not every
merchant who installs this plugin.** The compliance controls it describes (`class-wc-compound-
compliance.php`) are off by default in the plugin - the general download a third-party
merchant gets from GitHub Releases never loads them. chefspeps.com opts in explicitly
(`terraform/templates/provision.sh.tftpl` sets `COMPOUND_WC_ENABLE_COMPLIANCE` in
wp-config.php) because it is Compound's own reference storefront for a regulated product
category and carries its own regulatory posture; that is not a general requirement this
plugin imposes on every installation. A different merchant's compliance posture is their
own to configure, with their own counsel - this plugin does not decide it for them.

The plugin enforces the storefront controls that can be implemented technically, for
deployments that opt in. This document is an operating checklist, not legal advice. The
merchant remains responsible for reviewing every live page, product, document, jurisdiction,
and processor requirement before accepting orders.

## Scope and change control

This SOP applies to every change touching `class-wc-compound-compliance.php`, its assets, and
chefspeps.com's own terraform/provisioning - PHP, JavaScript, CSS, templates, seed scripts,
product fixtures, documentation, checkout behavior, marketing copy, metadata, structured data,
and generated demo content, wherever chefspeps.com is the deployment in question. It does not
apply to the general plugin's gateway/webhook code, which never loads this module.

- Treat every requirement in this document as release-blocking **for chefspeps.com**.
- Do not remove, bypass, hide, or weaken any required control on chefspeps.com, and do not
  make `COMPOUND_WC_ENABLE_COMPLIANCE` conditional or configurable there - it opts in
  unconditionally in `terraform/templates/provision.sh.tftpl`.
- Do not introduce noncompliant sample text even when it is marked as test or demo content.
- Do not infer that a processor, gateway, theme, or WooCommerce update replaces these controls.
- If a requested change conflicts with this SOP, stop and clearly identify the conflict before
  implementing it.
- A requirement may only be changed when the repository owner explicitly directs that exact policy
  change. Update this SOP and the implementation together so they cannot drift.

## Required storefront controls

Every deployable storefront must satisfy all of the following:

- Show a blocking 21+ age-verification popup before site content can be accessed.
- Require account creation or sign-in to complete a purchase; guest checkout must remain disabled.
- Require a separate Terms & Conditions acceptance checkbox at checkout.
- Publish Terms & Conditions, Privacy, Shipping, Refunds/Returns, and Chargeback policies.
- Display accepted card-network marks at checkout or in the footer. Only Visa, American Express,
  and Discover may be presented as accepted; Mastercard must never be advertised or accepted.
- List at least two current contact methods, such as a monitored email address and phone number.
- Give every product an image, description, price, and public product-specific COA link.
- Disable or moderate reviews so no outcome, effect, or other prohibited testimonial is published.

## Product and content rules

These rules apply everywhere content can appear, including product names, descriptions, categories,
bundles, menus, images, alt text, reviews, policy examples, SEO fields, schema, email templates, seed
data, and tests visible to a shopper.

- Use scientific product names only.
- Do not use pharmaceutical brand names, including Wegovy, Ozempic, Zepbound, or similar names.
- Limit product content to neutral scientific identity, composition, and analytical information.
- Scientific multi-product combinations are permitted, but do not frame them as usage “stacks.”
- Do not make health, anti-aging, performance, wellness, weight-loss, or recovery claims.
- Do not provide dosage guidance, protocols, cycles, administration instructions, or directions for
  use.
- Do not publish testimonials or reviews that reference outcomes, benefits, or effects.

## Required implementation behavior

The compliance layer must fail closed where practical:

- Products without an image, description, price, or COA must not proceed through checkout.
- Mastercard transactions must fail closed. Because the current WooCommerce integration receives
  only a generic card rail and no card-network identifier, every live processor and routing lane
  must be configured to reject Mastercard before card checkout is enabled.
- A missing or unassigned terms page must be treated as a configuration defect before launch.
- Account-only checkout and the age gate must work with both classic and block-based WooCommerce
  experiences used by the store.
- Compliance controls must remain usable on mobile devices and accessible by keyboard.
- Styling changes must not visually obscure required notices, checkboxes, COA links, policy links,
  contact details, card marks, or the age gate.
- The compliance layer's CSS (`assets/css/compliance.css`) sets structural layout only (spacing,
  sizing, flex/grid) for its own `compound-*` elements - never a color, font-family, or override
  of an existing theme element (headings, links, buttons, the product grid, form fields, the
  site header/footer). It inherits the active theme's look; it does not impose one. The single
  exception is the age-gate's full-page backdrop, which needs an explicit neutral (black/white,
  never a brand color) scrim to function as a blocking overlay.

## Enforced by the plugin

- A blocking 21+ age-verification popup appears before storefront access and remembers acceptance
  in the browser.
- Guest checkout is disabled and checkout requires sign-in or account creation.
- WooCommerce's separate Terms & Conditions acceptance is required once a terms page is assigned.
- Each product has a Certificate of Analysis URL field and a public **Lab Report (COA)** product tab.
- Checkout is blocked when a cart product lacks an image, description, price, or COA URL.
- Product reviews are disabled so outcome/effect testimonials cannot be published through reviews.
- Accepted card-network marks in the footer list only Visa, American Express, and Discover and
  explicitly state that Mastercard is not accepted.

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
- Processor and routing configuration evidence showing Mastercard is disabled on every live card
  lane available to this storefront.

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

## Engineering workflow

For every storefront-affecting change:

1. Read this SOP before editing.
2. Identify which required controls or content surfaces the change touches.
3. Preserve fail-closed behavior and both classic/block checkout compatibility where applicable.
4. Search new or modified shopper-facing text for prohibited brands, claims, dosage language,
   protocols, cycles, usage stacks, and testimonials.
5. Validate the most focused relevant PHP, JavaScript, shell, lint, and storefront checks available.
6. Report any requirement that depends on merchant documents, real product data, legal review, or
   production configuration and cannot be completed in source code.

## Release checklist

Before production release, confirm:

- The age gate appears for a new browser and prevents under-21 access.
- Guest checkout is unavailable and account creation/sign-in is required.
- Terms acceptance is separate, visible, linked, and required.
- Every policy is published, linked, and contains current merchant-specific terms.
- Every live product has a real image, scientific description, current price, and lot-specific COA.
- Product names and all shopper-facing content pass the product and content rules above.
- Reviews and testimonials contain no claims, outcomes, effects, protocols, or dosage guidance.
- Current email and phone contact methods are visible and monitored.
- Card-network marks show only Visa, American Express, and Discover.
- A Mastercard test transaction is declined in every enabled environment and processor lane.
- The merchant has supplied the external evidence listed above through an approved secure channel.

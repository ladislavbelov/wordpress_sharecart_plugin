# Share Cart – Minimal (Ref Doctor)

A small WooCommerce plugin that lets a referring doctor share their cart with a patient via a unique link, and attributes the resulting order back to that doctor.

## What it does

- The doctor builds a cart on the site and clicks **"Share Cart"** to generate a unique `/share-cart/{uuid}/` link.
- The link snapshots the cart's line items and any applied coupons.
- The patient opens the link (no login required), sees the product list, and clicks **"Add to cart"** to import everything into their own cart.
- The doctor's name follows the patient through checkout and gets attached to the resulting order — visible in an order metabox, and included in the admin notification email.
- An admin tools page lets you clean up old/expired share links.

## Requirements

- WordPress 6.2+
- WooCommerce 8.0+
- PHP 7.3+
- Works with both classic and block-based Cart/Checkout, and with both legacy post-based orders and HPOS (custom order tables).

## Installation

1. Copy the `sharecart-minimal` folder into `wp-content/plugins/`.
2. Activate the plugin (WooCommerce must already be active).
3. Go to **Settings → Permalinks** and click *Save* once, so the `/share-cart/{key}/` rewrite rule takes effect.
4. The "Share Cart" panel appears automatically on the Cart page; you can also drop the `[sharecart_button]` shortcode into any page or post.

## How it's built

Plain procedural plugin, five files:

- `sharecart-minimal.php` — bootstrap, share panel UI, order/email/admin-metabox integration.
- `includes/install.php` — DB table (`{prefix}sharecart_shared`) and schema migrations via `dbDelta`.
- `includes/rewrite.php` — the `/share-cart/{key}/` rewrite rule and template routing.
- `includes/ajax.php` — the two AJAX endpoints: generate link, add items to cart.
- `includes/template-share-page.php` — the public share page template.
- `assets/sharecart.js` — front-end glue, using event delegation so it survives WooCommerce's own cart/checkout re-renders.

Cart contents and applied coupons are stored as a JSON snapshot in a dedicated table, keyed by a UUID, with a one-year expiry.

## Compatibility notes

Both WooCommerce order storage modes (legacy post-based and HPOS) and both checkout UIs (shortcode-based and the Cart/Checkout blocks) are handled explicitly. In particular, order meta is written from two separate hooks — `woocommerce_checkout_create_order` and `woocommerce_store_api_checkout_update_order_meta` — since only the classic checkout fires the first one; block-based checkout (the default for newer WooCommerce stores) only fires the second.

## Known limitations

- Link generation has no rate limiting — fine for small/internal use, not hardened against abuse at scale.
- UI copy is hardcoded in Russian (the original use case); no `.pot` translation file yet despite the declared text domain.
- The public share page intentionally hides the doctor's name from the visitor by default — see the commented-out line in `includes/template-share-page.php` if you want it shown.

## License

GPLv2 or later.

=== DropLock for WooCommerce ===
Contributors: droplock
Tags: woocommerce, limit, drops, one per customer, purchase limit, limited edition, collectibles, preorder
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-per-customer protection for limited WooCommerce drops. Stop duplicate purchases across multiple orders.

== Description ==

**DropLock for WooCommerce** is built for limited product drops, collectibles, memberships, event exclusives, preorders, and one-per-customer products.

Unlike generic min/max quantity plugins, DropLock enforces **lifetime** purchase limits per customer across multiple orders — so customers cannot bypass restrictions by placing a second order.

= Core features =
* Lifetime purchase limit per customer per product
* Drop Mode toggle per product
* Logged-in customer validation by user ID
* Guest customer validation by billing email
* Add-to-cart, cart-update, and checkout validation
* Customizable limit message with variables ({product_name}, {limit}, {purchased_qty}, {cart_qty}, {remaining_qty})
* Customizable product page badge
* Admin / shop manager bypass
* Blocked attempt log inside WooCommerce > DropLock
* HPOS compatible
* Cart & checkout blocks compatible
* Works with simple products; variations roll up to their parent product

= Built for =
* Limited edition products
* Collector drops, chase variants, die-cast model releases
* Sneaker-style launches
* Event exclusives
* Memberships
* Preorders
* Limited merch drops
* Wholesale samples

== Installation ==

1. Upload `droplock-for-woocommerce` to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Edit a product, scroll down to the General tab, enable DropLock, and set the quantity per customer.

See `docs/installation.md` for full details.

== Frequently Asked Questions ==

= Does it work with guest checkout? =
Yes. Guests are validated by billing email at checkout.

= Does it work with variable products? =
Yes — the parent product limit applies across all variations. Variation-specific limits are planned for the Pro version.

= Does it work with HPOS? =
Yes. DropLock declares HPOS compatibility and uses `wc_get_orders()` for all order lookups.

= Does it bypass admin orders? =
Yes. Users with `manage_woocommerce` capability bypass all limits.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

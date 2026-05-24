# FAQ

### Does this work with guest checkout?
Yes. Guests are matched by billing email at checkout. The block fires before the order is created.

### Does it support variable products?
Yes — the parent product's DropLock setting applies across all variations. A customer who bought "Red" cannot then buy "Blue" of the same product. Per-variation limits are a planned Pro feature.

### Does it support WooCommerce HPOS (High-Performance Order Storage)?
Yes. The plugin declares HPOS compatibility and uses `wc_get_orders()` rather than direct order-post queries.

### Does it support the WooCommerce Cart & Checkout Blocks?
Yes. Validation hooks into `woocommerce_check_cart_items` and `woocommerce_after_checkout_validation`, both of which fire under the Blocks checkout flow.

### Will refunds reduce the counted quantity?
By default, **no**. Refunded orders are not in the default counted statuses, but the line items themselves still exist. If you need refunds to free up the customer's remaining allowance, change the order to a non-counted status (e.g., **Cancelled**) on the order screen.

### Can a customer just use a different email?
Yes — DropLock cannot identify a determined attacker who uses a clean email, a new account, and a different payment method. DropLock is a friction tool, not anti-fraud software. It stops casual duplicate purchases and accidental re-orders, not state-level adversaries.

### Will it slow down my site?
DropLock only runs its order lookup when:
- A product with DropLock enabled is added to the cart, or
- The cart/checkout is rendered with such a product present.

Results are cached per request and per customer for one minute. There is no impact on browsing or shop archive pages.

### Does it work with Subscriptions / Bundles / Composite Products?
v1 is built around standard simple and variable products. Subscriptions and Bundles will work but may behave unexpectedly around renewals and component products — test on a staging site before enabling on production.

### Can I export the blocked attempt log?
Not in v1. CSV export is on the v1.3 roadmap.

### Does it integrate with a license system?
v1 does not include license activation. A license/update channel is planned for v2.0.

### Where is data stored?
- Product settings → `postmeta` on each product.
- Blocked attempt log → custom table `{prefix}droplock_blocked_log`.
- Activation flag → `wp_options` (key `droplock_db_version`).

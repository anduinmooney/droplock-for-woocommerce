# DropLock — Manual Testing Checklist

Test on a clean staging site with a real WooCommerce install. Use at least two customer accounts and one guest email.

## Setup
- [ ] Install WooCommerce 8.0+ with a sample product.
- [ ] Install DropLock and activate.
- [ ] Confirm **WooCommerce → DropLock** menu appears.
- [ ] Confirm a "DropLock" section is visible in the product **General** tab.

## 1. Simple product, limit = 1
- [ ] Set DropLock enabled, max qty = 1.
- [ ] Log in as Customer A → buy product → order = Processing.
- [ ] Customer A tries to add to cart again. **Expected:** add-to-cart blocked with custom message.

## 2. Simple product, limit = 2
- [ ] Max qty = 2.
- [ ] Customer A buys 1. Tries to add 1 more. **Expected:** allowed.
- [ ] Customer A tries to add 2 more (would total 3). **Expected:** blocked.

## 3. Logged-in customer with existing purchase
- [ ] Customer A has a Completed order from before DropLock was enabled. Limit = 1.
- [ ] Customer A tries to add to cart. **Expected:** blocked.

## 4. Guest with same billing email
- [ ] Customer A has a Completed order.
- [ ] Log out. Add product to cart as guest.
- [ ] **Expected:** add-to-cart succeeds, but checkout blocks when same billing email is entered.

## 5. Cart quantity update beyond limit
- [ ] Limit = 1, cart already has 1. Go to cart, change qty to 2.
- [ ] **Expected:** blocked with message.

## 6. Checkout with too many items
- [ ] Limit = 1. Cart has 2. Try to checkout. **Expected:** checkout error before order creation.

## 7. Admin / shop manager bypass
- [ ] As admin, with same email as Customer A, add to cart and checkout.
- [ ] **Expected:** no block, order completes.

## 8. Product with DropLock disabled
- [ ] Disable DropLock on the product. Customer A who is already over the limit.
- [ ] **Expected:** can purchase freely.

## 9. Custom limit message
- [ ] Set custom message containing `{product_name}` and `{remaining_qty}`.
- [ ] Trigger a block. **Expected:** message renders variables correctly.

## 10. Product badge display
- [ ] Show badge checked. Visit product page. **Expected:** "Limited Drop: Limit 1 per customer" appears near add-to-cart.

## 11. Product badge hidden
- [ ] Uncheck show badge. **Expected:** badge disappears.

## 12. Blocked attempt logging
- [ ] Trigger a block. **Expected:** entry appears in **WooCommerce → DropLock** with correct fields.

## 13. Ignored statuses
- [ ] Set counted statuses to `completed` only.
- [ ] Give Customer A an order with status `processing`. **Expected:** that order does NOT count.

## 14. Counted statuses
- [ ] Set counted statuses to `processing` + `on-hold`.
- [ ] Customer A has on-hold order. **Expected:** that order counts.

## 15. Variable product
- [ ] DropLock on parent product, limit = 1.
- [ ] Customer buys Red variation. Tries Blue variation.
- [ ] **Expected:** blocked.

## 16. Refund / cancel
- [ ] Customer A is over limit. Refund the order. **Expected:** still blocked (refund is not counted as cancel by default).
- [ ] Change order to **Cancelled**. **Expected:** the cancellation removes that order from counted set; customer can repurchase.

## 17. HPOS
- [ ] Enable HPOS in **WooCommerce → Settings → Advanced → Features**.
- [ ] Re-run tests 1, 3, 4. **Expected:** identical behavior.

## 18. PHP error log
- [ ] After all tests, check `wp-content/debug.log`. **Expected:** no notices or warnings from DropLock.

## 19. WooCommerce status log
- [ ] **WooCommerce → Status → Logs**. **Expected:** no fatal DropLock entries.

## 20. Block-based checkout
- [ ] Switch checkout page to Blocks checkout. Re-run tests 4 and 6.
- [ ] **Expected:** errors appear inline at checkout.

## 21. Classic checkout
- [ ] Switch back to shortcode/classic checkout. Re-run tests 4 and 6.
- [ ] **Expected:** errors appear at the top of the checkout form.

## 22. Plugin conflicts
- [ ] Activate common plugins: WooCommerce Subscriptions (optional), Min/Max Quantities, Smart Coupons, WC Vendors. Confirm DropLock still blocks limits correctly.

## 23. Multisite (optional)
- [ ] On a multisite, activate per-site (not network-wide). Confirm activation creates the log table on each subsite.

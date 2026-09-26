# GALADO Gift Cards

E-gift cards as one-time WooCommerce coupons. Sold as a normal product, issued once per card when the
order is paid, emailed to the recipient on the chosen date, spent in one order (any unused value is lost).

Spec: `galado-international/HANDOVER-GIFT-CARD-PLUGIN.md`. Requires PHP 7.4+, WooCommerce 8.8+ (tested on
10.5.3, orders in posts and under HPOS).

## Launch day

1. Register `galado-gift-cards` in Git Sync, deploy, activate.
2. Create the product, either way (both are safe to repeat; they never make a second product):
   - WooCommerce > Settings > Products > Gift cards > **Create the gift card product**, or
   - `wp galado-gift-cards create-product`

   It is created **Private**. Add images and the description, then publish it.
3. Same settings screen: per-card limit, per-order limit, the risk hold.
4. Publish a "Check your gift card" page containing `[galado_gift_card_check]`, and the terms.
5. Cloudflare rate-limit rules for that page and for coupon apply calls.

## How the product is marked

- The product meta `_galado_gift_card = yes` is what marks it (variations count through their parent).
  The option `galado_gift_cards_product_id` only records the ID that create-product made; the plugin
  never reads it.
- Variations: RM50, RM100, RM150, RM200, RM300 (meta `_galado_gc_amount`), and "Custom amount"
  (meta `_galado_gc_custom = yes`, RM30 up to the per-card limit, whole ringgit). Virtual, tax status none,
  sold individually.
- The product and every variation carry `_wc_points_earned = 0`, so Points and Rewards neither gives
  points for a card nor shows "earn N points" on its page.
- The price shows as "RM30.00 to RM1,000.00" (the per-card limit), not WooCommerce's dashed range.

## Settings (WooCommerce > Settings > Products > Gift cards)

| Option | Default | Meaning |
|---|---|---|
| `galado_gift_cards_max_card` | 1000 | Highest value of one card (RM) |
| `galado_gift_cards_max_order` | 2000 | Highest total of gift cards in one order (RM), never below the per-card limit |
| `galado_gift_cards_risk_hold` | yes | Hold delivery when Stripe rates the payment elevated or highest |

## Helpers for other plugins

| Function | Returns |
|---|---|
| `galado_gift_cards_is_gift_card_product( $product_id )` | bool; variations count as their parent |
| `galado_gift_cards_is_gift_card_code( $code )` | bool; any issued card, in any state |
| `galado_gift_cards_cart_gift_total()` | float, RM value of gift card lines in the cart |
| `galado_gift_cards_order_gift_total( $order )` | float, RM value of gift card lines bought in an order |
| `galado_gift_cards_order_gift_coupon_total( $order )` | float, RM value paid by gift card codes on an order |

Check `function_exists()` before calling them from another plugin.

## Data it writes

- **Order item (a card line):** `_galado_gc_value`, `_galado_gc_recipient_name`,
  `_galado_gc_recipient_email`, `_galado_gc_message`, `_galado_gc_delivery_date` (Y-m-d, Malaysian time),
  `_galado_gc_coupon_ids`, `_galado_gc_sent_at`.
- **Order:** `_galado_gc_risk_level`, `_galado_gc_hold`, `_galado_gc_released`, and
  `_galado_gc_issue_attempts` while issuing is failing (cleared once it works).
- **Coupon (one per card):** `_galado_gift_card = yes`, `_galado_gc_order_id`, `_galado_gc_order_item_id`,
  `_galado_gc_index`, `_galado_gc_value`, and `_galado_gc_revoked` once disabled. A disabled card is the
  coupon moved to Draft.
- **Coupon line on the order that spent a card:** `_galado_gift_card = yes`, `_galado_gc_rm_used`.
- Action Scheduler group `galado-gift-cards`: `galado_gc_deliver` (order id, item id) and
  `galado_gc_issue_retry`.

Codes never go into order notes, logs, URLs or page text: notes (including WooCommerce's own "Coupon
applied" note) and the buyer's email show only the last four characters.

## Things other plugins should know

- Create coupons with `new WC_Coupon( 0 )`, never `new WC_Coupon()`. With Points and Rewards 1.6.13
  active and no redemption in the session, its `woocommerce_get_shop_coupon_data` filter matches the
  empty code and turns the object into a virtual coupon that `save()` silently never writes.
- Negative cart fees (REDIS cart rules, Club offers) are capped so they never pay for a gift card being
  bought: a cut fee's `amount` is lowered in place (the original is kept in `$fee->galado_gc_original`),
  and a fee cut to nothing is removed. Anything that records an offer from its fee must use the amount
  after the cut. The Club bridge should also leave gift lines out of its minimum spend with
  `galado_gift_cards_cart_gift_total()`, and out of points with `galado_gift_cards_order_gift_total()`.
- REDIS item-quantity maximums still count gift cards (REDIS checks those itself); minimums, subtotal
  conditions and "all items" bulk counts do not.
- Points and Rewards 1.6.13 does not declare HPOS support, so WooCommerce will not switch HPOS on while
  it is active.

## Emails

WooCommerce > Settings > Emails: "Gift card (to the recipient)" and "Gift card sent (to the buyer)".
Templates, overridable from the theme under `woocommerce/`:

- `emails/galado-gift-card-recipient.php`, `emails/plain/galado-gift-card-recipient.php`
- `emails/galado-gift-card-sent.php`, `emails/plain/galado-gift-card-sent.php`

## Admin order actions

- **Release held gift cards**: shown while a card is held for payment risk.
- **Send gift card emails now**: sends every unsent, still active card on the order.

## Tests

- Unit (no WordPress needed): `php tests/run.php`
- Integration (real WordPress 6.9.9 + WooCommerce 10.5.3 in Docker, throwaway database):
  `bash tests/integration/setup.sh` once, then `bash tests/integration/run.sh`. Runs every file on a fresh
  database, the order tests again under HPOS, and two PHP processes racing to issue the same order.
  Set `GCT_PR_DIR` to a copy of the Points and Rewards plugin folder before setup.sh to test against it
  too (it is a paid extension, so it is not downloaded); without it those checks are skipped.

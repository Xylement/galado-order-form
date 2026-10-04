# GALADO Gift Cards

Fully digital e-gift cards as one-time WooCommerce coupons. The buyer picks an amount and a design, writes
a message, sees a live preview of the card and chooses when it is sent. The card is issued once when the
order is paid, emailed to the recipient on that date, and spent in one order (any unused value is lost).
Earning is untouched: whoever pays earns on what they pay (the buyer on the card; the recipient only on
what they pay on top of it). A card always costs its full price.

Spec: `galado-international/HANDOVER-GIFT-CARD-PLUGIN.md`, as narrowed by Clement on 27 Sep 2026 (SPEC-2
GC4, GC5, GC6, GC10 and section 5.2 win where they differ). Requires PHP 7.4+, WooCommerce 8.8+ (tested on
10.5.3, orders in posts and under HPOS).

## Launch day

1. Register `galado-gift-cards` in Git Sync, deploy, activate.
2. Create the product, either way (both are safe to repeat; they never make a second product):
   - WooCommerce > Settings > Products > Gift cards > **Create the gift card product**, or
   - `wp galado-gift-cards create-product`

   It is created **Private**, in a "Gift cards" product category. Add images and the description, then
   publish it. Real card artwork: see "Card designs" below (the plugin ships placeholders).
3. Same settings screen: per-card limit, per-order limit, the risk hold.
4. Publish a "Check your gift card" page containing `[galado_gift_card_check]`, and the terms.
5. Cloudflare rate-limit rules for that page and for coupon apply calls.
6. Catalogue feeds: exclude the "Gift cards" category in the Facebook catalogue feed's settings (and in
   any Google Merchant Center feed) until each platform's gift card policy has been checked.

## How the product is marked

- The product meta `_galado_gift_card = yes` is what marks it (variations count through their parent).
  The option `galado_gift_cards_product_id` only records the ID that create-product made; the plugin
  never reads it.
- Variations: RM50, RM100, RM150, RM200, RM300 (meta `_galado_gc_amount`), and "Custom amount"
  (meta `_galado_gc_custom = yes`, RM30 up to the per-card limit, whole ringgit). Virtual, tax status none,
  sold individually.
- The price shows as "RM30.00 to RM1,000.00" (the per-card limit), not WooCommerce's dashed range.
- It sits in the "Gift cards" product category (slug `gift-cards`) and on GALADO Bundles' never-bundle
  list (`galado_bundles_excluded_products`), so no bundle discount can reach it.

## Card designs

18 designs in four groups, drawn to Brand Guidelines v1.0 (flat brand and campaign colours, the galado
wordmark on the card, the headline in Archivo):

- **Any day:** Classic (the default), Thank you, Just because, Thinking of you, Get well soon
- **Celebrations:** Birthday, Congratulations, Graduation, Wedding, Anniversary, New baby
- **Festivals:** Hari Raya, Chinese New Year, Deepavali, Christmas
- **Love and family:** Valentine's Day, Mother's Day, Father's Day

The product page shows a live preview of the card (design, amount, recipient's name, message) with the
design swatches below it, under those group headings; the chosen design is stored on the order line
(`_galado_gc_design`), shown in the cart and order, and heads the recipient's email. The picker is on
the product page only (its script and styles load there): a theme's quick view shows the card fields
without it, and the card gets the default design.

- Artwork is a plain 1200 x 750 JPG with no words (the wordmark, headline and amount are laid over it).
  `tools/make-designs.py` draws `assets/designs/{key}.jpg` (needs Python and Pillow). To use other
  artwork without changing the plugin, put a JPG with the same name in the child theme:
  `woocommerce/galado-gift-cards/designs/birthday.jpg` and so on. Image, style and script URLs carry
  the file's time, so changed art reaches browsers at once.
- Names, headlines, groups and text colour, or more designs: the `galado_gift_cards_designs` filter,
  entries shaped `'raya' => ['label' => 'Hari Raya', 'headline' => 'Selamat Hari Raya', 'group' =>
  'festival', 'text' => 'ink']`. Groups: `any`, `celebrate`, `festival`, `love` (anything else lands in
  `any`). `text` is `ink` (dark, for light art) or `white` (the default). Keys are normalised to lowercase
  letters, digits, `-` and `_`; an entry without a label and headline is skipped. The first is the default.
- The preview's name and message are masked for Clarity session recordings. Archivo 800 (latin) is
  bundled in `assets/fonts/` under the SIL Open Font License (`Archivo-OFL.txt`).

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

Check `function_exists()` before calling them from another plugin.

## Data it writes

- **Order item (a card line):** `_galado_gc_value`, `_galado_gc_recipient_name`,
  `_galado_gc_recipient_email`, `_galado_gc_message`, `_galado_gc_delivery_date` (Y-m-d, Malaysian time),
  `_galado_gc_design`, `_galado_gc_coupon_ids`, `_galado_gc_sent_at`.
- **Order:** `_galado_gc_risk_level`, `_galado_gc_hold`, `_galado_gc_hold_noted`, `_galado_gc_released`,
  and `_galado_gc_issue_attempts` while issuing is failing (cleared once it works).
- **Coupon (one per card):** `_galado_gift_card = yes`, `_galado_gc_order_id`, `_galado_gc_order_item_id`,
  `_galado_gc_index`, `_galado_gc_value`, and `_galado_gc_revoked` once disabled. A disabled card is the
  coupon moved to Draft.
- Action Scheduler group `galado-gift-cards`: `galado_gc_deliver` (order id, item id) and
  `galado_gc_issue_retry`.

Codes never go into order notes, logs, URLs or customer-facing page text: notes (including WooCommerce's
own "Coupon applied" note) and the buyer's email show only the last four characters. Staff see full codes
on the admin order screen, for support.

## Things other plugins should know

- Create coupons with `new WC_Coupon( 0 )`, never `new WC_Coupon()`. With Points and Rewards 1.6.13
  active and no redemption in the session, its `woocommerce_get_shop_coupon_data` filter matches the
  empty code and turns the object into a virtual coupon that `save()` silently never writes.
- A gift card always costs its full price. Negative cart fees (REDIS cart rules, Club offers) are capped
  so they never pay for a card being bought: a cut fee's `amount` is lowered in place (the original is
  kept in `$fee->galado_gc_original`), and a fee cut to nothing is removed. Anything that records an
  offer from its fee must use the amount after the cut.
- The Club bridge's win-back record is corrected here whenever a gift card is involved: after the bridge
  records `_galado_winback_applied` at checkout (priority 10), this plugin sets it to what the order was
  actually given (priority 20), so the member's balance is charged what they got. That covers a win-back
  trimmed by the cap above, one WooCommerce trims to nothing because a card paid for the whole order,
  and a figure left on a reused app (Store API) draft after the cap dropped the fee. It reads the
  bridge's figure the way each build writes it: up to 0.64.12 the fee amount after the cap; the
  money-fixes build (the one with `$winback_rm`) the RM worked out before any cut. Orders without a gift
  card are left to the bridge. Welcome and referral already record the amount after the cut. No Club
  code change is needed; if the bridge renames that fee ("GALADO Club reward"), its meta or
  `$winback_rm`, update `Galado_GC_Spending::match_club_winback_record()` to match.
- A card still counts toward a Club offer's minimum spend (Clement: nothing to do with the Club). The
  card itself is never discounted, but its value can unlock an offer on the other items.
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

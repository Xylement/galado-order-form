<?php
/**
 * Code format and uniqueness; delivery and expiry times in Malaysian time.
 *   php tests/unit/test-codes-and-time.php
 */
require __DIR__ . '/bootstrap.php';

echo "-- codes: format, alphabet, randomness\n";
$codes = [];
$bad = [];
for ($i = 0; $i < 20000; $i++) {
    $c = Galado_GC_Codes::generate();
    $codes[$c] = true;
    if (!preg_match(Galado_GC_Codes::PATTERN, $c)) {
        $bad[] = $c;
    }
}
check('20,000 codes all match GIFT-XXXX-XXXX-XXXX over the allowed alphabet', $bad, []);
check('no repeats in 20,000 (59 bits each)', count($codes), 20000);
$all = implode('', array_keys($codes));
$body = str_replace(['GIFT-', '-'], '', $all);
check('none of the ambiguous characters 0 O 1 I L ever appear', preg_match('/[0O1IL]/', $body), 0);
check('the alphabet is the 31 unambiguous characters', strlen(Galado_GC_Codes::ALPHABET), 31);
check('every one of the 31 characters is used (the source is not biased to a subset)', count(array_unique(str_split($body))), 31);

echo "-- codes: uniqueness against existing coupons (any status)\n";
$seen = 0;
$code = Galado_GC_Codes::unique(function ($c) use (&$seen) { return ++$seen <= 3; });
check('three collisions, then a free code: the fourth candidate is used', [$seen, (bool) preg_match(Galado_GC_Codes::PATTERN, $code)], [4, true]);
$threw = false;
try {
    Galado_GC_Codes::unique(function () { return true; });
} catch (RuntimeException $e) {
    $threw = true;
}
check('negative control: when every candidate is taken it refuses rather than reusing a code', $threw, true);

echo "-- codes: recognising and normalising\n";
check('normalises spaces and lower case', Galado_GC_Codes::normalize(' gift 7kq4 m2xd 9pwa '), 'GIFT-7KQ4-M2XD-9PWA');
check('normalises a code typed with no hyphens', Galado_GC_Codes::normalize('GIFT7KQ4M2XD9PWA'), 'GIFT-7KQ4-M2XD-9PWA');
check('rejects a code with an ambiguous character (O)', Galado_GC_Codes::normalize('GIFT-7KQ4-M2XD-9PWO'), '');
check('rejects a short code', Galado_GC_Codes::normalize('GIFT-7KQ4-M2XD'), '');
check('rejects an ordinary promo code', Galado_GC_Codes::normalize('WELCOME10'), '');
galado_test_gift_code('GIFT-7KQ4-M2XD-9PWA', 9001);
check('an issued code is a gift card code, any case', Galado_GC_Codes::is_gift_card_code('gift-7kq4-m2xd-9pwa'), true);
check('a code in the gift format that was never issued is not', Galado_GC_Codes::is_gift_card_code('GIFT-2222-3333-4444'), false);
$GLOBALS['__coupon_posts']['gift-aaaa-bbbb-cccc'] = 9002; // exists, but not flagged as a gift card
check('a coupon with the right shape but no gift flag is not a gift card', Galado_GC_Codes::is_gift_card_code('GIFT-AAAA-BBBB-CCCC'), false);
check('the tail shows only the last four characters', Galado_GC_Codes::tail('gift-7kq4-m2xd-9pwa'), '9PWA');
check('codes are masked inside any message',
    Galado_GC_Codes::mask_in_text('Coupon "gift-7kq4-m2xd-9pwa" has expired.'), 'Coupon "your gift card" has expired.');

echo "-- expiry: 23:59:59 Malaysian time, three years on\n";
$tz = new DateTimeZone('Asia/Kuala_Lumpur');
$ts = function ($s) use ($tz) { return (new DateTimeImmutable($s, $tz))->getTimestamp(); };
$fmt = function ($t) use ($tz) { return (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d H:i:s'); };
check('paid 26 Sep 2026 14:05 MYT: valid through 26 Sep 2029 23:59:59 MYT',
    $fmt(Galado_GC_Time::expiry_timestamp($ts('2026-09-26 14:05:00'))), '2029-09-26 23:59:59');
check('paid 00:30 MYT on 1 Oct (still 30 Sep in UTC): the Malaysian date counts',
    $fmt(Galado_GC_Time::expiry_timestamp($ts('2026-10-01 00:30:00'))), '2029-10-01 23:59:59');
check('paid on 29 Feb 2028: expires 28 Feb 2031 (no 29 Feb that year)',
    $fmt(Galado_GC_Time::expiry_timestamp($ts('2028-02-29 10:00:00'))), '2031-02-28 23:59:59');
check('the expiry moment is 15:59:59 UTC (UTC+8)',
    gmdate('H:i:s', Galado_GC_Time::expiry_timestamp($ts('2026-09-26 14:05:00'))), '15:59:59');

echo "-- delivery: now for today or earlier, else 09:00 Malaysian time on the chosen date\n";
$now = $ts('2026-09-26 10:00:00');
check('a future date goes out at 09:00 MYT that day',
    $fmt(Galado_GC_Time::delivery_timestamp('2026-12-25', $now)), '2026-12-25 09:00:00');
check('that is 01:00 UTC', gmdate('H:i', Galado_GC_Time::delivery_timestamp('2026-12-25', $now)), '01:00');
check('today after 9am: right now', Galado_GC_Time::delivery_timestamp('2026-09-26', $now), $now);
$early = $ts('2026-09-26 07:30:00');
check('today before 9am: right now too (the handover: "at once if the date is today")',
    Galado_GC_Time::delivery_timestamp('2026-09-26', $early), $early);
check('tomorrow, bought at 23:30 the night before: 09:00 tomorrow, not at once',
    $fmt(Galado_GC_Time::delivery_timestamp('2026-09-27', $ts('2026-09-26 23:30:00'))), '2026-09-27 09:00:00');
check('"today" is the Malaysian date: 00:30 MYT on the 27th (still the 26th in UTC) sends a card dated the 27th at once',
    Galado_GC_Time::delivery_timestamp('2026-09-27', $ts('2026-09-27 00:30:00')), $ts('2026-09-27 00:30:00'));
check('a past date: right now', Galado_GC_Time::delivery_timestamp('2026-09-01', $now), $now);
check('an unparseable date: right now, never silently dropped', Galado_GC_Time::delivery_timestamp('not-a-date', $now), $now);

echo "-- delivery date the buyer may pick: today to one year ahead (Malaysian dates)\n";
check('today is allowed', Galado_GC_Time::is_valid_delivery_date('2026-09-26', $now), true);
check('yesterday is not', Galado_GC_Time::is_valid_delivery_date('2026-09-25', $now), false);
check('365 days ahead is allowed', Galado_GC_Time::is_valid_delivery_date('2027-09-26', $now), true);
check('366 days ahead is not', Galado_GC_Time::is_valid_delivery_date('2027-09-27', $now), false);
check('31 Feb is not a date', Galado_GC_Time::is_valid_delivery_date('2027-02-31', $now), false);
$late = $ts('2026-09-26 23:30:00'); // 15:30 UTC, still the 26th in Malaysia
check('at 23:30 MYT "today" is still the Malaysian date', Galado_GC_Time::today($late), '2026-09-26');
$after_midnight = $ts('2026-09-27 00:10:00'); // 16:10 UTC on the 26th
check('at 00:10 MYT the 26th is already yesterday', Galado_GC_Time::is_valid_delivery_date('2026-09-26', $after_midnight), false);
check('customers read "26 September 2029"', Galado_GC_Time::human_date($ts('2029-09-26 23:59:59')), '26 September 2029');

done();

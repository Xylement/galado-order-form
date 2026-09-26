<?php
/**
 * Delivery: the Action Scheduler job, both emails and their content, once only, the Stripe risk
 * hold with admin release, and the 3-D Secure request.
 */
require __DIR__ . '/bootstrap.php';

gct_product();
$tz = new DateTimeZone('Asia/Kuala_Lumpur');

echo "-- a card for today goes out at once, a later date at 09:00 Malaysian time\n";
$today = gct_paid_order([['value' => 100, 'email' => 'jade@example.test', 'name' => 'Jade', 'message' => "Happy birthday, Jade! <script>x</script>"]]);
$later_date = (new DateTimeImmutable('now', $tz))->modify('+40 days')->format('Y-m-d');
$later = gct_paid_order([['value' => 50, 'label' => 'RM50', 'date' => $later_date, 'email' => 'later@example.test']]);
$later_item = current($later->get_items());
$next = as_next_scheduled_action(Galado_GC_Delivery::HOOK, [$later->get_id(), $later_item->get_id()], Galado_GC_Delivery::GROUP);
check('the later card is scheduled for 09:00 MYT on its date',
    is_int($next) ? (new DateTimeImmutable('@' . $next))->setTimezone($tz)->format('Y-m-d H:i') : $next, $later_date . ' 09:00');
gct_mail_reset();
gct_run_jobs();
$to = gct_mails_to('jade@example.test');
check("today's card: one email to the recipient", count($to), 1);
check('nothing for the later card yet', count(gct_mails_to('later@example.test')), 0);

echo "-- what the recipient reads\n";
$code = strtoupper(gct_coupons($today)[0]->get_code());
$html = $to[0]['message'];
$text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
check('subject names the buyer', $to[0]['subject'], 'Clement sent you a GALADO gift card');
check('the code', false !== strpos($text, $code), true);
check('the value', false !== strpos($text, 'RM100'), true);
$expiry = gct_coupons($today)[0]->get_date_expires()->setTimezone($tz)->format('j F Y');
check('the expiry date', false !== strpos($text, 'Valid until') && false !== strpos($text, $expiry), true);
check('the message, escaped', [false !== strpos($text, 'Happy birthday, Jade!'), false !== strpos($html, '<script>')], [true, false]);
check('where to use it', false !== strpos($text, 'Use it at checkout on galado.com.my, in the promo code box in the GALADO app, or at our counter.'), true);
check('the rule', false !== strpos($text, 'Single use: spend it in one order. Any unused value is lost.'), true);
check('no em or en dash in anything our template writes', preg_match('/\x{2013}|\x{2014}/u', str_replace(wp_strip_all_tags(get_option('woocommerce_email_footer_text')), '', $text)), 0);

echo "-- the buyer is told, without a spendable copy of the code\n";
$buyer = gct_mails_to('buyer@example.test');
check('one "your gift card was sent" email to the buyer', count($buyer), 1);
$btext = wp_strip_all_tags($buyer[0]['message']);
check('it names the recipient and the last four characters', [false !== strpos($btext, 'Jade (jade@example.test)'), false !== strpos($btext, 'ending ' . substr($code, -4))], [true, true]);
check('never the whole code', false !== strpos($btext, $code), false);

echo "-- sent once\n";
gct_mail_reset();
Galado_GC_Delivery::deliver($today->get_id(), current($today->get_items())->get_id());
Galado_GC_Delivery::schedule_for_order($today->get_id());
gct_run_jobs();
check('running the job again, and rescheduling, sends nothing more', count($GLOBALS['__mail']), 0);
check('the line records when it was sent', (bool) current(wc_get_order($today->get_id())->get_items())->get_meta('_galado_gc_sent_at'), true);

echo "-- a payment Stripe rates elevated risk is held until released\n";
$held = wc_create_order();
$held->set_billing_email('buyer@example.test');
$item = new WC_Order_Item_Product();
$item->set_product(wc_get_product(gct_variation('RM300')));
$item->set_total('300');
foreach (['_galado_gc_value' => '300.00', '_galado_gc_recipient_name' => 'Risky', '_galado_gc_recipient_email' => 'risky@example.test', '_galado_gc_message' => '', '_galado_gc_delivery_date' => Galado_GC_Time::today()] as $k => $v) {
    $item->add_meta_data($k, $v, true);
}
$held->add_item($item);
$held->calculate_totals();
$held->save();
// Exactly what Payment Plugins for Stripe does in payment_complete(): this action with the charge, then save, then paid.
do_action('wc_stripe_save_order_meta', $held, null, (object) ['outcome' => (object) ['risk_level' => 'elevated']], null);
$held->save();
$held->payment_complete('ch_test');
gct_mail_reset();
gct_run_jobs();
$held = wc_get_order($held->get_id());
check('the code is issued (the card exists)', count(gct_coupons($held)), 1);
check('but nothing is emailed', count(gct_mails_to('risky@example.test')), 0);
check('risk level and hold recorded', [$held->get_meta('_galado_gc_risk_level'), $held->get_meta('_galado_gc_hold')], ['elevated', 'elevated']);
check('staff are told what to do', false !== strpos(gct_notes_text($held), 'Release held gift cards'), true);
check('the order actions offer the release', array_key_exists('galado_gc_release', apply_filters('woocommerce_order_actions', [], $held)), true);
wp_set_current_user(0);
Galado_GC_Delivery::action_release($held);
gct_run_jobs();
check('negative control: a visitor cannot release it', count(gct_mails_to('risky@example.test')), 0);
wp_set_current_user(1);
Galado_GC_Delivery::action_release(wc_get_order($held->get_id()));
gct_run_jobs();
check('released by an admin: the card goes out', count(gct_mails_to('risky@example.test')), 1);
$normal = wc_create_order();
$normal->add_item(clone $item);
$normal->save();
do_action('wc_stripe_save_order_meta', $normal, null, (object) ['outcome' => (object) ['risk_level' => 'normal']], null);
check('a normal-risk payment is recorded but not held', [$normal->get_meta('_galado_gc_risk_level'), $normal->get_meta('_galado_gc_hold')], ['normal', '']);
update_option('galado_gift_cards_risk_hold', 'no');
$off = wc_create_order();
$off->add_item(clone $item);
$off->save();
do_action('wc_stripe_save_order_meta', $off, null, (object) ['outcome' => (object) ['risk_level' => 'highest']], null);
check('with the hold setting off, even highest is not held', $off->get_meta('_galado_gc_hold'), '');
delete_option('galado_gift_cards_risk_hold');

echo "-- 3-D Secure on card payments for gift card orders only\n";
$args = apply_filters('wc_stripe_payment_intent_args', ['payment_method_types' => ['card'], 'amount' => 30000], $held, null);
check('card payment for a gift card order: request_three_d_secure = any', $args['payment_method_options']['card']['request_three_d_secure'] ?? null, 'any');
$args = apply_filters('wc_stripe_payment_intent_args', ['payment_method_types' => ['grabpay']], $held, null);
check('GrabPay is left alone', isset($args['payment_method_options']), false);
$plain = wc_create_order();
$plain_item = new WC_Order_Item_Product();
$plain_item->set_product(wc_get_product(gct_charm()));
$plain->add_item($plain_item);
$plain->save();
$args = apply_filters('wc_stripe_payment_intent_args', ['payment_method_types' => ['card']], $plain, null);
check('an order without a gift card is left alone', isset($args['payment_method_options']), false);

done();

<?php
/**
 * Public-facing warranty registration form.
 *
 * Shortcode: [galado_warranty_register]
 * Behaviour:
 *  - Not logged in: shows a login/register CTA (uses WC My Account URL).
 *  - Logged in: renders the form. POSTs are handled here on the same page.
 */

if (!defined('ABSPATH')) exit;

add_shortcode('galado_warranty_register', 'gwarr_render_register_form');

/**
 * Render the registration form OR a result/notice depending on state.
 */
function gwarr_render_register_form($atts = []) {
    ob_start();

    // ---- Handle submission first so the result message renders inline. ----
    $submission_notice = '';
    if (
        isset($_POST['gwarr_submit'], $_POST['gwarr_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_nonce'])), 'gwarr_register')
    ) {
        $submission_notice = gwarr_handle_form_submission();
    }

    // ---- Not logged in: show the login/register prompt. ----
    if (!is_user_logged_in()) {
        $account_url = wc_get_page_permalink('myaccount');
        ?>
        <div class="gwarr-card gwarr-card-login">
            <h3>Register your warranty</h3>
            <p>You need a free GALADO account to register your warranty. It takes 30 seconds and lets you view your warranty status, redeem your welcome coupon, and access future perks.</p>
            <p>
                <a class="button gwarr-btn" href="<?php echo esc_url($account_url); ?>">Log in or create an account</a>
            </p>
            <p class="gwarr-fineprint">Already registered? <a href="<?php echo esc_url($account_url); ?>">Log in here</a> and come back to this page.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    // ---- Logged in: render notice (if any) + form. ----
    if ($submission_notice !== '') {
        echo $submission_notice;
    }

    $user           = wp_get_current_user();
    $marketplaces   = GWARR_Marketplaces::all();
    $form_values    = gwarr_form_repost_values();
    $my_warranties  = gwarr_my_warranties_url();
    ?>
    <div class="gwarr-card">
        <h3>Register your warranty</h3>
        <p class="gwarr-lede">
            Bought from a marketplace (Shopee, Lazada, TikTok, WhatsApp, social)?
            Register here to extend your warranty from <strong>1 month to 6 months</strong> and unlock a welcome discount for your next purchase on galado.com.my.
        </p>

        <form method="post" class="gwarr-form" novalidate>
            <?php wp_nonce_field('gwarr_register', 'gwarr_nonce'); ?>

            <?php
            // Initial placeholder reflects the saved selection so the field is
            // helpful even before JS attaches; the switcher in script.js keeps
            // it in sync as the customer changes marketplace.
            $initial_example = $form_values['marketplace']
                ? GWARR_Marketplaces::order_example($form_values['marketplace'])
                : 'e.g. 260609KXBRPS2K';
            $initial_placeholder = $initial_example !== '' ? 'e.g. ' . $initial_example : 'Order number';
            ?>
            <div class="gwarr-row gwarr-row-2">
                <label class="gwarr-field">
                    <span class="gwarr-label">Where did you buy from?</span>
                    <select name="marketplace" required>
                        <option value="">— Select marketplace —</option>
                        <?php foreach ($marketplaces as $slug => $label): ?>
                            <option value="<?php echo esc_attr($slug); ?>"
                                    data-example="<?php echo esc_attr(GWARR_Marketplaces::order_example($slug)); ?>"
                                    <?php selected($form_values['marketplace'], $slug); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="gwarr-field">
                    <span class="gwarr-label">Order number</span>
                    <input type="text" name="order_number" maxlength="64"
                           value="<?php echo esc_attr($form_values['order_number']); ?>"
                           placeholder="<?php echo esc_attr($initial_placeholder); ?>" required>
                </label>
            </div>

            <label class="gwarr-field">
                <span class="gwarr-label">Anything we should know? <span class="gwarr-optional">(optional)</span></span>
                <textarea name="notes" rows="3" maxlength="500"
                          placeholder="Optional notes — e.g. gift recipient, return concerns, etc."><?php echo esc_textarea($form_values['notes']); ?></textarea>
            </label>

            <label class="gwarr-consent">
                <input type="checkbox" name="marketing_consent" value="1" checked>
                <span>Yes, send me GALADO promotions, new arrivals, and exclusive perks by email. You can unsubscribe any time.</span>
            </label>

            <p class="gwarr-actions">
                <button type="submit" name="gwarr_submit" value="1" class="button gwarr-btn">Submit registration</button>
                <a href="<?php echo esc_url($my_warranties); ?>" class="gwarr-link">View my warranties</a>
            </p>

            <p class="gwarr-fineprint">
                We'll verify your order against our records. Once approved, you'll receive an email with your warranty period and a welcome coupon.
            </p>
        </form>
    </div>
    <?php

    return ob_get_clean();
}

/**
 * Sticky values for the form when validation fails — keep what the customer typed.
 */
function gwarr_form_repost_values() {
    $values = [
        'marketplace'  => '',
        'order_number' => '',
        'notes'        => '',
    ];
    foreach ($values as $key => $_) {
        if (isset($_POST[$key])) {
            $values[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
        }
    }
    return $values;
}

/**
 * Process a posted registration. Returns a notice HTML string to render inline.
 */
function gwarr_handle_form_submission() {
    if (!is_user_logged_in()) {
        return gwarr_notice('error', 'Please log in before submitting.');
    }

    $marketplace = isset($_POST['marketplace']) ? sanitize_key(wp_unslash($_POST['marketplace'])) : '';
    $order       = isset($_POST['order_number']) ? trim(sanitize_text_field(wp_unslash($_POST['order_number']))) : '';
    $notes       = isset($_POST['notes']) ? trim(sanitize_textarea_field(wp_unslash($_POST['notes']))) : '';
    $consent     = !empty($_POST['marketing_consent']);

    // Validate.
    $errors = [];
    if (!GWARR_Marketplaces::is_valid($marketplace)) {
        $errors[] = 'Please select a marketplace.';
    }
    if ($order === '') {
        $errors[] = 'Order number is required.';
    } elseif (strlen($order) > 64) {
        $errors[] = 'Order number is too long.';
    }
    if (!empty($errors)) {
        return gwarr_notice('error', implode('<br>', array_map('esc_html', $errors)));
    }

    // product_text stays in the schema (Phase 2 fills it from the sheet)
    // — we just don't ask the customer for it.
    $id = GWARR_DB::insert([
        'user_id'           => get_current_user_id(),
        'marketplace'       => $marketplace,
        'order_number'      => $order,
        'product_text'      => '',
        'notes'             => $notes,
        'marketing_consent' => $consent ? 1 : 0,
        'status'            => 'pending',
    ]);

    if (is_wp_error($id)) {
        if ($id->get_error_code() === 'gwarr_duplicate') {
            return gwarr_render_duplicate_notice($id, $marketplace, $order);
        }
        return gwarr_notice('error', esc_html($id->get_error_message()));
    }

    // Auto-approve if the order is in the local sheet cache. Cheap: this is
    // a primary-key lookup against wp_galado_warranty_sheet_cache, not a
    // network call. The Sheets API is only ever hit by the WP-Cron sync.
    $settings   = get_option('gwarr_settings', []);
    $auto_on    = !empty($settings['auto_approve']);
    $autoresult = false;
    if ($auto_on && class_exists('GWARR_Auto_Approve')) {
        $autoresult = GWARR_Auto_Approve::try_for($id);
    }

    // If we didn't auto-approve, notify admin a pending registration arrived.
    if (!$autoresult && function_exists('gwarr_send_admin_new_registration_email')) {
        $row = GWARR_DB::find($id);
        if ($row) {
            gwarr_send_admin_new_registration_email($row);
        }
    }

    // Clear $_POST so the form doesn't repopulate after a successful submit.
    $_POST = [];

    if ($autoresult) {
        $row = GWARR_DB::find($id);
        $msg  = '<strong>You\'re all set — warranty extended.</strong> ';
        if ($row && $row->warranty_ends) {
            $msg .= 'Your warranty is now covered until <strong>' . esc_html(mysql2date('F j, Y', $row->warranty_ends)) . '</strong>. ';
        }
        $msg .= 'We\'ve emailed you your welcome coupon. ';
        $msg .= 'See full details on <a href="' . esc_url(gwarr_my_warranties_url()) . '">My Warranties</a>.';
        return gwarr_notice('success', $msg);
    }

    $msg  = '<strong>Thanks — we got your registration.</strong> ';
    $msg .= 'We\'ll verify your order against our records and email you when your warranty is approved (usually within 1 business day). ';
    $msg .= 'You can check status any time on <a href="' . esc_url(gwarr_my_warranties_url()) . '">My Warranties</a>.';
    return gwarr_notice('success', $msg);
}

function gwarr_notice($type, $html) {
    $valid = ['success', 'error', 'info'];
    $type  = in_array($type, $valid, true) ? $type : 'error';
    return '<div class="gwarr-notice gwarr-notice-' . esc_attr($type) . '">' . $html . '</div>';
}

/**
 * Build the inline notice shown when GWARR_DB::insert() flagged a duplicate.
 * Branches between "you already registered this" and "someone else claimed it",
 * and fires the admin alert in the cross-account case.
 */
function gwarr_render_duplicate_notice($wp_error, $marketplace, $order_number) {
    $data      = $wp_error->get_error_data();
    $same_user = !empty($data['same_user']);
    $existing  = isset($data['existing']) ? $data['existing'] : null;

    if ($same_user) {
        $msg  = '<strong>You\'ve already registered this order.</strong> ';
        $msg .= 'You can see its status';
        if ($existing && !empty($existing->coupon_code)) {
            $msg .= ' and your welcome coupon';
        }
        $msg .= ' on your <a href="' . esc_url(gwarr_my_warranties_url()) . '">My Warranties</a> page.';
        return gwarr_notice('info', $msg);
    }

    // Different user — could be the same person with two accounts (forgot which
    // email they used) or an unauthorised claim. Alert admin either way.
    if ($existing && function_exists('gwarr_send_admin_cross_claim_alert')) {
        gwarr_send_admin_cross_claim_alert($existing, get_current_user_id());
    }
    if (function_exists('error_log')) {
        error_log(sprintf(
            '[galado-warranty] Cross-claim attempt: user %d tried to register %s order %s already registered by user %d (status: %s)',
            (int) get_current_user_id(),
            $marketplace,
            $order_number,
            $existing ? (int) $existing->user_id : 0,
            $existing ? $existing->status : 'unknown'
        ));
    }

    $msg  = '<strong>This order number is already registered to another account.</strong> ';
    $msg .= 'If you registered it under a different email, please log in with that account. ';
    $msg .= 'If you believe this is a mistake, please contact us — we\'ve notified our team to look into it.';
    return gwarr_notice('error', $msg);
}

/**
 * URL to the "My Warranties" view (WC My Account tab).
 */
function gwarr_my_warranties_url() {
    if (function_exists('wc_get_account_endpoint_url')) {
        return wc_get_account_endpoint_url('warranties');
    }
    return wc_get_page_permalink('myaccount');
}

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
 * Process form POSTs BEFORE any HTML is sent, so we can do PRG (POST →
 * redirect → GET). Without this, the success notice was only visible
 * in the POST response — refreshing the page or relying on themes /
 * page-cache behaviour could swallow it on the first submit.
 *
 * Flow:
 *   1. template_redirect fires (still no output sent)
 *   2. We process the form, stash the notice in a per-user transient
 *   3. wp_safe_redirect back to the same URL (now a GET request)
 *   4. The shortcode renders normally, reads the notice from the transient
 *      and renders it once
 */
add_action('template_redirect', 'gwarr_maybe_process_register_form');

function gwarr_maybe_process_register_form() {
    if (empty($_POST['gwarr_submit']) || empty($_POST['gwarr_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_nonce'])), 'gwarr_register')) {
        return;
    }
    if (!is_user_logged_in()) {
        return;
    }

    gwarr_mark('prg: handler start');
    $result = gwarr_handle_form_submission();
    gwarr_mark('prg: handler returned');

    if (!empty($result['notice'])) {
        set_transient('gwarr_form_notice_' . get_current_user_id(), $result['notice'], 60);
    }

    gwarr_mark('prg: before redirect');
    gwarr_store_marks([
        'ok'           => !empty($result['ok']),
        'total_php_ms' => round((microtime(true) - (isset($GLOBALS['timestart']) ? (float) $GLOBALS['timestart'] : microtime(true))) * 1000, 1),
    ]);

    if (!empty($result['ok'])) {
        // Success → land them on My Warranties. That page is a WooCommerce
        // account endpoint (never edge-cached) and LISTS the warranty they
        // just registered, so they get unambiguous confirmation even if the
        // transient notice were ever lost to caching. The same transient is
        // popped + shown there too.
        $url = gwarr_my_warranties_url();
    } else {
        // Error / duplicate → stay on the form so they can correct and retry.
        $url = remove_query_arg(['gwarr_submit']);
        if (!$url) {
            $url = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '/'));
        }
    }

    wp_safe_redirect($url);
    exit;
}

/**
 * Render the registration form OR a result/notice depending on state.
 */
function gwarr_render_register_form($atts = []) {
    ob_start();

    // Pop any notice stashed by the template_redirect handler above.
    $submission_notice = '';
    if (is_user_logged_in()) {
        $notice_key = 'gwarr_form_notice_' . get_current_user_id();
        $stashed    = get_transient($notice_key);
        if (is_string($stashed) && $stashed !== '') {
            $submission_notice = $stashed;
            delete_transient($notice_key);
        }
    }

    // ---- Not logged in: show the login/register prompt. ----
    if (!is_user_logged_in()) {
        gwarr_render_login_prompt();
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
    <?php
    // Tier-aware messaging: Black Club members see "1 month to 12 months",
    // everyone else sees "1 month to 6 months". Helper falls back to the
    // configured standard if Club is unreachable, so this is always safe.
    $current_user_email = is_user_logged_in() ? wp_get_current_user()->user_email : '';
    $extension_months   = function_exists('galado_warranty_months_for_email')
        ? max(1, (int) galado_warranty_months_for_email($current_user_email))
        : 6;
    ?>
    <div class="gwarr-card">
        <h3>Register your warranty</h3>
        <p class="gwarr-lede">
            Bought from Shopee, Lazada, or TikTok? Register here to extend your warranty from
            <strong>1 month to <?php echo (int) $extension_months; ?> months</strong> and get a welcome coupon for <strong><?php echo esc_html(gwarr_perk_description()); ?></strong> on your next purchase at galado.com.my.
        </p>

        <p class="gwarr-coverage-note">
            🛡 Wondering what the warranty covers? See our <a href="<?php echo esc_url(gwarr_coverage_url()); ?>" target="_blank" rel="noopener">satisfaction guarantee details</a>.
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

    <?php gwarr_render_processing_overlay(); ?>
    <?php

    return ob_get_clean();
}

/**
 * Full-screen "processing" overlay shown while the registration POST is in
 * flight. The form uses POST → redirect → GET, so server-side work (Club
 * webhook, sheet auto-approve lookup, emails) happens before the browser
 * navigates — this gives the customer feedback during that wait instead of
 * a page that looks frozen. JS in script.js reveals it on submit and cycles
 * the status messages; the redirect to the result page tears it down.
 */
function gwarr_render_processing_overlay() {
    ?>
    <div id="gwarr-processing" class="gwarr-processing" hidden aria-hidden="true" role="status" aria-live="polite">
        <div class="gwarr-processing-inner">
            <div class="gwarr-spinner" aria-hidden="true"></div>
            <p class="gwarr-processing-title">Registering your warranty…</p>
            <p class="gwarr-processing-step" id="gwarr-processing-step">Sending your details securely</p>
            <div class="gwarr-progress" aria-hidden="true">
                <div class="gwarr-progress-fill" id="gwarr-progress-fill"></div>
            </div>
            <p class="gwarr-processing-hint">This can take a minute or two — please keep this page open and don't refresh.</p>
        </div>
    </div>
    <?php
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
 * Build the structured result the PRG handler branches on.
 * @return array{ok:bool,notice:string}
 */
function gwarr_result($ok, $notice) {
    return ['ok' => (bool) $ok, 'notice' => (string) $notice];
}

/**
 * Process a posted registration.
 * @return array{ok:bool,notice:string} ok=true means a row was registered
 *         (pending or auto-approved); ok=false means error/duplicate.
 */
function gwarr_handle_form_submission() {
    if (!is_user_logged_in()) {
        return gwarr_result(false, gwarr_notice('error', 'Please log in before submitting.'));
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
        return gwarr_result(false, gwarr_notice('error', implode('<br>', array_map('esc_html', $errors))));
    }

    // product_text stays in the schema (Phase 2 fills it from the sheet)
    // — we just don't ask the customer for it.
    gwarr_mark('insert: start');
    $id = GWARR_DB::insert([
        'user_id'           => get_current_user_id(),
        'marketplace'       => $marketplace,
        'order_number'      => $order,
        'product_text'      => '',
        'notes'             => $notes,
        'marketing_consent' => $consent ? 1 : 0,
        'status'            => 'pending',
    ]);
    gwarr_mark('insert: done');

    if (is_wp_error($id)) {
        if ($id->get_error_code() === 'gwarr_duplicate') {
            return gwarr_result(false, gwarr_render_duplicate_notice($id, $marketplace, $order));
        }
        return gwarr_result(false, gwarr_notice('error', esc_html($id->get_error_message())));
    }

    // Fire the GALADO Club welcome-pack webhook (Registered badge + Guardian
    // pet + 50 G-Coins + branded welcome email, all granted by the Club).
    // Fire-and-forget + idempotent per member, so it's safe on every
    // registration and never blocks the customer. Keyed on the logged-in
    // user's email (the form requires login, so this is always present).
    if (function_exists('galado_club_notify_warranty')) {
        $club_email = wp_get_current_user()->user_email;
        galado_club_notify_warranty($club_email, $id, [
            'order_id'    => $order,
            'marketplace' => $marketplace,
        ]);
    }
    gwarr_mark('club notify: done');

    // Auto-approve if the order is in the local sheet cache. Cheap: this is
    // a primary-key lookup against wp_galado_warranty_sheet_cache, not a
    // network call. The Sheets API is only ever hit by the WP-Cron sync.
    $settings   = get_option('gwarr_settings', []);
    $auto_on    = !empty($settings['auto_approve']);
    $autoresult = false;
    if ($auto_on && class_exists('GWARR_Auto_Approve')) {
        gwarr_mark('auto-approve: start');
        $autoresult = GWARR_Auto_Approve::try_for($id);
        gwarr_mark('auto-approve: done (' . ($autoresult ? 'approved' : 'no match') . ')');
    }

    // If we didn't auto-approve, notify admin a pending registration arrived.
    // Deferred past the response flush so the customer isn't kept waiting on
    // an admin email they never see.
    if (!$autoresult && function_exists('gwarr_send_admin_new_registration_email')) {
        $row = GWARR_DB::find($id);
        if ($row) {
            if (class_exists('GWARR_Deferred')) {
                GWARR_Deferred::add(function () use ($row) {
                    gwarr_send_admin_new_registration_email($row);
                });
            } else {
                gwarr_send_admin_new_registration_email($row);
            }
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
        $msg .= 'We\'ve emailed you your welcome coupon. Your warranty is shown below.';
        return gwarr_result(true, gwarr_notice('success', $msg));
    }

    $msg  = '<strong>Thanks — we got your registration.</strong> ';
    $msg .= 'We\'ll verify your order against our records and email you when your warranty is approved (usually within 1 business day). ';
    $msg .= 'Your registration is shown below.';
    return gwarr_result(true, gwarr_notice('success', $msg));
}

/**
 * Render the "log in or create an account" prompt + the modal markup.
 * The modal is mounted once on the page; clicking either button reveals
 * it instead of redirecting to /my-account/. AJAX endpoints below.
 */
function gwarr_render_login_prompt() {
    ?>
    <div class="gwarr-card gwarr-card-login">
        <h3>Register your warranty</h3>
        <p>You need a free GALADO account to register your warranty. It takes 30 seconds and lets you view your warranty status, redeem your welcome coupon, and access future perks.</p>
        <p>
            <button type="button" class="button gwarr-btn" data-gwarr-auth="register">Create an account</button>
            <button type="button" class="button gwarr-btn-secondary" data-gwarr-auth="login">Log in</button>
        </p>
        <p class="gwarr-fineprint">No need to leave this page — your warranty registration form will appear right after you sign in.</p>
    </div>

    <?php gwarr_render_auth_modal(); ?>
    <?php
}

/**
 * The shared AJAX login/register modal. Rendered once whenever the login
 * prompt is shown.
 */
function gwarr_render_auth_modal() {
    ?>
    <div id="gwarr-auth-modal" class="gwarr-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="gwarr-auth-modal-title">
        <div class="gwarr-modal-overlay" data-gwarr-modal-close></div>
        <div class="gwarr-modal-dialog">
            <button type="button" class="gwarr-modal-close" data-gwarr-modal-close aria-label="Close">&times;</button>

            <div class="gwarr-modal-tabs" role="tablist">
                <button type="button" class="gwarr-modal-tab is-active" data-gwarr-tab="login" role="tab" aria-selected="true">Log in</button>
                <button type="button" class="gwarr-modal-tab" data-gwarr-tab="register" role="tab" aria-selected="false">Create account</button>
            </div>

            <h3 id="gwarr-auth-modal-title" class="gwarr-modal-title">Log in to GALADO</h3>

            <form class="gwarr-modal-form is-active" data-gwarr-form="login" novalidate>
                <label class="gwarr-modal-field">
                    <span>Email or username</span>
                    <input type="text" name="username" autocomplete="username" required>
                </label>
                <label class="gwarr-modal-field">
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <label class="gwarr-modal-remember">
                    <input type="checkbox" name="remember" value="1" checked>
                    <span>Remember me</span>
                </label>
                <button type="submit" class="button gwarr-btn gwarr-modal-submit">Log in</button>
                <p class="gwarr-modal-error" role="alert"></p>
            </form>

            <form class="gwarr-modal-form" data-gwarr-form="register" novalidate hidden>
                <div class="gwarr-modal-row">
                    <label class="gwarr-modal-field">
                        <span>First name</span>
                        <input type="text" name="first_name" autocomplete="given-name">
                    </label>
                    <label class="gwarr-modal-field">
                        <span>Last name</span>
                        <input type="text" name="last_name" autocomplete="family-name">
                    </label>
                </div>
                <label class="gwarr-modal-field">
                    <span>Email</span>
                    <input type="email" name="email" autocomplete="email" required>
                </label>
                <label class="gwarr-modal-field">
                    <span>Create a password</span>
                    <input type="password" name="password" autocomplete="new-password" minlength="8" required>
                    <small>At least 8 characters.</small>
                </label>
                <button type="submit" class="button gwarr-btn gwarr-modal-submit">Create account</button>
                <p class="gwarr-modal-fineprint">By creating an account you agree to receive a one-time confirmation and (if you opt in) occasional emails about your warranty and offers.</p>
                <p class="gwarr-modal-error" role="alert"></p>
            </form>
        </div>
    </div>
    <?php
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

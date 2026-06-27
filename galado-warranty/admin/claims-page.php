<?php
/**
 * Admin: warranty-claim review queue. Lists customer claims with their issue
 * description + uploaded media, and lets staff approve (→ warranty flips to
 * 'claimed') or reject (with a reason). Customer emails are sent on resolution.
 */

if (!defined('ABSPATH')) exit;

function gwarr_render_claims_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Insufficient permissions.');
    }

    $action_notice = gwarr_handle_claim_admin_post();

    $status   = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
    $paged    = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $per_page = 20;

    $counts = GWARR_Claims::status_counts();
    $result = GWARR_Claims::list(['status' => $status, 'per_page' => $per_page, 'page' => $paged]);
    $rows   = $result['rows'];
    $total  = $result['total'];
    ?>
    <div class="wrap gwarr-admin">
        <h1>Warranty Claims</h1>
        <?php echo $action_notice; // already-escaped notice HTML ?>

        <ul class="subsubsub">
            <?php
            $filters = [
                ''          => ['label' => 'All',          'count' => $counts['all']],
                'submitted' => ['label' => 'Pending',      'count' => $counts['submitted']],
                'approved'  => ['label' => 'Approved',     'count' => $counts['approved']],
                'rejected'  => ['label' => 'Declined',     'count' => $counts['rejected']],
            ];
            $i = 0;
            foreach ($filters as $slug => $info) {
                $url = add_query_arg(['page' => 'galado-warranty-claims', 'status' => $slug], admin_url('admin.php'));
                $cls = ($status === $slug) ? 'current' : '';
                echo ($i++ ? ' | ' : '') . '<li><a href="' . esc_url($url) . '" class="' . esc_attr($cls) . '">'
                    . esc_html($info['label']) . ' <span class="count">(' . (int) $info['count'] . ')</span></a></li>';
            }
            ?>
        </ul>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:90px;">Status</th>
                    <th style="width:180px;">Customer</th>
                    <th>Item / Order</th>
                    <th>Issue &amp; media</th>
                    <th style="width:140px;">Submitted</th>
                    <th style="width:240px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6">No claims<?php echo $status ? ' with this status' : ' yet'; ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row) { gwarr_render_claim_admin_row($row); } ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php gwarr_render_claims_pagination($total, $per_page, $paged, $status); ?>
    </div>
    <?php
}

function gwarr_render_claim_admin_row($row) {
    $status_text = ['submitted' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Declined'][$row->status] ?? ucfirst($row->status);
    $status_cls  = ['submitted' => 'gwarr-badge-pending', 'approved' => 'gwarr-badge-approved', 'rejected' => 'gwarr-badge-rejected'][$row->status] ?? '';

    $is_website = isset($row->source) && $row->source === 'website';
    $order      = ($is_website && !empty($row->wc_order_id)) ? '#' . $row->wc_order_id : $row->order_number;
    $media      = GWARR_Claims::media_ids($row);
    ?>
    <tr>
        <td><span class="gwarr-badge <?php echo esc_attr($status_cls); ?>"><?php echo esc_html($status_text); ?></span></td>
        <td>
            <strong><?php echo esc_html($row->display_name ?: '(no name)'); ?></strong><br>
            <small><?php echo esc_html($row->user_email ?: '—'); ?></small>
        </td>
        <td>
            <?php if (!empty($row->item_label)): ?>
                <strong class="gwarr-claim-item"><?php echo esc_html($row->item_label); ?></strong>
                <?php if ($row->product_text && count(gwarr_parse_product_items($row->product_text)) > 1): ?>
                    <br><small style="color:#888;">of <?php echo (int) count(gwarr_parse_product_items($row->product_text)); ?> items on this order</small>
                <?php endif; ?>
            <?php else: ?>
                <?php echo $row->product_text ? gwarr_format_product_email($row->product_text) : '<span style="color:#999;">—</span>'; ?>
            <?php endif; ?>
            <br><small><?php echo esc_html(GWARR_Marketplaces::label($row->marketplace)); ?> · <code><?php echo esc_html($order); ?></code></small>
        </td>
        <td>
            <div class="gwarr-claim-issue"><?php echo nl2br(esc_html($row->issue_description)); ?></div>
            <?php if ($media): ?>
                <div class="gwarr-claim-media">
                    <?php foreach ($media as $att_id): ?>
                        <?php gwarr_render_claim_media_thumb($att_id); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </td>
        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' g:i a', $row->created_at)); ?></td>
        <td><?php gwarr_render_claim_admin_actions($row); ?></td>
    </tr>
    <?php
}

/**
 * Render a thumbnail (image) or a typed link (video/other) for a claim
 * attachment, linking to the full file.
 */
function gwarr_render_claim_media_thumb($att_id) {
    $url = wp_get_attachment_url($att_id);
    if (!$url) {
        return;
    }
    if (wp_attachment_is_image($att_id)) {
        $thumb = wp_get_attachment_image($att_id, [64, 64], false, ['style' => 'width:64px;height:64px;object-fit:cover;border-radius:6px;']);
        echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" style="display:inline-block;margin:2px;">' . $thumb . '</a>';
    } else {
        $is_video = strpos((string) get_post_mime_type($att_id), 'video/') === 0;
        echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" class="button button-small" style="margin:2px;">'
            . ($is_video ? '▶ Video' : '📎 File') . '</a>';
    }
}

function gwarr_render_claim_admin_actions($row) {
    if ($row->status !== 'submitted') {
        if (!empty($row->admin_note)) {
            echo '<small><em>' . esc_html($row->admin_note) . '</em></small><br>';
        }
        // Shipping-fee order status, if one was created on approval.
        if (!empty($row->shipping_order_id) && function_exists('wc_get_order')) {
            $o = wc_get_order((int) $row->shipping_order_id);
            if ($o) {
                $fee  = $row->shipping_fee !== null ? 'RM ' . number_format((float) $row->shipping_fee, 2) : '';
                $paid = $o->is_paid();
                echo '<small>Shipping ' . esc_html($fee) . ' — <strong style="color:' . ($paid ? '#00a32a' : '#dba617') . ';">' . ($paid ? 'Paid' : 'Unpaid') . '</strong> '
                    . '(<a href="' . esc_url($o->get_edit_order_url()) . '">order #' . (int) $row->shipping_order_id . '</a>)</small><br>';
            }
        } elseif ($row->status === 'approved' && $row->shipping_fee !== null && (float) $row->shipping_fee > 0) {
            echo '<small style="color:#d63638;">Shipping fee RM ' . number_format((float) $row->shipping_fee, 2) . ' set, but no WooCommerce order was created — the email has no pay button. Check the error log.</small><br>';
        }
        echo '<small>Resolved ' . esc_html(mysql2date('M j, Y', $row->resolved_at)) . '</small>';

        // Approval-email send status + resend (approved claims only).
        if ($row->status === 'approved') {
            $log = get_option('gwarr_claim_email_' . (int) $row->id, []);
            echo '<br>';
            if (!empty($log['at'])) {
                $ok = !empty($log['ok']);
                echo '<small>Approval email: <strong style="color:' . ($ok ? '#00a32a' : '#d63638') . ';">' . ($ok ? 'sent' : 'FAILED') . '</strong> '
                    . esc_html(mysql2date('M j, g:i a', $log['at'])) . ' &rarr; ' . esc_html($row->user_email) . '</small><br>';
            } else {
                echo '<small style="color:#888;">Approval email: status not recorded (approved before logging was added).</small><br>';
            }
            ?>
            <form method="post" style="margin-top:4px;" onsubmit="return confirm('Re-send the approval email<?php echo empty($row->shipping_order_id) ? '' : ' (with the pay-shipping button)'; ?> to <?php echo esc_attr($row->user_email); ?>?');">
                <?php wp_nonce_field('gwarr_claim_admin', 'gwarr_claim_admin_nonce'); ?>
                <input type="hidden" name="claim_id" value="<?php echo (int) $row->id; ?>">
                <button type="submit" name="gwarr_claim_action" value="resend_approved" class="button button-small">✉️ Resend approval email</button>
            </form>
            <?php
            // No pay order yet → let the admin add a fee and send a pay link now.
            if (empty($row->shipping_order_id)) {
                ?>
                <form method="post" style="margin-top:6px;" onsubmit="return confirm('Create a shipping order and email a pay link to <?php echo esc_attr($row->user_email); ?>?');">
                    <?php wp_nonce_field('gwarr_claim_admin', 'gwarr_claim_admin_nonce'); ?>
                    <input type="hidden" name="claim_id" value="<?php echo (int) $row->id; ?>">
                    <label style="font-size:11px;color:#666;">Charge shipping fee (RM)<br>
                        <input type="number" name="shipping_fee" min="0" step="0.01" placeholder="e.g. 8.00" style="width:100px;" required>
                    </label>
                    <button type="submit" name="gwarr_claim_action" value="charge_shipping" class="button button-small">💳 Create pay link &amp; email</button>
                </form>
                <?php
            }
        }
        return;
    }
    ?>
    <details class="gwarr-row-actions">
        <summary class="button button-primary">Resolve</summary>
        <div class="gwarr-action-panel">
            <form method="post" class="gwarr-approve-form" onsubmit="return confirm('Approve this claim? The warranty will be marked as claimed (greyed out for the customer).');">
                <?php wp_nonce_field('gwarr_claim_admin', 'gwarr_claim_admin_nonce'); ?>
                <input type="hidden" name="claim_id" value="<?php echo (int) $row->id; ?>">
                <label>Note to customer <small>(optional)</small><br>
                    <input type="text" name="admin_note" maxlength="300" placeholder="e.g. We'll ship a replacement strap once delivery is paid.">
                </label>
                <label>Replacement shipping fee (RM) <small>(optional)</small><br>
                    <input type="number" name="shipping_fee" min="0" step="0.01" placeholder="e.g. 8.00" style="width:120px;">
                </label>
                <p class="description" style="margin:4px 0 8px;max-width:280px;">If set, a pending WooCommerce order is created for the customer and the approval email includes a <strong>Pay shipping</strong> button. Leave blank for free shipping.</p>
                <p><button type="submit" name="gwarr_claim_action" value="approve" class="button button-primary">Approve claim</button></p>
            </form>

            <form method="post" class="gwarr-reject-form" onsubmit="return confirm('Decline this claim? The customer will be notified and can re-file.');">
                <?php wp_nonce_field('gwarr_claim_admin', 'gwarr_claim_admin_nonce'); ?>
                <input type="hidden" name="claim_id" value="<?php echo (int) $row->id; ?>">
                <label>Reason (shown to customer)<br>
                    <input type="text" name="admin_note" maxlength="300" placeholder="e.g. Damage looks accidental, not a defect" required>
                </label>
                <p><button type="submit" name="gwarr_claim_action" value="reject" class="button button-link-delete">Decline</button></p>
            </form>
        </div>
    </details>
    <?php
}

function gwarr_render_claims_pagination($total, $per_page, $paged, $status) {
    $pages = (int) ceil($total / $per_page);
    if ($pages <= 1) {
        return;
    }
    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo '<span class="displaying-num">' . (int) $total . ' item(s)</span>';
    for ($p = 1; $p <= $pages; $p++) {
        $url = add_query_arg(['page' => 'galado-warranty-claims', 'status' => $status, 'paged' => $p], admin_url('admin.php'));
        if ($p === $paged) {
            echo ' <span class="button button-primary" style="pointer-events:none;">' . $p . '</span>';
        } else {
            echo ' <a class="button" href="' . esc_url($url) . '">' . $p . '</a>';
        }
    }
    echo '</div></div>';
}

/**
 * Handle approve/reject POSTs. Sends customer email on resolution.
 */
function gwarr_handle_claim_admin_post() {
    if (empty($_POST['gwarr_claim_action'])) {
        return '';
    }
    if (!current_user_can('manage_woocommerce')) {
        return gwarr_admin_notice('error', 'Insufficient permissions.');
    }
    if (empty($_POST['gwarr_claim_admin_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_claim_admin_nonce'])), 'gwarr_claim_admin')) {
        return gwarr_admin_notice('error', 'Security check failed.');
    }

    $claim_id = isset($_POST['claim_id']) ? (int) $_POST['claim_id'] : 0;
    $action   = sanitize_key($_POST['gwarr_claim_action']);
    $note     = isset($_POST['admin_note']) ? sanitize_text_field(wp_unslash($_POST['admin_note'])) : '';
    $fee      = isset($_POST['shipping_fee']) ? max(0, round((float) $_POST['shipping_fee'], 2)) : 0;

    if ($claim_id <= 0) {
        return gwarr_admin_notice('error', 'Missing claim ID.');
    }

    if ($action === 'approve') {
        $result = GWARR_Claims::approve($claim_id, $note, $fee);
        if (is_wp_error($result)) {
            return gwarr_admin_notice('error', 'Approval failed: ' . esc_html($result->get_error_message()));
        }
        $ok = class_exists('GWARR_Email') ? (bool) GWARR_Email::send_claim_approved($result) : false;
        gwarr_record_claim_email_status($claim_id, $ok);

        $msg = 'Claim #' . $claim_id . ' approved — warranty marked as claimed.';
        if ($fee > 0) {
            $msg .= !empty($result->shipping_order_id)
                ? ' A RM ' . number_format($fee, 2) . ' shipping order (#' . (int) $result->shipping_order_id . ') was created with a pay link in the email.'
                : ' <strong>Shipping fee set, but the WooCommerce order could not be created — the email has no pay button. Check the error log.</strong>';
        }
        $msg .= $ok
            ? ' Approval email sent.'
            : ' <strong style="color:#d63638;">⚠ The approval email could not be sent (mailer returned false) — check your SMTP/email setup, then use “Resend”.</strong>';
        return gwarr_admin_notice($ok ? 'success' : 'error', $msg);
    }

    if ($action === 'resend_approved') {
        $claim = GWARR_Claims::find($claim_id);
        if (!$claim || $claim->status !== 'approved') {
            return gwarr_admin_notice('error', 'That claim is not approved, so there is no approval email to resend.');
        }
        $ok = class_exists('GWARR_Email') ? (bool) GWARR_Email::send_claim_approved($claim) : false;
        gwarr_record_claim_email_status($claim_id, $ok);
        return gwarr_admin_notice($ok ? 'success' : 'error', $ok
            ? 'Approval email re-sent for claim #' . $claim_id . '. If it still doesn\'t arrive, it\'s almost certainly mail delivery — check spam and your SMTP setup (try the “Send sample emails” button in Warranty Settings).'
            : 'Resend failed — the mailer returned false. Your site can\'t send email right now; fix SMTP and try again.');
    }

    if ($action === 'charge_shipping') {
        $claim = GWARR_Claims::find($claim_id);
        if (!$claim || $claim->status !== 'approved') {
            return gwarr_admin_notice('error', 'Shipping can only be charged on an approved claim.');
        }
        $result = GWARR_Claims::set_shipping_fee($claim_id, $fee);
        if (is_wp_error($result)) {
            return gwarr_admin_notice('error', 'Could not add shipping fee: ' . esc_html($result->get_error_message()));
        }
        $ok = class_exists('GWARR_Email') ? (bool) GWARR_Email::send_claim_approved($result) : false;
        gwarr_record_claim_email_status($claim_id, $ok);
        $msg = 'Shipping order #' . (int) $result->shipping_order_id . ' (RM ' . number_format($fee, 2) . ') created for claim #' . $claim_id . '.';
        $msg .= $ok ? ' The approval email with the pay button was sent.' : ' <strong style="color:#d63638;">⚠ But the email could not be sent — check your SMTP setup, then use Resend.</strong>';
        return gwarr_admin_notice($ok ? 'success' : 'error', $msg);
    }

    if ($action === 'reject') {
        if ($note === '') {
            return gwarr_admin_notice('error', 'Please provide a reason for declining.');
        }
        $result = GWARR_Claims::reject($claim_id, $note);
        if (is_wp_error($result)) {
            return gwarr_admin_notice('error', 'Decline failed: ' . esc_html($result->get_error_message()));
        }
        if (class_exists('GWARR_Email')) {
            GWARR_Email::send_claim_rejected($result);
        }
        return gwarr_admin_notice('success', 'Claim #' . $claim_id . ' declined and the customer notified.');
    }

    return '';
}

/**
 * Record whether a claim's approval email was handed off to the mailer, so the
 * claims list can show "sent / FAILED" and we can tell deliverability issues
 * apart from send failures. Stored per-claim as an option (no schema change).
 */
function gwarr_record_claim_email_status($claim_id, $ok) {
    update_option('gwarr_claim_email_' . (int) $claim_id, [
        'at' => current_time('mysql'),
        'ok' => $ok ? 1 : 0,
    ], false);
}

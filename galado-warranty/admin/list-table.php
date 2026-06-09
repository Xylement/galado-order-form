<?php
/**
 * Admin screen — list of warranty registrations with approve/reject actions.
 *
 * Lightweight bespoke renderer (rather than WP_List_Table) so we can show the
 * approval form inline on each pending row without a detour to a sub-page.
 */

if (!defined('ABSPATH')) exit;

/**
 * Renderer for the "Warranties" admin page.
 */
function gwarr_render_registrations_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Forbidden');
    }

    // ---- Handle POST actions (approve / reject) ----
    $action_notice = gwarr_handle_admin_post();

    // ---- Filters ----
    $status      = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
    $marketplace = isset($_GET['marketplace']) ? sanitize_key($_GET['marketplace']) : '';
    $search      = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $page        = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

    $list = GWARR_DB::list([
        'status'      => $status,
        'marketplace' => $marketplace,
        'search'      => $search,
        'page'        => $page,
        'per_page'    => 20,
    ]);
    $counts = GWARR_DB::status_counts();

    ?>
    <div class="wrap gwarr-admin">
        <h1>Warranty Registrations</h1>
        <p style="color:#646970;max-width:780px;">
            Customers from marketplaces (Shopee, Lazada, TikTok, etc.) register their purchase here to extend warranty from 1 month to 6 months.
            Approving generates a unique welcome coupon and pushes them to Klaviyo's Marketplace Buyers list.
        </p>

        <?php if ($action_notice): ?>
            <?php echo $action_notice; // already escaped ?>
        <?php endif; ?>

        <!-- Status filter chips -->
        <ul class="subsubsub">
            <?php
            $current_status = $status;
            $base           = admin_url('admin.php?page=galado-warranty');
            $statuses       = [
                ''         => ['label' => 'All',      'count' => $counts['all']],
                'pending'  => ['label' => 'Pending',  'count' => $counts['pending']],
                'approved' => ['label' => 'Approved', 'count' => $counts['approved']],
                'rejected' => ['label' => 'Rejected', 'count' => $counts['rejected']],
            ];
            $links = [];
            foreach ($statuses as $slug => $info) {
                $url = $slug === '' ? $base : add_query_arg('status', $slug, $base);
                $is_current = $slug === $current_status ? ' class="current"' : '';
                $links[] = '<a href="' . esc_url($url) . '"' . $is_current . '>' . esc_html($info['label']) . ' <span class="count">(' . intval($info['count']) . ')</span></a>';
            }
            echo '<li>' . implode(' | </li><li>', $links) . '</li>';
            ?>
        </ul>

        <!-- Search + marketplace filter form -->
        <form method="get" class="gwarr-filters">
            <input type="hidden" name="page" value="galado-warranty">
            <?php if ($status !== ''): ?>
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
            <?php endif; ?>

            <select name="marketplace">
                <option value="">All marketplaces</option>
                <?php foreach (GWARR_Marketplaces::all() as $slug => $label): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($marketplace, $slug); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search order number, product, customer">
            <button class="button" type="submit">Filter</button>

            <?php if ($status !== '' || $marketplace !== '' || $search !== ''): ?>
                <a class="button" href="<?php echo esc_url($base); ?>">Clear</a>
            <?php endif; ?>
        </form>

        <table class="wp-list-table widefat fixed striped gwarr-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Customer</th>
                    <th>Marketplace</th>
                    <th>Order #</th>
                    <th>Product</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list['rows'])): ?>
                    <tr><td colspan="7"><em>No registrations match your filters.</em></td></tr>
                <?php else: ?>
                    <?php foreach ($list['rows'] as $row): ?>
                        <?php gwarr_render_admin_row($row); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php gwarr_render_pagination($list['total'], 20, $page); ?>
    </div>
    <?php
}

/**
 * One table row + (for pending) an inline approval/rejection form.
 */
function gwarr_render_admin_row($row) {
    $status_class = 'gwarr-badge gwarr-badge-' . esc_attr($row->status);
    $status_text  = ucfirst($row->status);

    if ($row->status === 'approved') {
        $is_expired = $row->warranty_ends && strtotime($row->warranty_ends) < strtotime(current_time('Y-m-d'));
        if ($is_expired) {
            $status_class = 'gwarr-badge gwarr-badge-expired';
            $status_text  = 'Approved (expired)';
        }
    }
    ?>
    <tr>
        <td><span class="<?php echo $status_class; ?>"><?php echo esc_html($status_text); ?></span></td>
        <td>
            <strong><?php echo esc_html($row->display_name ?: '(no name)'); ?></strong><br>
            <small><?php echo esc_html($row->user_email ?: '—'); ?></small>
        </td>
        <td><?php echo esc_html(GWARR_Marketplaces::label($row->marketplace)); ?></td>
        <td><code><?php echo esc_html($row->order_number); ?></code></td>
        <td>
            <?php if (!empty($row->product_text)): ?>
                <?php echo nl2br(esc_html($row->product_text)); ?>
            <?php else: ?>
                <span style="color:#999;">—</span>
            <?php endif; ?>
            <?php if (!empty($row->notes)): ?>
                <br><small><em><?php echo esc_html($row->notes); ?></em></small>
            <?php endif; ?>
        </td>
        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' g:i a', $row->created_at)); ?></td>
        <td>
            <?php gwarr_render_admin_row_actions($row); ?>
        </td>
    </tr>
    <?php
}

function gwarr_render_admin_row_actions($row) {
    if ($row->status === 'pending') {
        $today = current_time('Y-m-d');
        ?>
        <details class="gwarr-row-actions">
            <summary class="button">Approve / Reject</summary>
            <div class="gwarr-action-panel">
                <form method="post" class="gwarr-approve-form">
                    <?php wp_nonce_field('gwarr_admin_action', 'gwarr_admin_nonce'); ?>
                    <input type="hidden" name="gwarr_id" value="<?php echo (int) $row->id; ?>">
                    <label>Purchase date<br>
                        <input type="date" name="purchase_date" value="<?php echo esc_attr($today); ?>" required>
                    </label>
                    <label>Admin note <small>(optional, shown to customer)</small><br>
                        <input type="text" name="admin_note" maxlength="200" placeholder="e.g. verified via sheet row 42">
                    </label>
                    <p>
                        <button type="submit" name="gwarr_action" value="approve" class="button button-primary">Approve + send coupon</button>
                    </p>
                </form>

                <form method="post" class="gwarr-reject-form" onsubmit="return confirm('Reject this registration? The customer will be notified.');">
                    <?php wp_nonce_field('gwarr_admin_action', 'gwarr_admin_nonce'); ?>
                    <input type="hidden" name="gwarr_id" value="<?php echo (int) $row->id; ?>">
                    <label>Reason (shown to customer)<br>
                        <input type="text" name="admin_note" maxlength="200" placeholder="e.g. order number not found in our records" required>
                    </label>
                    <p>
                        <button type="submit" name="gwarr_action" value="reject" class="button button-link-delete">Reject</button>
                    </p>
                </form>
            </div>
        </details>
        <?php
    } elseif ($row->status === 'approved') {
        echo '<small>Purchased ' . esc_html(mysql2date('M j, Y', $row->purchase_date)) . '<br>';
        echo 'Until ' . esc_html(mysql2date('M j, Y', $row->warranty_ends)) . '<br>';
        echo 'Coupon <code>' . esc_html($row->coupon_code) . '</code></small>';
    } elseif ($row->status === 'rejected') {
        if (!empty($row->admin_note)) {
            echo '<small><em>' . esc_html($row->admin_note) . '</em></small>';
        }
    }
}

function gwarr_render_pagination($total, $per_page, $current_page) {
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($total_pages <= 1) return;

    $base = add_query_arg('paged', '%#%');
    echo '<div class="tablenav bottom"><div class="tablenav-pages">';
    echo '<span class="displaying-num">' . esc_html($total) . ' item' . ($total === 1 ? '' : 's') . '</span> ';
    echo paginate_links([
        'base'      => $base,
        'format'    => '',
        'current'   => $current_page,
        'total'     => $total_pages,
        'prev_text' => '‹',
        'next_text' => '›',
    ]);
    echo '</div></div>';
}

/**
 * Handle admin POSTs (approve / reject). Returns an HTML notice or empty string.
 */
function gwarr_handle_admin_post() {
    if (empty($_POST['gwarr_action']) || empty($_POST['gwarr_admin_nonce'])) {
        return '';
    }
    if (!current_user_can('manage_woocommerce')) {
        return gwarr_admin_notice('error', 'Insufficient permissions.');
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_admin_nonce'])), 'gwarr_admin_action')) {
        return gwarr_admin_notice('error', 'Security check failed.');
    }

    $id     = isset($_POST['gwarr_id']) ? (int) $_POST['gwarr_id'] : 0;
    $action = sanitize_key(wp_unslash($_POST['gwarr_action']));
    $note   = isset($_POST['admin_note']) ? sanitize_text_field(wp_unslash($_POST['admin_note'])) : '';

    if (!$id) {
        return gwarr_admin_notice('error', 'Missing registration ID.');
    }

    if ($action === 'approve') {
        $purchase_date = isset($_POST['purchase_date']) ? sanitize_text_field(wp_unslash($_POST['purchase_date'])) : '';
        $result = GWARR_Approval::approve($id, $purchase_date, $note);
        if (is_wp_error($result)) {
            return gwarr_admin_notice('error', 'Approval failed: ' . esc_html($result->get_error_message()));
        }
        return gwarr_admin_notice('success', 'Approved. Coupon <code>' . esc_html($result->coupon_code) . '</code> sent to customer.');
    }

    if ($action === 'reject') {
        if ($note === '') {
            return gwarr_admin_notice('error', 'Please provide a rejection reason.');
        }
        $result = GWARR_Approval::reject($id, $note);
        if (is_wp_error($result)) {
            return gwarr_admin_notice('error', 'Rejection failed: ' . esc_html($result->get_error_message()));
        }
        return gwarr_admin_notice('success', 'Registration rejected and customer notified.');
    }

    return '';
}

function gwarr_admin_notice($type, $message) {
    $class = $type === 'success' ? 'notice-success' : 'notice-error';
    return '<div class="notice ' . $class . '"><p>' . $message . '</p></div>';
}

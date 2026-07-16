<?php
/**
 * Repository + lifecycle for warranty claims.
 *
 * A claim is a customer report against one warranty row (one item). It carries
 * an issue description and a set of uploaded media (WP attachment ids). When an
 * admin approves a claim, the underlying warranty is flipped to 'claimed' (greys
 * out for the customer); rejecting leaves the warranty active so they can re-file.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Claims {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'galado_warranty_claims';
    }

    /**
     * On the pay-for-order page of a warranty shipping order, re-add enabled
     * gateways that hid themselves.
     *
     * Why: several Malaysian gateway plugins (FPX online banking, Touch 'n Go
     * / e-wallets) compute is_available() from the CART total. On the
     * order-pay page the cart is empty (total 0), so a minimum-amount check
     * fails and the gateway vanishes, while cards/PayPal (which read the
     * order) still show. Normal checkout is unaffected because the cart is
     * real there.
     *
     * Scoped strictly to orders this plugin created (created_via =
     * galado-warranty); every other page and order keeps WooCommerce's own
     * availability decisions.
     */
    /** Gateway diagnostics for the admin-only pay-page readout. */
    private static $pay_diag = null;

    public static function pay_page_gateways($available) {
        if (!is_array($available)) {
            return $available;
        }
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return $available;
        }

        global $wp;
        $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if (!$order_id || !function_exists('wc_get_order')) {
            return $available;
        }
        $order = wc_get_order($order_id);
        if (!$order || 'galado-warranty' !== $order->get_created_via()) {
            return $available;
        }
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return $available;
        }

        $before = array_keys($available);
        $added  = [];
        $diag   = [];

        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            $runtime_enabled = isset($gateway->enabled) ? (string) $gateway->enabled : '?';
            // The SAVED setting is the merchant's actual on/off choice. Some
            // gateways flip their runtime ->enabled to 'no' outside checkout
            // (no cart), which is exactly the bug we're correcting for, so a
            // saved 'yes' counts even when runtime says 'no'.
            $saved_enabled = method_exists($gateway, 'get_option')
                ? (string) $gateway->get_option('enabled', $runtime_enabled)
                : $runtime_enabled;

            $was_available = isset($available[$id]);
            if (!$was_available && ('yes' === $runtime_enabled || 'yes' === $saved_enabled)) {
                $available[$id] = $gateway;
                $added[]        = $id;
            }

            $diag[] = [
                'id'      => $id,
                'title'   => isset($gateway->method_title) && $gateway->method_title ? $gateway->method_title : (isset($gateway->title) ? $gateway->title : $id),
                'runtime' => $runtime_enabled,
                'saved'   => $saved_enabled,
                'before'  => $was_available,
                'final'   => isset($available[$id]),
            ];
        }

        if ($added) {
            error_log('[galado-warranty] pay page for order ' . $order_id
                . ': re-added gateways hidden by cart-based availability: ' . implode(', ', $added));
        }

        // Admin-only readout so a missing gateway can be diagnosed on the page
        // itself (registered? enabled where?) instead of guessing.
        if (function_exists('current_user_can') && current_user_can('manage_woocommerce')) {
            $first = null === self::$pay_diag;
            self::$pay_diag = ['order_id' => $order_id, 'before' => $before, 'rows' => $diag];
            if ($first) {
                add_action('wp_footer', [__CLASS__, 'render_pay_page_diag'], 999);
            }
        }

        return $available;
    }

    /** Fixed-position gateway readout on the pay page, admins only. */
    public static function render_pay_page_diag() {
        if (null === self::$pay_diag || !current_user_can('manage_woocommerce')) {
            return;
        }
        $d = self::$pay_diag;
        echo '<div style="position:fixed;left:8px;bottom:8px;z-index:999999;max-width:480px;max-height:45vh;overflow:auto;background:#111;color:#eee;font:11px/1.5 Menlo,monospace;padding:10px 12px;border-radius:8px;opacity:.95;">';
        echo '<div style="font-weight:700;margin-bottom:6px;">GALADO warranty · gateway diagnostic (admins only) · order #' . (int) $d['order_id'] . '</div>';
        echo '<div style="margin-bottom:6px;color:#9f9;">available before fix: ' . esc_html(implode(', ', $d['before']) ?: '(none)') . '</div>';
        foreach ($d['rows'] as $r) {
            $color = $r['final'] ? '#9f9' : '#f99';
            echo '<div style="color:' . $color . ';">'
                . esc_html($r['id']) . ' · ' . esc_html($r['title'])
                . ' · runtime=' . esc_html($r['runtime'])
                . ' · saved=' . esc_html($r['saved'])
                . ' · shown=' . ($r['final'] ? 'YES' : 'no')
                . '</div>';
        }
        echo '<div style="margin-top:6px;color:#999;">If Online Banking / FPX is not listed at all here, its plugin never registers the gateway on this page.</div>';
        echo '</div>';
    }

    /**
     * Create a claim. media_ids is an array of WP attachment ids.
     * @return int|WP_Error new claim id
     */
    public static function insert($args) {
        global $wpdb;

        $row = wp_parse_args($args, [
            'warranty_id'        => 0,
            'user_id'            => 0,
            'item_label'         => '',
            'issue_description'  => '',
            'media_ids'          => [],
            'delivery_name'      => '',
            'delivery_phone'     => '',
            'delivery_address_1' => '',
            'delivery_address_2' => '',
            'delivery_city'      => '',
            'delivery_state'     => '',
            'delivery_postcode'  => '',
        ]);

        if ((int) $row['warranty_id'] <= 0 || (int) $row['user_id'] <= 0) {
            return new WP_Error('gwarr_claim_bad', 'Missing warranty or user.');
        }
        if (trim((string) $row['issue_description']) === '') {
            return new WP_Error('gwarr_claim_no_issue', 'Please describe the issue.');
        }

        $media = array_values(array_filter(array_map('absint', (array) $row['media_ids'])));

        $ok = $wpdb->insert(
            self::table(),
            [
                'warranty_id'        => (int) $row['warranty_id'],
                'user_id'            => (int) $row['user_id'],
                'item_label'         => (string) $row['item_label'] ?: null,
                'delivery_name'      => (string) $row['delivery_name'] ?: null,
                'delivery_phone'     => (string) $row['delivery_phone'] ?: null,
                'delivery_address_1' => (string) $row['delivery_address_1'] ?: null,
                'delivery_address_2' => (string) $row['delivery_address_2'] ?: null,
                'delivery_city'      => (string) $row['delivery_city'] ?: null,
                'delivery_state'     => (string) $row['delivery_state'] ?: null,
                'delivery_postcode'  => (string) $row['delivery_postcode'] ?: null,
                'issue_description'  => (string) $row['issue_description'],
                'media_ids'          => wp_json_encode($media),
                'status'             => 'submitted',
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($ok === false) {
            return new WP_Error('gwarr_claim_insert_failed', $wpdb->last_error ?: 'Database insert failed.');
        }
        return (int) $wpdb->insert_id;
    }

    public static function find($id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id)
        );
    }

    /**
     * The most recent claim for a warranty (any status), or null.
     */
    public static function latest_for_warranty($warranty_id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE warranty_id = %d ORDER BY id DESC LIMIT 1',
                (int) $warranty_id
            )
        );
    }

    /**
     * Whether a warranty already has a claim awaiting review (blocks re-filing).
     */
    public static function has_open_claim($warranty_id) {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . " WHERE warranty_id = %d AND status = 'submitted'",
                (int) $warranty_id
            )
        ) > 0;
    }

    /**
     * Open-claim map keyed by warranty_id for a user, so the My Warranties
     * render can decorate cards without an N+1 query.
     * @return array<int,object> warranty_id => latest claim row
     */
    public static function map_for_user($user_id) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.* FROM ' . self::table() . ' c
                 INNER JOIN (SELECT warranty_id, MAX(id) AS max_id FROM ' . self::table() . '
                             WHERE user_id = %d GROUP BY warranty_id) latest
                 ON c.id = latest.max_id',
                (int) $user_id
            )
        );
        $map = [];
        foreach ((array) $rows as $r) {
            $map[(int) $r->warranty_id] = $r;
        }
        return $map;
    }

    /**
     * Paginated admin list with the customer + warranty joined in.
     * @return array{rows:array,total:int}
     */
    public static function list($args = []) {
        global $wpdb;
        $args = wp_parse_args($args, ['status' => '', 'per_page' => 20, 'page' => 1]);

        $where  = ['1=1'];
        $params = [];
        if ($args['status'] !== '') {
            $where[]  = 'c.status = %s';
            $params[] = $args['status'];
        }

        $per_page = max(1, min(200, (int) $args['per_page']));
        $offset   = (max(1, (int) $args['page']) - 1) * $per_page;

        $w_table = $wpdb->prefix . GWARR_TABLE;
        $base = 'FROM ' . self::table() . ' c
                 LEFT JOIN ' . $w_table . ' w ON w.id = c.warranty_id
                 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = c.user_id
                 WHERE ' . implode(' AND ', $where);

        $rows_sql = "SELECT c.*, u.user_email, u.display_name,
                     w.product_text, w.marketplace, w.source, w.order_number, w.wc_order_id, w.warranty_ends, w.status AS warranty_status
                     {$base} ORDER BY c.id DESC LIMIT %d OFFSET %d";

        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) ' . $base, ...$params));
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, ...array_merge($params, [$per_page, $offset])));
        } else {
            $total = (int) $wpdb->get_var('SELECT COUNT(*) ' . $base);
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, $per_page, $offset));
        }
        return ['rows' => $rows ?: [], 'total' => $total];
    }

    public static function status_counts() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A);
        $out  = ['submitted' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach ((array) $rows as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all']      += (int) $r['n'];
        }
        return $out;
    }

    /**
     * Approve a claim → flips the underlying warranty to 'claimed'. When a
     * replacement-shipping fee is supplied (> 0), a pending WooCommerce order
     * for that amount is created under the customer's account so they can pay
     * online (WooCommerce verifies the payment); the order id is stored on the
     * claim and the approval email links to it.
     */
    public static function approve($id, $admin_note = '', $shipping_fee = 0) {
        global $wpdb;

        $claim = self::find($id);
        if (!$claim) {
            return new WP_Error('gwarr_claim_not_found', 'Claim not found.');
        }
        $updated = self::set_status($id, 'approved', $admin_note);
        if (is_wp_error($updated)) {
            return $updated;
        }
        if (class_exists('GWARR_DB')) {
            GWARR_DB::mark_claimed((int) $claim->warranty_id);
        }

        $fee = round((float) $shipping_fee, 2);
        if ($fee > 0) {
            $order_id = self::create_shipping_order($claim, $fee);
            $wpdb->update(
                self::table(),
                ['shipping_fee' => $fee, 'shipping_order_id' => $order_id ?: null],
                ['id' => (int) $id],
                ['%f', '%d'],
                ['%d']
            );
            $updated = self::find($id);
        }

        return $updated;
    }

    /**
     * Attach (or re-use) a replacement-shipping fee + WooCommerce pay order to
     * an already-approved claim — for when the fee was missed at approval, or
     * the claim was approved before the pay flow existed. Creates the order
     * only if one isn't already linked.
     *
     * @return object|WP_Error the refreshed claim row
     */
    public static function set_shipping_fee($id, $fee) {
        global $wpdb;
        $fee   = round((float) $fee, 2);
        $claim = self::find($id);
        if (!$claim) {
            return new WP_Error('gwarr_claim_not_found', 'Claim not found.');
        }
        if ($fee <= 0) {
            return new WP_Error('gwarr_bad_fee', 'Please enter a shipping fee greater than 0.');
        }

        $order_id = !empty($claim->shipping_order_id)
            ? (int) $claim->shipping_order_id
            : self::create_shipping_order($claim, $fee);

        if (!$order_id) {
            $why = self::last_order_error();
            return new WP_Error('gwarr_order_failed',
                'Could not create the WooCommerce shipping order' . ($why ? ': ' . $why : '. Check the error log.'));
        }

        $wpdb->update(
            self::table(),
            ['shipping_fee' => $fee, 'shipping_order_id' => $order_id],
            ['id' => (int) $id],
            ['%f', '%d'],
            ['%d']
        );
        return self::find($id);
    }

    /**
     * Create a pending WooCommerce order for a warranty-claim shipping fee,
     * assigned to the customer. Returns the order id, or 0 on failure (the
     * approval still succeeds — the email just falls back to "we'll send a
     * payment link"). Tax is disabled so the customer pays exactly the fee.
     */
    /** Last shipping-order failure reason, surfaced by set_shipping_fee(). */
    private static $last_order_error = '';

    public static function last_order_error() {
        return self::$last_order_error;
    }

    /**
     * The house product behind warranty shipping-fee line items. Email
     * templates (and other plugins) call methods on $item->get_product(),
     * which is false for a product-less line item and fatals the render —
     * that is exactly how the paid notification for order 404824 vanished.
     * Private + hidden keeps it out of the storefront, feeds and search;
     * virtual keeps it out of shipping. Cached in an option, with an SKU
     * lookup as self-repair. Returns 0 on failure (line item then behaves
     * as before, order creation is never blocked).
     */
    private static function shipping_product_id() {
        $pid = (int) get_option('gwarr_shipping_product_id', 0);
        if ($pid && function_exists('wc_get_product')) {
            $p = wc_get_product($pid);
            if ($p && 'trash' !== $p->get_status()) {
                return $pid;
            }
        }
        if (!class_exists('WC_Product_Simple')) {
            return 0;
        }
        try {
            $pid = (int) wc_get_product_id_by_sku('gwarr-shipping');
            if (!$pid) {
                $product = new WC_Product_Simple();
                $product->set_name('Warranty Replacement Shipping');
                $product->set_sku('gwarr-shipping');
                $product->set_status('private');
                $product->set_catalog_visibility('hidden');
                $product->set_virtual(true);
                $product->set_regular_price('0');
                $product->set_sold_individually(true);
                $product->set_reviews_allowed(false);
                $pid = (int) $product->save();
            }
            if ($pid) {
                update_option('gwarr_shipping_product_id', $pid, false);
            }
            return $pid;
        } catch (Throwable $e) {
            error_log('[galado-warranty] shipping product create failed: ' . $e->getMessage());
            return 0;
        }
    }

    private static function create_shipping_order($claim, $fee) {
        self::$last_order_error = '';
        if (!function_exists('wc_create_order')) {
            self::$last_order_error = 'WooCommerce is not active.';
            return 0;
        }
        if (!class_exists('WC_Order_Item_Product')) {
            self::$last_order_error = 'WC_Order_Item_Product class unavailable.';
            return 0;
        }
        try {
            // Order-save hooks (and WC internals) often reach for WC()->session,
            // ->cart or ->customer, which are null on a wp-admin request →
            // "Call to a member function get() on null". Initialise them first.
            if (function_exists('WC')) {
                if (null === WC()->session && class_exists('WC_Session_Handler')) {
                    WC()->session = new WC_Session_Handler();
                    WC()->session->init();
                }
                if (null === WC()->customer) {
                    WC()->customer = new WC_Customer((int) $claim->user_id, true);
                }
                if (null === WC()->cart && class_exists('WC_Cart')) {
                    WC()->cart = new WC_Cart();
                }
            }

            $user  = get_userdata((int) $claim->user_id);
            $label = !empty($claim->item_label) ? (string) $claim->item_label : 'warranty replacement';

            $order = wc_create_order(['customer_id' => (int) $claim->user_id]);
            if (is_wp_error($order)) {
                self::$last_order_error = $order->get_error_message();
                error_log('[galado-warranty] wc_create_order failed for claim ' . (int) $claim->id . ': ' . $order->get_error_message());
                return 0;
            }

            // Add the charge as a real product LINE ITEM, not a bare
            // WC_Order_Item_Fee. The Razer / Molpay seamless gateway builds its
            // payment-form description by imploding product names gathered from
            // $order->get_items() (line_item type only). A fee-only order has no
            // line items, so that variable stays undefined and generate_form()
            // fatals with "implode(): Argument #1 must be of type array, NULL
            // given" on the pay page. A product line item keeps get_items()
            // non-empty so the gateway form renders. capture_order() skips
            // created_via=galado-warranty orders so this never becomes a bogus
            // warranty row.
            $line = new WC_Order_Item_Product();
            $line->set_product_id(self::shipping_product_id());
            $line->set_name('Replacement shipping: ' . $label);
            $line->set_quantity(1);
            $line->set_subtotal((string) $fee);
            $line->set_total((string) $fee);
            $line->set_subtotal_tax(0);
            $line->set_total_tax(0);
            $order->add_item($line);

            // Populate billing details so Malaysian payment gateways (FPX online
            // banking, Touch 'n Go / e-wallets) appear on the order-pay page.
            // Those gateways hide themselves when the order has no billing
            // country / name / phone. Prefer the customer's saved WooCommerce
            // billing; fall back to the WP account + Malaysia.
            $cust = class_exists('WC_Customer') ? new WC_Customer((int) $claim->user_id) : null;

            $b_email = ($user && $user->user_email) ? $user->user_email : ($cust ? $cust->get_billing_email() : '');
            if ($b_email) {
                $order->set_billing_email($b_email);
            }
            $b_first = $cust && $cust->get_billing_first_name() ? $cust->get_billing_first_name() : ($user->first_name ?? '');
            $b_last  = $cust && $cust->get_billing_last_name()  ? $cust->get_billing_last_name()  : ($user->last_name ?? '');
            if ($b_first) $order->set_billing_first_name($b_first);
            if ($b_last)  $order->set_billing_last_name($b_last);
            if ($cust && $cust->get_billing_phone())     $order->set_billing_phone($cust->get_billing_phone());
            if ($cust && $cust->get_billing_address_1())  $order->set_billing_address_1($cust->get_billing_address_1());
            if ($cust && $cust->get_billing_city())       $order->set_billing_city($cust->get_billing_city());
            if ($cust && $cust->get_billing_state())      $order->set_billing_state($cust->get_billing_state());
            if ($cust && $cust->get_billing_postcode())   $order->set_billing_postcode($cust->get_billing_postcode());
            // Country is the field gateways check most: default to MY (GALADO is
            // Malaysia-first) when the customer has none saved.
            $order->set_billing_country(($cust && $cust->get_billing_country()) ? $cust->get_billing_country() : 'MY');

            // The claim's delivery snapshot (collected at submission) wins over
            // the profile: it's what the customer just confirmed for THIS
            // replacement. Also mirrored to the shipping address so the order
            // is fulfilment-ready everywhere it's viewed.
            if (!empty($claim->delivery_phone) || !empty($claim->delivery_address_1)) {
                $d_name  = trim((string) ($claim->delivery_name ?? ''));
                $d_first = $d_name !== '' ? preg_split('/\s+/', $d_name)[0] : '';
                $d_last  = $d_first !== '' ? trim(substr($d_name, strlen($d_first))) : '';

                if ($d_first) { $order->set_billing_first_name($d_first); $order->set_shipping_first_name($d_first); }
                if ($d_last)  { $order->set_billing_last_name($d_last);   $order->set_shipping_last_name($d_last); }
                if (!empty($claim->delivery_phone)) $order->set_billing_phone((string) $claim->delivery_phone);
                if (!empty($claim->delivery_address_1)) {
                    $order->set_billing_address_1((string) $claim->delivery_address_1);
                    $order->set_shipping_address_1((string) $claim->delivery_address_1);
                    $order->set_billing_address_2((string) ($claim->delivery_address_2 ?? ''));
                    $order->set_shipping_address_2((string) ($claim->delivery_address_2 ?? ''));
                    $order->set_billing_city((string) ($claim->delivery_city ?? ''));
                    $order->set_shipping_city((string) ($claim->delivery_city ?? ''));
                    $order->set_billing_state((string) ($claim->delivery_state ?? ''));
                    $order->set_shipping_state((string) ($claim->delivery_state ?? ''));
                    $order->set_billing_postcode((string) ($claim->delivery_postcode ?? ''));
                    $order->set_shipping_postcode((string) ($claim->delivery_postcode ?? ''));
                    $order->set_shipping_country('MY');
                }
            }

            $order->set_created_via('galado-warranty');
            $order->add_order_note('Auto-created for warranty claim #' . (int) $claim->id . ' (replacement shipping fee).');

            // Set the total directly instead of calculate_totals(): the fee is a
            // fixed, tax-free amount, and calculate_totals() runs WooCommerce's
            // tax/session/customer machinery which is null on an admin request
            // ("Call to a member function get() on null").
            $order->set_cart_tax(0);
            $order->set_total((float) $fee);
            $order->update_status('pending', 'Awaiting warranty-replacement shipping payment.');
            $order->save();

            $oid = (int) $order->get_id();
            if ($oid <= 0) {
                self::$last_order_error = 'Order saved but no ID was returned.';
                return 0;
            }
            return $oid;
        } catch (\Throwable $e) {
            // Pinpoint the exact failing call so we can see whether it's WC core
            // or a third-party hook (and which one), not just the message.
            self::$last_order_error = $e->getMessage()
                . ' [' . get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            error_log('[galado-warranty] shipping order for claim ' . (int) $claim->id
                . ' failed (v' . (defined('GWARR_VERSION') ? GWARR_VERSION : '?') . '): '
                . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
                . "\n" . $e->getTraceAsString());
            return 0;
        }
    }

    /** Ensure the WC frontend singletons exist (admin/cron requests lack them). */
    private static function boot_wc_context($user_id) {
        if (!function_exists('WC')) return;
        if (null === WC()->session && class_exists('WC_Session_Handler')) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }
        if (null === WC()->customer && class_exists('WC_Customer')) {
            WC()->customer = new WC_Customer((int) $user_id, true);
        }
        if (null === WC()->cart && class_exists('WC_Cart')) {
            WC()->cart = new WC_Cart();
        }
    }

    /**
     * Backfill Malaysian billing on an EXISTING shipping order so FPX + Touch
     * 'n Go appear on the pay page. Fills only empty fields (never overwrites a
     * real value); defaults billing country to MY. Returns true if it saved a
     * change.
     */
    public static function ensure_order_billing($order, $user_id) {
        if (!$order || !is_object($order)) return false;
        try {
            self::boot_wc_context($user_id);
            $cust = (class_exists('WC_Customer') && $user_id) ? new WC_Customer((int) $user_id) : null;
            $user = $user_id ? get_userdata((int) $user_id) : null;
            $changed = false;

            if (!$order->get_billing_country()) {
                $order->set_billing_country(($cust && $cust->get_billing_country()) ? $cust->get_billing_country() : 'MY');
                $changed = true;
            }
            if (!$order->get_billing_first_name()) {
                $f = ($cust && $cust->get_billing_first_name()) ? $cust->get_billing_first_name() : ($user->first_name ?? '');
                if ($f) { $order->set_billing_first_name($f); $changed = true; }
            }
            if (!$order->get_billing_last_name()) {
                $l = ($cust && $cust->get_billing_last_name()) ? $cust->get_billing_last_name() : ($user->last_name ?? '');
                if ($l) { $order->set_billing_last_name($l); $changed = true; }
            }
            if (!$order->get_billing_phone() && $cust && $cust->get_billing_phone()) {
                $order->set_billing_phone($cust->get_billing_phone()); $changed = true;
            }
            if (!$order->get_billing_email()) {
                $e = ($user && $user->user_email) ? $user->user_email : ($cust ? $cust->get_billing_email() : '');
                if ($e) { $order->set_billing_email($e); $changed = true; }
            }
            if ($changed) $order->save();
            return $changed;
        } catch (\Throwable $e) {
            error_log('[galado-warranty] ensure_order_billing failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Repair an EXISTING shipping order that was created as a bare fee (no
     * product line item) so the Razer / Molpay pay page stops fataling on
     * implode() of an undefined product-name array. Converts any fee line into
     * an equivalent product line item, preserving the order total. No-op if the
     * order already has a line item. Returns true if it changed anything.
     */
    public static function ensure_order_line_item($order) {
        if (!$order || !is_object($order) || !class_exists('WC_Order_Item_Product')) {
            return false;
        }
        if (count($order->get_items('line_item')) > 0) {
            return false; // already fulfilment-shaped
        }
        try {
            $amount = 0.0;
            $label  = '';
            foreach ($order->get_items('fee') as $fee_id => $fee) {
                $amount += (float) $fee->get_total();
                if ($label === '') {
                    $label = (string) $fee->get_name();
                }
                $order->remove_item($fee_id); // the line item replaces it (no double count)
            }
            if ($amount <= 0) {
                $amount = (float) $order->get_total();
            }
            if ($label === '') {
                $label = 'Replacement shipping';
            }
            $line = new WC_Order_Item_Product();
            $line->set_product_id(self::shipping_product_id());
            $line->set_name($label);
            $line->set_quantity(1);
            $line->set_subtotal((string) $amount);
            $line->set_total((string) $amount);
            $line->set_subtotal_tax(0);
            $line->set_total_tax(0);
            $order->add_item($line);
            $order->set_cart_tax(0);
            $order->set_total((float) $amount);
            $order->save();
            return true;
        } catch (\Throwable $e) {
            error_log('[galado-warranty] ensure_order_line_item failed for order ' . (int) $order->get_id() . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Approved claims that carry a shipping pay-order (for bulk maintenance). */
    public static function with_shipping_orders() {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . self::table() . " WHERE status = 'approved' AND shipping_order_id IS NOT NULL AND shipping_fee > 0 ORDER BY id DESC"
        );
    }

    /** The claim a shipping pay-order belongs to, or null. */
    public static function find_by_shipping_order($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE shipping_order_id = %d ORDER BY id DESC LIMIT 1',
            (int) $order_id
        ));
    }

    /**
     * Inject the warranty-claim context into WooCommerce's OWN order emails
     * (admin New Order when the fee is paid, plus the customer's receipt):
     * claim number, item, and the ORIGINAL marketplace order number. The
     * delivery address and phone already appear natively in these emails
     * because the claim's delivery snapshot is written onto the order's
     * billing + shipping. Fired on woocommerce_email_order_meta.
     */
    public static function email_order_meta($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if (!$order || !is_object($order) || !method_exists($order, 'get_created_via')) {
            return;
        }
        if ('galado-warranty' !== $order->get_created_via()) {
            return;
        }
        $claim = self::find_by_shipping_order((int) $order->get_id());
        if (!$claim) {
            return;
        }

        $warranty = class_exists('GWARR_DB') ? GWARR_DB::find((int) $claim->warranty_id) : null;
        $origin   = '';
        if ($warranty) {
            $is_website = isset($warranty->source) && 'website' === $warranty->source;
            $order_no   = ($is_website && !empty($warranty->wc_order_id)) ? '#' . $warranty->wc_order_id : $warranty->order_number;
            $origin     = GWARR_Marketplaces::label($warranty->marketplace) . ' order ' . $order_no;
        }
        $item = !empty($claim->item_label) ? (string) $claim->item_label : (string) ($warranty->product_text ?? '');
        // Phone shown here only if the order itself is missing one (legacy claims).
        $phone = (empty($order->get_billing_phone()) && !empty($claim->delivery_phone)) ? (string) $claim->delivery_phone : '';

        if ($plain_text) {
            echo "\n==== GALADO WARRANTY CLAIM ====\n";
            echo 'Claim: #' . (int) $claim->id . "\n";
            if ($item !== '')   echo 'Item: ' . wp_strip_all_tags($item) . "\n";
            if ($origin !== '') echo 'Original order: ' . wp_strip_all_tags($origin) . "\n";
            if ($phone !== '')  echo 'Phone: ' . $phone . "\n";
            echo "This order is the replacement-shipping fee. Deliver to the shipping address in this email.\n\n";
            return;
        }

        echo '<div style="margin:0 0 24px;padding:14px 18px;background:#f5f5f3;border-radius:8px;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#111111;">';
        echo '<strong style="display:block;margin-bottom:4px;">GALADO warranty claim #' . (int) $claim->id . '</strong>';
        if ($item !== '') {
            echo 'Item: <strong>' . esc_html(wp_strip_all_tags($item)) . '</strong><br>';
        }
        if ($origin !== '') {
            echo 'Original order: <strong>' . esc_html($origin) . '</strong><br>';
        }
        if ($phone !== '') {
            echo 'Phone: <strong>' . esc_html($phone) . '</strong><br>';
        }
        echo '<span style="color:#4a4a4a;">This order is the replacement-shipping fee. Deliver to the shipping address in this email.</span>';
        echo '</div>';
    }

    /**
     * Explicitly send the admin New Order email when a warranty shipping
     * order is PAID. WooCommerce is supposed to do this on the status
     * transition, but fee-only orders go pending -> completed directly and
     * that path demonstrably skipped the email (order 404824, 16 Jul). This
     * is idempotent per order and defers to Woo when Woo already sent it.
     */
    public static function send_paid_notification($order_id) {
        try {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
            if (!$order || 'galado-warranty' !== $order->get_created_via()) {
                return;
            }
            if ($order->get_meta('_gwarr_paid_email_sent')) {
                return; // already handled (by us)
            }
            if (!$order->is_paid()) {
                return;
            }

            if ($order->get_meta('_new_order_email_sent')) {
                // Woo's own trigger got there first this time; just record it.
                $order->update_meta_data('_gwarr_paid_email_sent', current_time('mysql'));
                $order->save();
                return;
            }

            // Self-heal pre-1.9.4 fee orders: a line item without a product
            // makes every Woo email template fatal on
            // $item->get_product()->get_image_id() (proven on this order's
            // note trail). Point it at the house shipping product first.
            foreach ($order->get_items('line_item') as $item) {
                if (!$item->get_product_id()) {
                    $pid = self::shipping_product_id();
                    if ($pid) {
                        $item->set_product_id($pid);
                        $item->save();
                    }
                }
            }

            $mailer = function_exists('WC') ? WC()->mailer() : null;
            $emails = $mailer ? $mailer->get_emails() : [];
            if (empty($emails['WC_Email_New_Order'])) {
                $order->add_order_note('Warranty paid-notification: New Order email class unavailable.');
                return;
            }
            try {
                $emails['WC_Email_New_Order']->trigger($order_id, $order);
            } catch (Throwable $e) {
                // Surface the real failure ON THE ORDER (php error_log is not
                // reachable on this host) with the exact file:line, so the
                // culprit template/plugin names itself.
                $order->add_order_note('Warranty paid-notification FAILED in email render: '
                    . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
                return;
            }

            $order->update_meta_data('_gwarr_paid_email_sent', current_time('mysql'));
            $order->save();
            $order->add_order_note('Warranty shipping fee paid: New Order email sent to the store inbox and ' . GWARR_Email::claim_notify_email() . '.');
        } catch (Throwable $e) {
            error_log('[galado-warranty] paid notification for order ' . $order_id . ' failed: ' . $e->getMessage());
            if (!empty($order)) {
                $order->add_order_note('Warranty paid-notification FAILED: ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
    }

    /**
     * One-time sweep after upgrade: send the paid notification for recently
     * paid shipping orders that never got one (covers order 404824 and any
     * other silent misses). Idempotent via the same per-order meta.
     */
    public static function backfill_paid_notifications($days = 14, $cap = 30) {
        $done = 0;
        foreach ((array) self::with_shipping_orders() as $claim) {
            if ($done >= $cap || empty($claim->shipping_order_id)) {
                continue;
            }
            $order = wc_get_order((int) $claim->shipping_order_id);
            if (!$order || !$order->is_paid() || $order->get_meta('_gwarr_paid_email_sent')) {
                continue;
            }
            $paid = $order->get_date_paid();
            if (!$paid || $paid->getTimestamp() < time() - $days * DAY_IN_SECONDS) {
                continue;
            }
            self::send_paid_notification($order->get_id());
            $done++;
        }
        return $done;
    }

    /**
     * Route the admin New Order email for warranty shipping-fee orders to the
     * warranty inbox as well, so the paid notification lands where claims are
     * handled (in addition to WooCommerce's configured recipient).
     */
    public static function new_order_recipient($recipient, $order = null, $email = null) {
        if (!$order || !is_object($order) || !method_exists($order, 'get_created_via')) {
            return $recipient;
        }
        if ('galado-warranty' !== $order->get_created_via()) {
            return $recipient;
        }
        $extra = class_exists('GWARR_Email') ? GWARR_Email::claim_notify_email() : '';
        if ($extra && false === stripos((string) $recipient, $extra)) {
            $recipient = trim((string) $recipient) !== '' ? $recipient . ',' . $extra : $extra;
        }
        return $recipient;
    }

    public static function reject($id, $admin_note = '') {
        return self::set_status($id, 'rejected', $admin_note);
    }

    private static function set_status($id, $status, $admin_note) {
        global $wpdb;
        $ok = $wpdb->update(
            self::table(),
            [
                'status'      => $status,
                'admin_note'  => (string) $admin_note,
                'resolved_by' => get_current_user_id() ?: null,
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => (int) $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('gwarr_claim_update_failed', $wpdb->last_error ?: 'Database update failed.');
        }
        return self::find($id);
    }

    /**
     * Decode media_ids JSON to an array of attachment ids.
     */
    public static function media_ids($claim) {
        if (!$claim || empty($claim->media_ids)) {
            return [];
        }
        $ids = json_decode($claim->media_ids, true);
        return is_array($ids) ? array_values(array_filter(array_map('absint', $ids))) : [];
    }
}

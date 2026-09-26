<?php
/**
 * WP-CLI, for any host with shell access. (The live store has no SSH: use the admin button under
 * WooCommerce > Settings > Products > Gift cards instead.)
 *
 *   wp galado-gift-cards create-product
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_CLI {

    /**
     * Create the gift card product as Private, or print the existing one's id.
     *
     * @subcommand create-product
     */
    public function create_product() {
        $existing = Galado_GC_Product::find_product_id();
        $id = Galado_GC_Product::create_product();
        WP_CLI::success(($existing ? 'Already exists: product ' : 'Created product ') . $id . ' (status ' . get_post_status($id) . ').');
    }
}

<?php
/**
 * Runs only when an admin explicitly deletes the plugin (spec 8: data removed
 * only on opt-in uninstall). Deactivation alone keeps all bundle posts + meta.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$posts = get_posts([
    'post_type'      => 'galado_bundle',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
]);
foreach ($posts as $pid) {
    wp_delete_post($pid, true); // meta cascades
}
delete_option('galado_bundles_storefront_enabled');

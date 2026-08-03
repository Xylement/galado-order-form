<?php
/**
 * Uninstall: the owner's explicit opt-in to remove all data.
 * Drops the abandoned-carts table (guest PII) and every plugin option.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'galado_abandoned_carts');

delete_option('galado_recovery_settings');
delete_option('galado_recovery_last_error');
delete_option('galado_recovery_last_push');

wp_clear_scheduled_hook('galado_recovery_purge');

<?php
/**
 * AJAX login + register endpoints used by the warranty page's auth modal.
 *
 * These bypass the WC /my-account/ pages on purpose — the warranty
 * landing flow needs in-place authentication so we don't lose the
 * customer to a redirect.
 *
 * Security notes:
 *   - Both endpoints check a nonce surfaced via wp_localize_script
 *   - Login errors are intentionally vague to avoid username enumeration
 *   - Registration uses WooCommerce's wc_create_new_customer so customer
 *     meta + welcome email behaviour matches a normal WC signup
 *   - Light rate limit via a per-IP transient (5 attempts / 10 min)
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_nopriv_gwarr_login',    'gwarr_ajax_login');
add_action('wp_ajax_gwarr_login',           'gwarr_ajax_login'); // already-logged-in just succeeds
add_action('wp_ajax_nopriv_gwarr_register', 'gwarr_ajax_register');
add_action('wp_ajax_gwarr_register',        'gwarr_ajax_register');

function gwarr_ajax_login() {
    if (!gwarr_ajax_verify_nonce()) {
        wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.']);
    }
    if (is_user_logged_in()) {
        wp_send_json_success(['message' => 'Already logged in.']);
    }
    if (gwarr_ajax_rate_limited()) {
        wp_send_json_error(['message' => 'Too many attempts. Please wait a few minutes and try again.']);
    }

    $username = isset($_POST['username']) ? sanitize_text_field(wp_unslash($_POST['username'])) : '';
    $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
    $remember = !empty($_POST['remember']);

    if ($username === '' || $password === '') {
        wp_send_json_error(['message' => 'Please enter your email and password.']);
    }

    $user = wp_signon([
        'user_login'    => $username,
        'user_password' => $password,
        'remember'      => $remember,
    ], is_ssl());

    if (is_wp_error($user)) {
        gwarr_ajax_record_attempt();
        // Vague on purpose — don't leak whether the user exists.
        wp_send_json_error(['message' => 'Email or password was incorrect.']);
    }

    wp_set_current_user($user->ID);
    wp_send_json_success(['message' => 'Logged in. Reloading…']);
}

function gwarr_ajax_register() {
    if (!gwarr_ajax_verify_nonce()) {
        wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.']);
    }
    if (is_user_logged_in()) {
        wp_send_json_success(['message' => 'Already logged in.']);
    }
    if (gwarr_ajax_rate_limited()) {
        wp_send_json_error(['message' => 'Too many attempts. Please wait a few minutes and try again.']);
    }

    $email      = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $password   = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
    $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
    $last_name  = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';

    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Please enter a valid email address.']);
    }
    if (strlen($password) < 8) {
        wp_send_json_error(['message' => 'Password must be at least 8 characters.']);
    }
    if (email_exists($email)) {
        wp_send_json_error(['message' => 'An account with this email already exists. Try logging in instead.']);
    }

    if (!function_exists('wc_create_new_customer')) {
        wp_send_json_error(['message' => 'WooCommerce is unavailable. Please try again later.']);
    }

    $user_id = wc_create_new_customer($email, '', $password, [
        'first_name' => $first_name,
        'last_name'  => $last_name,
    ]);

    if (is_wp_error($user_id)) {
        gwarr_ajax_record_attempt();
        wp_send_json_error(['message' => $user_id->get_error_message()]);
    }

    // Auto-log in the new customer.
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true, is_ssl());

    wp_send_json_success(['message' => 'Account created. Reloading…']);
}

/**
 * Nonce verification — checks against the action surfaced by wp_localize_script.
 */
function gwarr_ajax_verify_nonce() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    return $nonce !== '' && wp_verify_nonce($nonce, 'gwarr_auth');
}

/**
 * Per-IP rate limit so a misbehaving client (or an attacker) can't grind
 * through credentials freely from the modal.
 */
function gwarr_ajax_rate_limited() {
    $ip      = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
    $key     = 'gwarr_auth_attempts_' . md5($ip);
    $tries   = (int) get_transient($key);
    return $tries >= 5;
}

function gwarr_ajax_record_attempt() {
    $ip    = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
    $key   = 'gwarr_auth_attempts_' . md5($ip);
    $tries = (int) get_transient($key);
    set_transient($key, $tries + 1, 10 * MINUTE_IN_SECONDS);
}

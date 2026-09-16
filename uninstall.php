<?php
/**
 * Uninstall handler.
 *
 * @package Ifthenpay\NinjaForms
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('iftp_nf_backoffice_key');
delete_option('iftp_nf_gateway_key');
delete_option('iftp_nf_gateway_keys');
delete_option('iftp_nf_methods');
delete_option('iftp_nf_default_method');
delete_option('iftp_nf_description');
delete_option('iftp_nf_expiry_days');
delete_option('iftp_nf_confirmation_paid_type');
delete_option('iftp_nf_confirmation_paid_page_id');
delete_option('iftp_nf_confirmation_paid_url');
delete_option('iftp_nf_confirmation_show_entry_data');
delete_option('iftp_nf_confirmation_paid_message');
delete_option('iftp_nf_confirmation_pending_message');
delete_option('iftp_nf_confirmation_failed_message');
delete_option('iftp_nf_confirmation_cancelled_message');
delete_option('iftp_nf_payments_index_version');
delete_option('iftp_nf_adhoc_payments_index_version');
delete_option('iftp_nf_test_ref_seq');

$payment_options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('iftp_nf_payment_') . '%'
    )
);

foreach ($payment_options as $option_name) {
    delete_option($option_name);
}

$transient_options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        '%' . $wpdb->esc_like('iftp_nf_activation_') . '%'
    )
);

foreach ($transient_options as $option_name) {
    delete_option($option_name);
}

$timestamp = wp_next_scheduled('iftp_nf_expire_payments');

if (false !== $timestamp) {
    wp_unschedule_event($timestamp, 'iftp_nf_expire_payments');
}

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}iftp_nf_payments_index");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}iftp_nf_adhoc_payments_index");

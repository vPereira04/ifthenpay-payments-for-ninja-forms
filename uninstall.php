<?php
/**
 * Uninstall handler.
 *
 * @package Ifthenpay\NinjaForms
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/*
 * Options, transients, cron and the index tables all live per site, so on a
 * network I clean every site, not just the one running the uninstall.
 */
$iftp_nf_uninstall_site = static function (): void {
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
    delete_option('iftp_nf_confirmation_titles');
    delete_option('iftp_nf_db_version');
    delete_option('iftp_nf_payments_index_version');
    delete_option('iftp_nf_adhoc_payments_index_version');
    delete_option('iftp_nf_adhoc_form');
    delete_option('iftp_nf_test_ref_seq');

    delete_transient('iftp_nf_gateway_rows');
    delete_transient('iftp_nf_methods_catalog');

    // One option per payment, plus the activation-request cooldown transients.
    $option_names = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like('iftp_nf_payment_') . '%',
            '%' . $wpdb->esc_like('iftp_nf_activation_') . '%'
        )
    );

    foreach ($option_names as $option_name) {
        delete_option($option_name);
    }

    wp_clear_scheduled_hook('iftp_nf_expire_payments');

    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'iftp_nf_payments_index'));
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'iftp_nf_adhoc_payments_index'));
};

if (is_multisite()) {
    // `number` 0 means every site; get_sites() stops at 100 otherwise.
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $iftp_nf_site_id) {
        switch_to_blog((int) $iftp_nf_site_id);
        $iftp_nf_uninstall_site();
        restore_current_blog();
    }
} else {
    $iftp_nf_uninstall_site();
}

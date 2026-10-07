<?php
/**
 * Plugin Name: ifthenpay | Payments for Ninja Forms
 * Plugin URI: https://github.com/vPereira04/ifthenpay-payments-for-ninja-forms
 * Description: Accept ifthenpay payments (Multibanco, MB WAY, Payshop, Pix, Credit Card and more) in Ninja Forms via Pay by Link, with webhook-confirmed payment status.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: ninja-forms
 * Author: ifthenpay
 * Author URI: https://ifthenpay.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ifthenpay-payments-for-ninja-forms
 *
 * @package Ifthenpay\NinjaForms
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('IFTP_NF_VERSION', '1.0.0');
define('IFTP_NF_FILE', __FILE__);
define('IFTP_NF_PATH', plugin_dir_path(__FILE__));
define('IFTP_NF_URL', plugin_dir_url(__FILE__));

$iftp_nf_autoload = IFTP_NF_PATH . 'vendor/autoload.php';

if (file_exists($iftp_nf_autoload)) {
    require_once $iftp_nf_autoload;
}

/*
 * Ninja Forms fires `ninja_forms_loaded` from inside its own `plugins_loaded`
 * callback, and reads our filter early (priority -1) on that same event. If
 * we bootstrapped at the default priority too, load order would decide
 * whether our filter got registered in time — so I hook earlier here to
 * remove that race entirely.
 */
add_action(
    'plugins_loaded',
    static function (): void {
        if (! class_exists('Ninja_Forms')) {
            add_action(
                'admin_notices',
                static function (): void {
                    wp_admin_notice(
                        esc_html__('ifthenpay Payments for Ninja Forms requires Ninja Forms to be installed and active.', 'ifthenpay-payments-for-ninja-forms'),
                        ['type' => 'warning']
                    );
                },
                10,
                0
            );

            return;
        }

        if (! class_exists(\Ifthenpay\NinjaForms\Plugin::class)) {
            add_action(
                'admin_notices',
                static function (): void {
                    wp_admin_notice(
                        esc_html__('ifthenpay Payments for Ninja Forms could not load its classes. Run "composer install" in the plugin folder.', 'ifthenpay-payments-for-ninja-forms'),
                        ['type' => 'error']
                    );
                },
                10,
                0
            );

            return;
        }

        \Ifthenpay\NinjaForms\Plugin::instance()->boot();
    },
    5
);

register_activation_hook(__FILE__, static function (): void {
    // `Requires Plugins` already blocks this on WP 6.5+; this covers 6.4.
    if (! class_exists('Ninja_Forms')) {
        wp_die(
            esc_html__('ifthenpay Payments for Ninja Forms requires Ninja Forms to be installed and active.', 'ifthenpay-payments-for-ninja-forms'),
            esc_html__('Plugin dependency missing', 'ifthenpay-payments-for-ninja-forms'),
            ['back_link' => true]
        );
    }

    if (class_exists(\Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::class)) {
        \Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::schedule();
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists(\Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::class)) {
        \Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::unschedule();
    }
});

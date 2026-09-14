<?php
/**
 * Plugin Name: ifthenpay Payments for Ninja Forms
 * Plugin URI: https://ifthenpay.com
 * Description: Accept ifthenpay payments (Multibanco, MB WAY, Payshop, Pix, Credit Card and more) in Ninja Forms via Pay by Link, with webhook-confirmed payment status.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: ifthenpay
 * Author URI: https://ifthenpay.com
 * License: GPL v2 or later
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
 * Ninja Forms fires `ninja_forms_loaded` from *inside* its own
 * `plugins_loaded` callback (registered at the default priority, 10), and
 * `NF_Actions_CollectPayment` reads the `ninja_forms_register_payment_gateways`
 * filter on that same event at priority -1. If our bootstrap also ran at the
 * default priority, whichever plugin's file loaded first would win the race
 * -  and if Ninja Forms won, our filter would never be registered in time,
 * so "ifthenpay" would silently never appear in the gateway dropdown.
 * Hooking an earlier priority here removes that race entirely: this always
 * runs, and therefore always registers our `ninja_forms_loaded` handler,
 * before Ninja Forms' own `plugins_loaded` callback can fire.
 * `class_exists('Ninja_Forms')` is still safe to check this early because
 * the class itself is defined at file-include time, before `plugins_loaded`
 * fires for any plugin.
 */
add_action('plugins_loaded', 'iftp_nf_bootstrap', 5);

function iftp_nf_bootstrap(): void
{
    if (! class_exists('Ninja_Forms')) {
        add_action('admin_notices', 'iftp_nf_missing_ninja_forms_notice');

        return;
    }

    if (! class_exists(\Ifthenpay\NinjaForms\Plugin::class)) {
        add_action('admin_notices', 'iftp_nf_missing_autoloader_notice');

        return;
    }

    \Ifthenpay\NinjaForms\Plugin::instance()->boot();
}

function iftp_nf_missing_ninja_forms_notice(): void
{
    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html__('ifthenpay Payments for Ninja Forms requires Ninja Forms to be installed and active.', 'ifthenpay-payments-for-ninja-forms')
    );
}

function iftp_nf_missing_autoloader_notice(): void
{
    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__('ifthenpay Payments for Ninja Forms could not load its classes. Run "composer install" in the plugin folder.', 'ifthenpay-payments-for-ninja-forms')
    );
}

register_activation_hook(__FILE__, static function (): void {
    if (class_exists(\Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::class)) {
        \Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::schedule();
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists(\Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::class)) {
        \Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron::unschedule();
    }
});

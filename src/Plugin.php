<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms;

use Ifthenpay\NinjaForms\Admin\EntriesPage;
use Ifthenpay\NinjaForms\Admin\SettingsPage;
use Ifthenpay\NinjaForms\Ajax\Controller as AjaxController;
use Ifthenpay\NinjaForms\Api\Webhook\WebhookController;
use Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron;
use Ifthenpay\NinjaForms\Gateway\IfthenpayGateway;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin bootstrap: wires every piece together once, on `ninja_forms_loaded`.
 */
class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        add_action('ninja_forms_loaded', [$this, 'register_gateway'], -5);

        (new SettingsPage())->register();
        (new EntriesPage())->register();
        (new AjaxController())->register();
        (new WebhookController())->register();
        (new ExpiredPaymentsCron())->register();

        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_return_banner']);
    }

    /**
     * Hooked at priority -5 on `ninja_forms_loaded`, so our `add_filter`
     * call below is in place before CollectPayment's own handler on the
     * same action (priority -1) applies the filter.
     */
    public function register_gateway(): void
    {
        add_filter('ninja_forms_register_payment_gateways', static function (array $gateways): array {
            $gateways[IfthenpayGateway::SLUG] = new IfthenpayGateway();

            return $gateways;
        });
    }

    public function maybe_enqueue_return_banner(): void
    {
        $status = sanitize_text_field(wp_unslash($_GET['iftp_nf_pay'] ?? ''));
        $ref    = sanitize_text_field(wp_unslash($_GET['ref'] ?? ''));

        if ('' === $status || '' === $ref) {
            return;
        }

        $record = (new SubmissionStore())->get($ref);

        if (null === $record) {
            return;
        }

        wp_enqueue_script('iftp-nf-frontend', IFTP_NF_URL . 'assets/js/frontend.js', [], IFTP_NF_VERSION, true);

        wp_localize_script('iftp-nf-frontend', 'iftpNfReturn', [
            'status'  => $record['status'],
            'message' => $this->status_message($record['status']),
        ]);
    }

    private function status_message(string $status): string
    {
        switch ($status) {
            case SubmissionStore::STATUS_PAID:
                return __('Payment confirmed. Thank you!', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_FAILED:
                return __('Payment failed. Please try again.', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_CANCELLED:
                return __('Payment cancelled.', 'ifthenpay-payments-for-ninja-forms');
            default:
                return __('Payment pending. We will confirm it as soon as it is received (this can take a while for Multibanco/Payshop).', 'ifthenpay-payments-for-ninja-forms');
        }
    }
}

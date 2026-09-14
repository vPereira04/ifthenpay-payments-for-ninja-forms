<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Ajax;

use Ifthenpay\NinjaForms\Api\Webhook\WebhookController;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Plugin;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Public-facing AJAX endpoint backing the return-status popup
 * (`assets/js/frontend.js`) — unlike `Controller` above (admin-only), this is
 * reachable by any visitor, logged in or not, since it's what a customer's
 * browser calls right after returning from ifthenpay's hosted payment page.
 *
 * Two things happen here, mirroring `ifthenpay-payments-for-wpforms`'
 * `Api\WPForms\Process::ajax_verify_payment()`:
 *
 *  - On the customer's very first call back (`return_action` "success" with a
 *    transaction id), try to resolve the payment immediately via
 *    `WebhookController::confirm_via_transaction_status()` rather than
 *    waiting on the asynchronous webhook.
 *  - Every call, including plain background polling ticks, reports the
 *    payment's real, current stored status — this never trusts a
 *    client-supplied status to mark anything paid; only the webhook or a
 *    successful transaction-status confirmation above may do that.
 */
class FrontendController
{
    public const NONCE_ACTION = 'iftp_nf_frontend';

    private SubmissionStore $submissions;
    private WebhookController $webhook;

    public function __construct(?SubmissionStore $submissions = null, ?WebhookController $webhook = null)
    {
        $this->submissions = $submissions ?? new SubmissionStore();
        $this->webhook     = $webhook ?? new WebhookController();
    }

    public function register(): void
    {
        add_action('wp_ajax_iftp_nf_verify_payment', [$this, 'verify_payment']);
        add_action('wp_ajax_nopriv_iftp_nf_verify_payment', [$this, 'verify_payment']);
    }

    public function verify_payment(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $ref = sanitize_text_field(wp_unslash($_POST['ref'] ?? ''));

        if ('' === $ref) {
            wp_send_json_error(['message' => __('Missing required parameters.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        $return_action = sanitize_text_field(wp_unslash($_POST['return_action'] ?? ''));

        if ('success' === $return_action) {
            $transaction_id = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));

            if ('' !== $transaction_id) {
                $this->submissions->record_transaction_id($ref, $transaction_id);
                $this->webhook->confirm_via_transaction_status($ref, $transaction_id);
            }
        }

        $record        = $this->submissions->get($ref);
        $stored_status = null !== $record ? (string) $record['status'] : SubmissionStore::STATUS_PENDING;

        // Same query-param fallback `Plugin::maybe_enqueue_return_banner()` applies on
        // first page load — reapplied on every poll tick too (`query_status` is the
        // page's original `iftp_nf_pay` value, unrelated to this call's own
        // `return_action`), or a customer shown "Payment cancelled" on load would see
        // it flip back to "Payment pending" the moment the stored status is still
        // "pending" by the time polling starts.
        $query_status = sanitize_text_field(wp_unslash($_POST['query_status'] ?? ''));
        $status       = Plugin::resolve_display_status($stored_status, $query_status);
        $settings     = new SettingsRepository();
        $is_paid      = SubmissionStore::STATUS_PAID === $status;

        wp_send_json_success([
            'status'      => $status,
            'message'     => Plugin::status_message($status),
            // Only meaningful for "paid" — a payment resolving to "paid"
            // mid-poll (see `assets/js/frontend.js`) redirects here instead
            // of showing the checkmark popup, matching the "Confirmation
            // Type" setting (`Admin\ConfirmationPage`) exactly as a "paid at
            // load" request already does via
            // `Plugin::maybe_redirect_paid_confirmation()`.
            'redirectUrl' => $is_paid ? $settings->get_paid_redirect_url() : '',
            // Same "only once genuinely paid" guard as
            // `Plugin::maybe_enqueue_return_banner()`'s own `entryData` —
            // the "Show Entry Data" checkbox (`Admin\ConfirmationPage`)
            // never leaks a submission's fields before its payment is
            // actually confirmed.
            'entryData'   => $is_paid && null !== $record && $settings->get_show_entry_data()
                ? $this->submissions->entry_data_pairs($record)
                : [],
        ]);
    }
}

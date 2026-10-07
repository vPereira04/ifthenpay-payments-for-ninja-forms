<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Ajax;

use Ifthenpay\NinjaForms\Gateway\IfthenpayGateway;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Plugin;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * This has to be public (no login check) since it's called by a customer's
 * browser right after they return from ifthenpay's hosted payment page.
 *
 * I only ever report our own stored status here — never a client-supplied
 * one — and I never mark anything paid. Only the webhook (validated via
 * WebhookValidator) is allowed to do that.
 *
 * The nonce is the same for every logged-out visitor, so it only stops
 * cross-site posts. What ties a request to one payment is the signed
 * return key from the customer's own return URL.
 */
class FrontendController
{
    public const NONCE_ACTION = 'iftp_nf_frontend';

    private SubmissionStore $submissions;

    public function __construct(?SubmissionStore $submissions = null)
    {
        $this->submissions = $submissions ?? new SubmissionStore();
    }

    public function register(): void
    {
        add_action('wp_ajax_iftp_nf_verify_payment', [$this, 'verify_payment']);
        add_action('wp_ajax_nopriv_iftp_nf_verify_payment', [$this, 'verify_payment']);
    }

    public function verify_payment(): void
    {
        // A stale nonce has to come back as JSON; a bare -1 would just look
        // like a network error to the poll.
        if (! check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Please reload the page.', 'ifthenpay-payments-for-ninja-forms')], 403);
        }

        $ref = sanitize_text_field(wp_unslash($_POST['ref'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));

        if ('' === $ref) {
            wp_send_json_error(['message' => __('Missing required parameters.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        if (! IfthenpayGateway::is_valid_return_key($ref, $key)) {
            wp_send_json_error(['message' => __('This payment link is not valid.', 'ifthenpay-payments-for-ninja-forms')], 403);
        }

        $return_action = sanitize_text_field(wp_unslash($_POST['return_action'] ?? ''));

        if ('success' === $return_action) {
            $transaction_id = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));

            if ('' !== $transaction_id) {
                $this->submissions->record_transaction_id($ref, $transaction_id);
            }
        }

        $record        = $this->submissions->get($ref);
        $stored_status = null !== $record ? (string) $record['status'] : SubmissionStore::STATUS_PENDING;

        // I reapply this fallback on every poll tick, not just first load — query_status
        // carries the page's original return status, and without it a customer shown
        // "cancelled" on load could flip back to "pending" before polling catches up.
        $query_status = sanitize_text_field(wp_unslash($_POST['query_status'] ?? ''));
        $status       = Plugin::resolve_display_status($stored_status, $query_status);
        $settings     = new SettingsRepository();
        $is_paid      = SubmissionStore::STATUS_PAID === $status;

        wp_send_json_success([
            'status'      => $status,
            'message'     => Plugin::status_message($status),
            // Only set when paid — if the payment resolves to "paid" mid-poll, the
            // frontend redirects here instead of showing the checkmark popup.
            'redirectUrl' => $is_paid ? $settings->get_paid_redirect_url() : '',
            // Gated on is_paid so we never leak a submission's fields before the
            // payment is actually confirmed.
            'entryData'   => $is_paid && null !== $record && $settings->get_show_entry_data()
                ? $this->submissions->entry_data_pairs($record)
                : [],
        ]);
    }
}

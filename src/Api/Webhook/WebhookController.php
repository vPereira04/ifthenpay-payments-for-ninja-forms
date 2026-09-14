<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Api\Webhook;

use Ifthenpay\NinjaForms\Api\IfthenpayClient;
use Ifthenpay\NinjaForms\NinjaForms\ResumeController;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Receives ifthenpay's server-to-server webhook — still the only path that
 * runs a payment's remaining form actions (Email, Success Message, ...) once
 * it's confirmed paid, whether that confirmation came from here or from
 * `confirm_via_transaction_status()` below. The customer's browser return
 * (`iftp_nf_pay=success|error|cancel`) is flash-only and never completes
 * anything on its own, matching every sibling ifthenpay plugin.
 */
class WebhookController
{
    public const ACTION = 'iftp_nf_webhook';

    private SubmissionStore $submissions;
    private WebhookValidator $validator;
    private ResumeController $resume;
    private IfthenpayClient $client;

    public function __construct(
        ?SubmissionStore $submissions = null,
        ?WebhookValidator $validator = null,
        ?ResumeController $resume = null,
        ?IfthenpayClient $client = null
    ) {
        $this->submissions = $submissions ?? new SubmissionStore();
        $this->validator    = $validator ?? new WebhookValidator();
        $this->resume       = $resume ?? new ResumeController();
        $this->client       = $client ?? new IfthenpayClient();
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
    }

    public static function url(): string
    {
        return admin_url('admin-ajax.php?action=' . self::ACTION);
    }

    public function handle(): void
    {
        $payload = WebhookPayload::from_request();

        if ('' === $payload->ref()) {
            wp_die('Missing reference', 'ifthenpay', ['response' => 400]);
        }

        $record = $this->submissions->get($payload->ref());

        if (null === $record) {
            wp_die('Unknown reference', 'ifthenpay', ['response' => 404]);
        }

        if ($payload->is_failure_notice()) {
            $this->handle_failure($payload, $record);
            wp_die('OK', 'ifthenpay', ['response' => 200]);
        }

        if (! $payload->is_success_notice() || ! $this->validator->is_valid($payload, $record)) {
            wp_die('Invalid anti-phishing key or amount', 'ifthenpay', ['response' => 403]);
        }

        $this->handle_success($payload, $record);

        wp_die('OK', 'ifthenpay', ['response' => 200]);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function handle_success(WebhookPayload $payload, array $record): void
    {
        $this->finalize_paid($payload->ref(), $payload->method(), $payload->request_id(), $record);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function handle_failure(WebhookPayload $payload, array $record): void
    {
        if ('cancelled' === $payload->status()) {
            $this->submissions->mark_cancelled($payload->ref());

            return;
        }

        $this->submissions->mark_failed($payload->ref());
    }

    /**
     * Resolves a payment immediately via ifthenpay's own transaction-status
     * API, using the transaction id ifthenpay appended to a genuine success
     * return (`Gateway\IfthenpayGateway::build_return_url()`), instead of
     * waiting on this same class's asynchronous `handle()` webhook — called
     * from `Ajax\FrontendController::verify_payment()` the moment the
     * customer's browser lands back on the success return URL.
     *
     * A client-supplied transaction id can't be used to fake a "paid" result
     * on its own: this only ever trusts what ifthenpay's own API reports back
     * for that id, and only accepts it once that report's `OrderId` matches
     * this exact payment's `$ref` and its `Amount` matches what this payment
     * was actually for — the same two checks `WebhookValidator` runs against
     * the async webhook's own payload, just sourced from a different ifthenpay
     * endpoint here.
     *
     * Returns `false` (rather than throwing) for every way this can
     * legitimately not resolve yet — API unreachable, unknown/unmatched
     * transaction, mismatched amount, or a payment method not yet present
     * (e.g. a Multibanco/Payshop reference that only just got generated) —
     * so the caller falls back to polling/the webhook exactly as if no
     * transaction id had been supplied at all.
     */
    public function confirm_via_transaction_status(string $ref, string $transaction_id): bool
    {
        $transaction_id = trim($transaction_id);

        if ('' === $ref || '' === $transaction_id) {
            return false;
        }

        $record = $this->submissions->get($ref);

        if (null === $record) {
            return false;
        }

        if (SubmissionStore::STATUS_PAID === $record['status']) {
            return true;
        }

        $status = $this->client->get_transaction_status($transaction_id);

        $order_id = isset($status['OrderId']) ? sanitize_text_field((string) $status['OrderId']) : '';

        if ('' === $order_id || $order_id !== $ref) {
            return false;
        }

        $amount = isset($status['Amount']) ? (float) $status['Amount'] : 0.0;

        if (abs($amount - (float) $record['amount']) > 0.01) {
            return false;
        }

        $payment_method = isset($status['PaymentMethod']) ? sanitize_text_field((string) $status['PaymentMethod']) : '';

        if ('' === $payment_method) {
            return false;
        }

        $this->finalize_paid($ref, $payment_method, $transaction_id, $record);

        return true;
    }

    /**
     * Shared completion side effects for a payment independently confirmed
     * paid, whether that confirmation came from the async webhook
     * (`handle_success()`) or `confirm_via_transaction_status()` above — both
     * call this only after their own server-to-server verification against
     * ifthenpay has passed.
     *
     * @param array<string, mixed> $record
     */
    private function finalize_paid(string $ref, string $pay_method, string $request_id, array $record): void
    {
        $already_paid = SubmissionStore::STATUS_PAID === $record['status'];

        $this->submissions->mark_paid($ref, $pay_method, $request_id);

        if ($already_paid) {
            return;
        }

        // Re-read the just-updated record — `mark_paid()` above already
        // synced its extra values onto the reserved submission's post meta
        // (see `SubmissionStore`) — so what's merged into `$data['extra']`
        // below reflects the final paid status, method, and request id, not
        // the stale "pending" snapshot captured at halt time. The "save"
        // action itself never runs again here (it already ran up front, see
        // `Gateway\IfthenpayGateway::reserve_submission()`); this is only
        // for whatever actions remain (Email, Success Message, Redirect...).
        $updated_record = $this->submissions->get($ref) ?? $record;

        $data = $updated_record['data'];
        $data['extra'] = array_merge(
            $data['extra'] ?? [],
            $this->submissions->submission_extra_values($updated_record)
        );

        $data = $this->resume->run_remaining_actions((int) $updated_record['form_id'], $data);

        // If `reserve_submission()` never got a `sub_id` up front, the
        // "save" action just ran for the first time above and minted the
        // real submission — persist that back onto this record, or it stays
        // permanently disconnected from it (see `SubmissionStore::update_data()`).
        $this->submissions->update_data($ref, $data);
    }
}

<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Api\Webhook;

use Ifthenpay\NinjaForms\NinjaForms\ResumeController;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Receives ifthenpay's server-to-server webhook. This is the only place in
 * the plugin that ever marks a payment `paid` — the customer's browser
 * return (`iftp_nf_pay=success|error|cancel`) is flash-only and never
 * completes anything, matching every sibling ifthenpay plugin.
 */
class WebhookController
{
    public const ACTION = 'iftp_nf_webhook';

    private SubmissionStore $submissions;
    private WebhookValidator $validator;
    private ResumeController $resume;

    public function __construct(
        ?SubmissionStore $submissions = null,
        ?WebhookValidator $validator = null,
        ?ResumeController $resume = null
    ) {
        $this->submissions = $submissions ?? new SubmissionStore();
        $this->validator    = $validator ?? new WebhookValidator();
        $this->resume       = $resume ?? new ResumeController();
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
        $already_paid = SubmissionStore::STATUS_PAID === $record['status'];

        $this->submissions->mark_paid($payload->ref(), $payload->method(), $payload->request_id());

        if ($already_paid) {
            return;
        }

        // Re-read the just-updated record so the extra values written onto
        // the submission (below) reflect the final paid status, method, and
        // request id — not the stale "pending" snapshot captured at halt time.
        $updated_record = $this->submissions->get($payload->ref()) ?? $record;

        $data = $updated_record['data'];
        $data['extra'] = array_merge(
            $data['extra'] ?? [],
            $this->submissions->submission_extra_values($updated_record)
        );

        $this->resume->run_remaining_actions((int) $updated_record['form_id'], $data);
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
}

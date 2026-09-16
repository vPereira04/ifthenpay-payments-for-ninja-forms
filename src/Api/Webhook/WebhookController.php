<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Api\Webhook;

use Ifthenpay\NinjaForms\NinjaForms\ResumeController;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * I confirm payments here — this is the only path that runs a form's
 * remaining actions (email, success message, ...) once it's paid. The
 * customer's browser return is just a flash message; it never completes
 * anything on its own.
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
     * Completion logic for a payment confirmed paid by the webhook — the
     * only caller, since it's the only place that's already verified this
     * server-side via WebhookValidator.
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

        // I re-read the record here so $data['extra'] reflects the final paid
        // status/method, not the stale pending snapshot. The save action
        // already ran up front — this only covers what's left (email,
        // success message, redirect...).
        $updated_record = $this->submissions->get($ref) ?? $record;

        $data = $updated_record['data'];
        $data['extra'] = array_merge(
            $data['extra'] ?? [],
            $this->submissions->submission_extra_values($updated_record)
        );

        $data = $this->resume->run_remaining_actions((int) $updated_record['form_id'], $data);

        // If there was no sub_id reserved up front, the save action just ran
        // for the first time above and minted the real submission — I persist
        // that back onto the record so it doesn't stay orphaned.
        $this->submissions->update_data($ref, $data);
    }
}

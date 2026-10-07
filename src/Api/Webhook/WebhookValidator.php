<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Api\Webhook;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Anti-phishing + amount validation for an inbound webhook payload, checked
 * against the pending payment record it claims to resolve.
 */
class WebhookValidator
{
    private const AMOUNT_TOLERANCE = 0.01;

    /**
     * @param array<string, mixed> $record As stored by SubmissionStore.
     */
    public function is_valid(WebhookPayload $payload, array $record): bool
    {
        $gateway_key = (string) base64_decode($payload->apk(), true);

        if ('' === $gateway_key || ! hash_equals((string) $record['gateway_key'], $gateway_key)) {
            return false;
        }

        return abs($payload->amount() - (float) $record['amount']) <= self::AMOUNT_TOLERANCE;
    }
}

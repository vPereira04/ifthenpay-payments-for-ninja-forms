<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Api\Webhook;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The asynchronous server-to-server callback ifthenpay sends when a payment
 * resolves:
 *
 *   Success: GET ?ref={ref}&apk={base64(gateway_key)}&val={amount}&mtd={method}&req={request_id}
 *   Failure: GET ?status={cancelled|error}&ref={ref}
 */
class WebhookPayload
{
    private string $ref;
    private string $apk;
    private string $val;
    private string $mtd;
    private string $req;
    private string $status;

    private function __construct(string $ref, string $apk, string $val, string $mtd, string $req, string $status)
    {
        $this->ref    = $ref;
        $this->apk    = $apk;
        $this->val    = $val;
        $this->mtd    = $mtd;
        $this->req    = $req;
        $this->status = $status;
    }

    public static function from_request(): self
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Server-to-server callback from ifthenpay; nonces don't apply. WebhookValidator checks the anti-phishing key and amount.
        return new self(
            sanitize_text_field(wp_unslash($_GET['ref'] ?? '')),
            sanitize_text_field(wp_unslash($_GET['apk'] ?? '')),
            sanitize_text_field(wp_unslash($_GET['val'] ?? '')),
            sanitize_text_field(wp_unslash($_GET['mtd'] ?? '')),
            sanitize_text_field(wp_unslash($_GET['req'] ?? '')),
            sanitize_text_field(wp_unslash($_GET['status'] ?? ''))
        );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    public function is_failure_notice(): bool
    {
        return '' !== $this->status;
    }

    public function is_success_notice(): bool
    {
        return '' !== $this->ref && '' !== $this->apk && '' !== $this->val;
    }

    public function ref(): string
    {
        return $this->ref;
    }

    public function apk(): string
    {
        return $this->apk;
    }

    public function amount(): float
    {
        return (float) $this->val;
    }

    public function method(): string
    {
        return $this->mtd;
    }

    public function request_id(): string
    {
        return $this->req;
    }

    public function status(): string
    {
        return $this->status;
    }
}

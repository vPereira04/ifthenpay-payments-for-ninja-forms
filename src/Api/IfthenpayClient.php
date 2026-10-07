<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Api;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the ifthenpay HTTP API.
 */
class IfthenpayClient
{
    private const API_BASE      = 'https://api.ifthenpay.com';
    private const GATEWAY_TYPE  = 'ninjaforms';
    private const CALLBACK_CMS  = 'ninjaforms';

    private const GATEWAY_ROWS_CACHE = 'iftp_nf_gateway_rows';
    private const CATALOG_CACHE      = 'iftp_nf_methods_catalog';

    private string $last_error = '';

    /**
     * The raw reason the last call failed — a WP_Error message, or the raw
     * body when ifthenpay didn't return a RedirectUrl. I keep this out of
     * customer-facing responses; it's only for logs or admins.
     */
    public function get_last_error(): string
    {
        return $this->last_error;
    }

    /**
     * GET /gateway/get?boKey={key}&Type=NinjaForms
     *
     * Returns the raw gateway row(s) for this Backoffice Key, or an empty
     * array when the key is invalid/has no NinjaForms-type gateway.
     *
     * I cache the rows for a few minutes, so switching Gateway Keys back and
     * forth doesn't hit the API every time. Connect and Refresh pass $fresh.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_gateway_keys(string $backoffice_key, bool $fresh = false): array
    {
        $key_hash = md5($backoffice_key);
        $cached   = get_transient(self::GATEWAY_ROWS_CACHE);
        $cached   = is_array($cached) && ($cached['key_hash'] ?? '') === $key_hash && is_array($cached['rows'] ?? null) ? $cached['rows'] : [];

        if (! $fresh && [] !== $cached) {
            return $cached;
        }

        $response = wp_remote_get(
            add_query_arg(
                [
                    'boKey' => $backoffice_key,
                    'Type'  => self::GATEWAY_TYPE,
                ],
                self::API_BASE . '/gateway/get'
            ),
            ['timeout' => 15]
        );

        // API down or erroring: keep what we had rather than "lose" the gateway.
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return $cached;
        }

        $body = $this->decode_response($response);

        if ([] === $body) {
            return [];
        }

        // One gateway comes back as a bare object, several as a list.
        $rows = isset($body[0]) ? array_values(array_filter($body, 'is_array')) : [$body];

        set_transient(self::GATEWAY_ROWS_CACHE, ['key_hash' => $key_hash, 'rows' => $rows], 5 * MINUTE_IN_SECONDS);

        return $rows;
    }

    /**
     * GET /gateway/methods/available
     *
     * The catalog is the same for every account and rarely changes, so I
     * keep it for 12 hours. Failures aren't cached; they fall back to
     * whatever copy is still there.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_available_methods(bool $fresh = false): array
    {
        $cached = get_transient(self::CATALOG_CACHE);
        $cached = is_array($cached) ? $cached : [];

        if (! $fresh && [] !== $cached) {
            return $cached;
        }

        $response = wp_remote_get(self::API_BASE . '/gateway/methods/available', ['timeout' => 15]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return $cached;
        }

        $catalog = array_values(array_filter($this->decode_response($response), 'is_array'));

        if ([] !== $catalog) {
            set_transient(self::CATALOG_CACHE, $catalog, 12 * HOUR_IN_SECONDS);
        }

        return $catalog;
    }

    public static function clear_cache(): void
    {
        delete_transient(self::GATEWAY_ROWS_CACHE);
        delete_transient(self::CATALOG_CACHE);
    }

    /**
     * POST /gateway/pinpay/{gateway_key}
     *
     * Heads up: the redirect field is `RedirectUrl` (PascalCase), confirmed
     * against a live response — there's no `redirect_url`.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|false
     */
    public function create_payment_link(string $gateway_key, array $payload)
    {
        $this->last_error = '';

        $response = wp_remote_post(
            self::API_BASE . '/gateway/pinpay/' . rawurlencode($gateway_key),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => (string) wp_json_encode($payload),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();

            return false;
        }

        $body = $this->decode_response($response);

        if (empty($body['RedirectUrl'])) {
            $this->last_error = sprintf(
                'HTTP %d: %s',
                wp_remote_retrieve_response_code($response),
                wp_remote_retrieve_body($response)
            );

            return false;
        }

        return $body;
    }

    /**
     * POST /endpoint/callback/activation/?cms=ninjaforms
     *
     * Registers (or re-registers) the webhook URL for this gateway key.
     */
    public function activate_callback(string $gateway_key, string $webhook_url): bool
    {
        $response = wp_remote_post(
            self::API_BASE . '/endpoint/callback/activation/?cms=' . self::CALLBACK_CMS,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => (string) wp_json_encode([
                    'apKey' => base64_encode($gateway_key),
                    'chave' => $gateway_key,
                    'urlCb' => $webhook_url,
                ]),
                'timeout' => 15,
            ]
        );

        return ! is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int|string, mixed>
     */
    private function decode_response(array $response): array
    {
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : [];
    }
}

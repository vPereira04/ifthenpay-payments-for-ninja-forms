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

    private string $last_error = '';

    /**
     * The raw reason the last `create_payment_link()`/`get_gateway_keys()`
     * call failed (a WP_Error message, or the raw response body when
     * ifthenpay returned something without a `RedirectUrl`). Empty when
     * the last call succeeded. Never shown to the customer — log it or
     * surface it to an admin only.
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
     * @return array<int, array<string, mixed>>
     */
    public function get_gateway_keys(string $backoffice_key): array
    {
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

        $body = $this->decode_response($response);

        if (! is_array($body)) {
            return [];
        }

        return isset($body[0]) ? $body : [$body];
    }

    /**
     * GET /gateway/methods/available
     *
     * @return array<int, array{Entity: string, Alias: string, IsVisible: bool, Position: int}>
     */
    public function get_available_methods(): array
    {
        $response = wp_remote_get(self::API_BASE . '/gateway/methods/available', ['timeout' => 15]);

        $body = $this->decode_response($response);

        return is_array($body) ? $body : [];
    }

    /**
     * POST /gateway/pinpay/{gateway_key}
     *
     * The response's redirect field is `RedirectUrl` (PascalCase) — confirmed
     * against a live response: `{"PinCode":"...","PinpayUrl":"...","RedirectUrl":"https://pinpay.pt/..."}`.
     * There is no `redirect_url` key.
     *
     * @param array<string, mixed> $payload
     * @return array{RedirectUrl?: string}|false
     */
    public function create_payment_link(string $gateway_key, array $payload)
    {
        $this->last_error = '';

        $response = wp_remote_post(
            self::API_BASE . '/gateway/pinpay/' . rawurlencode($gateway_key),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode($payload),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();

            return false;
        }

        $body = $this->decode_response($response);

        if (! is_array($body) || empty($body['RedirectUrl'])) {
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
                'body'    => wp_json_encode([
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
     * @return mixed
     */
    private function decode_response($response)
    {
        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

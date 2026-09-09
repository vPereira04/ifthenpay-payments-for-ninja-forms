<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Api;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the Pay by Link request payload and the accounts string.
 */
class IfthenpayPayload
{
    /**
     * `$method['account']` is already the complete `ENTITY|ACCOUNT` segment
     * ifthenpay's own gateway row provided (see `Sync\GatewaySync::resolve_account()`)
     * — Multibanco's segment, for instance, is a numeric Entidade/Subentidade
     * pair (`"11686|000"`), never the literal string "MB". Never re-derive
     * or re-prefix it from `$method['entity']` here.
     *
     * @param array<int, array{entity: string, account: string}> $enabled_methods
     */
    public static function build_accounts_string(array $enabled_methods): string
    {
        $pairs = [];

        foreach ($enabled_methods as $method) {
            if ('' === $method['account']) {
                continue;
            }

            $pairs[] = $method['account'];
        }

        return implode(';', $pairs);
    }

    public static function format_amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @param array<int, array{entity: string, account: string}> $enabled_methods
     * @return array<string, mixed>
     */
    public static function build_payment_payload(
        string $id,
        float $amount,
        string $description,
        array $enabled_methods,
        string $success_url,
        string $error_url,
        string $cancel_url,
        string $lang = 'en',
        string $selected_method_position = ''
    ): array {
        $payload = [
            'id'          => $id,
            'amount'      => self::format_amount($amount),
            'description' => $description,
            'accounts'    => self::build_accounts_string($enabled_methods),
            'success_url' => $success_url,
            'error_url'   => $error_url,
            'cancel_url'  => $cancel_url,
            'otp'         => 'true',
            'lang'        => $lang,
        ];

        if ('' !== $selected_method_position) {
            $payload['selected_method'] = $selected_method_position;
        }

        return $payload;
    }

    /**
     * Maps a WordPress locale (e.g. `pt_PT`, `es_ES`) to an ifthenpay `lang` code.
     */
    public static function locale_to_lang(string $locale): string
    {
        $prefix = strtolower(substr($locale, 0, 2));

        return in_array($prefix, ['pt', 'es', 'fr'], true) ? $prefix : 'en';
    }
}

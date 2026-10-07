<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tells our admin screens apart. Ninja Forms' settings tabs all share one
 * screen ID, so the `tab` query arg is the only way to know which is open.
 */
final class AdminScreen
{
    public static function is(string $page, string $tab = ''): bool
    {
        return $page === self::query_arg('page') && ('' === $tab || $tab === self::query_arg('tab'));
    }

    private static function query_arg(string $name): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; nothing is saved.
        return sanitize_text_field(wp_unslash($_GET[$name] ?? ''));
    }
}

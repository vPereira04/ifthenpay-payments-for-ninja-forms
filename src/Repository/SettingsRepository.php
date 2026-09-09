<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Repository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the plugin's global ifthenpay configuration.
 *
 * Ninja Forms has no per-form/per-feed concept the way Gravity Forms or
 * WPForms do, so (matching the ifthenpay-payments-for-givewp precedent) all
 * ifthenpay Gateway settings are global to the site, not per form.
 */
class SettingsRepository
{
    private const OPTION_BACKOFFICE_KEY = 'iftp_nf_backoffice_key';
    private const OPTION_GATEWAY_KEY     = 'iftp_nf_gateway_key';
    private const OPTION_GATEWAY_KEYS    = 'iftp_nf_gateway_keys';
    private const OPTION_METHODS         = 'iftp_nf_methods';
    private const OPTION_DEFAULT_METHOD  = 'iftp_nf_default_method';
    private const OPTION_DESCRIPTION     = 'iftp_nf_description';
    private const OPTION_EXPIRY_DAYS     = 'iftp_nf_expiry_days';

    public function get_backoffice_key(): string
    {
        return (string) get_option(self::OPTION_BACKOFFICE_KEY, '');
    }

    public function set_backoffice_key(string $key): void
    {
        update_option(self::OPTION_BACKOFFICE_KEY, $key, false);
    }

    public function delete_backoffice_key(): void
    {
        delete_option(self::OPTION_BACKOFFICE_KEY);
    }

    public function is_connected(): bool
    {
        return '' !== $this->get_backoffice_key();
    }

    public function get_gateway_key(): string
    {
        return (string) get_option(self::OPTION_GATEWAY_KEY, '');
    }

    public function set_gateway_key(string $gateway_key): void
    {
        update_option(self::OPTION_GATEWAY_KEY, $gateway_key, false);
    }

    /**
     * Every Gateway Key row available on this Backoffice Key (an ifthenpay
     * account can have more than one), so the admin screen can offer a
     * dropdown instead of assuming there's only ever one.
     *
     * @return array<int, string>
     */
    public function get_gateway_keys(): array
    {
        $keys = get_option(self::OPTION_GATEWAY_KEYS, []);

        return is_array($keys) ? $keys : [];
    }

    /**
     * @param array<int, string> $gateway_keys
     */
    public function set_gateway_keys(array $gateway_keys): void
    {
        update_option(self::OPTION_GATEWAY_KEYS, array_values(array_unique($gateway_keys)), false);
    }

    /**
     * @return array<int, array{entity: string, alias: string, logo: string, enabled: bool, account: string, position: int}>
     */
    public function get_methods(): array
    {
        $methods = get_option(self::OPTION_METHODS, []);

        return is_array($methods) ? $methods : [];
    }

    /**
     * @param array<int, array{entity: string, alias: string, enabled: bool, account: string, position: int}> $methods
     */
    public function set_methods(array $methods): void
    {
        update_option(self::OPTION_METHODS, $methods, false);
    }

    /**
     * @return array<int, array{entity: string, account: string}>
     */
    public function get_enabled_methods(): array
    {
        $enabled = [];

        foreach ($this->get_methods() as $method) {
            if (! empty($method['enabled']) && '' !== ($method['account'] ?? '')) {
                $enabled[] = [
                    'entity'  => (string) $method['entity'],
                    'account' => (string) $method['account'],
                ];
            }
        }

        return $enabled;
    }

    public function get_default_method(): string
    {
        return (string) get_option(self::OPTION_DEFAULT_METHOD, '');
    }

    /**
     * The `Position` catalog value for the configured default method, as
     * expected by the PBL payload's `selected_method` field. Empty when no
     * default is set or it's no longer a provisioned/enabled method.
     */
    public function get_default_method_position(): string
    {
        $default = $this->get_default_method();

        if ('' === $default) {
            return '';
        }

        foreach ($this->get_methods() as $method) {
            if ($method['entity'] === $default && ! empty($method['enabled'])) {
                return (string) $method['position'];
            }
        }

        return '';
    }

    public function set_default_method(string $entity): void
    {
        update_option(self::OPTION_DEFAULT_METHOD, $entity);
    }

    public function get_description(): string
    {
        $default = get_bloginfo('name');

        return (string) get_option(self::OPTION_DESCRIPTION, $default);
    }

    public function set_description(string $description): void
    {
        update_option(self::OPTION_DESCRIPTION, $description);
    }

    public function get_expiry_days(): int
    {
        return (int) get_option(self::OPTION_EXPIRY_DAYS, 3);
    }

    public function set_expiry_days(int $days): void
    {
        update_option(self::OPTION_EXPIRY_DAYS, max(1, $days));
    }

    public function delete_all(): void
    {
        delete_option(self::OPTION_BACKOFFICE_KEY);
        delete_option(self::OPTION_GATEWAY_KEY);
        delete_option(self::OPTION_GATEWAY_KEYS);
        delete_option(self::OPTION_METHODS);
        delete_option(self::OPTION_DEFAULT_METHOD);
        delete_option(self::OPTION_DESCRIPTION);
        delete_option(self::OPTION_EXPIRY_DAYS);
    }
}

<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Repository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the plugin's global ifthenpay configuration.
 *
 * Ninja Forms has no per-form/per-feed concept, so all settings here are
 * global to the site rather than per form.
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

    private const OPTION_CONFIRMATION_PAID_TYPE         = 'iftp_nf_confirmation_paid_type';
    private const OPTION_CONFIRMATION_PAID_PAGE_ID      = 'iftp_nf_confirmation_paid_page_id';
    private const OPTION_CONFIRMATION_PAID_URL          = 'iftp_nf_confirmation_paid_url';
    private const OPTION_CONFIRMATION_SHOW_ENTRY_DATA   = 'iftp_nf_confirmation_show_entry_data';
    private const OPTION_CONFIRMATION_PAID_MESSAGE      = 'iftp_nf_confirmation_paid_message';
    private const OPTION_CONFIRMATION_PENDING_MESSAGE   = 'iftp_nf_confirmation_pending_message';
    private const OPTION_CONFIRMATION_FAILED_MESSAGE    = 'iftp_nf_confirmation_failed_message';
    private const OPTION_CONFIRMATION_CANCELLED_MESSAGE = 'iftp_nf_confirmation_cancelled_message';

    public const CONFIRMATION_TYPE_POPUP = 'popup';
    public const CONFIRMATION_TYPE_PAGE  = 'page';
    public const CONFIRMATION_TYPE_URL   = 'url';

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
     * Every Gateway Key on this Backoffice Key — an account can have more
     * than one, so the admin screen offers a dropdown instead of assuming.
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
        delete_option(self::OPTION_CONFIRMATION_PAID_TYPE);
        delete_option(self::OPTION_CONFIRMATION_PAID_PAGE_ID);
        delete_option(self::OPTION_CONFIRMATION_PAID_URL);
        delete_option(self::OPTION_CONFIRMATION_SHOW_ENTRY_DATA);
        delete_option(self::OPTION_CONFIRMATION_PAID_MESSAGE);
        delete_option(self::OPTION_CONFIRMATION_PENDING_MESSAGE);
        delete_option(self::OPTION_CONFIRMATION_FAILED_MESSAGE);
        delete_option(self::OPTION_CONFIRMATION_CANCELLED_MESSAGE);
    }

    public function get_paid_confirmation_type(): string
    {
        $type = (string) get_option(self::OPTION_CONFIRMATION_PAID_TYPE, self::CONFIRMATION_TYPE_POPUP);

        $allowed = [self::CONFIRMATION_TYPE_POPUP, self::CONFIRMATION_TYPE_PAGE, self::CONFIRMATION_TYPE_URL];

        return in_array($type, $allowed, true) ? $type : self::CONFIRMATION_TYPE_POPUP;
    }

    public function set_paid_confirmation_type(string $type): void
    {
        update_option(self::OPTION_CONFIRMATION_PAID_TYPE, $type);
    }

    public function get_paid_confirmation_page_id(): int
    {
        return (int) get_option(self::OPTION_CONFIRMATION_PAID_PAGE_ID, 0);
    }

    public function set_paid_confirmation_page_id(int $page_id): void
    {
        update_option(self::OPTION_CONFIRMATION_PAID_PAGE_ID, $page_id);
    }

    public function get_paid_confirmation_url(): string
    {
        return (string) get_option(self::OPTION_CONFIRMATION_PAID_URL, '');
    }

    public function set_paid_confirmation_url(string $url): void
    {
        update_option(self::OPTION_CONFIRMATION_PAID_URL, $url);
    }

    /**
     * Whether the paid popup should also reveal the customer's submitted
     * data. Only meaningful for the "popup" confirmation type — a
     * page/url redirect never shows a popup at all.
     */
    public function get_show_entry_data(): bool
    {
        return (bool) get_option(self::OPTION_CONFIRMATION_SHOW_ENTRY_DATA, false);
    }

    public function set_show_entry_data(bool $show): void
    {
        update_option(self::OPTION_CONFIRMATION_SHOW_ENTRY_DATA, $show);
    }

    /**
     * The redirect target for a "paid" confirmation set to a page or URL —
     * empty means show the popup instead of redirecting.
     */
    public function get_paid_redirect_url(): string
    {
        switch ($this->get_paid_confirmation_type()) {
            case self::CONFIRMATION_TYPE_PAGE:
                $page_id = $this->get_paid_confirmation_page_id();

                return $page_id > 0 ? (string) get_permalink($page_id) : '';
            case self::CONFIRMATION_TYPE_URL:
                return $this->get_paid_confirmation_url();
            default:
                return '';
        }
    }

    /**
     * The admin-configured popup message for one of the four customizable
     * statuses. Empty when not set — callers fall back to their own default,
     * since "expired" has no configurable message at all.
     */
    public function get_confirmation_message(string $status): string
    {
        $option = self::confirmation_message_option($status);

        return null !== $option ? (string) get_option($option, '') : '';
    }

    public function set_confirmation_message(string $status, string $message): void
    {
        $option = self::confirmation_message_option($status);

        if (null === $option) {
            return;
        }

        update_option($option, $message);
    }

    private static function confirmation_message_option(string $status): ?string
    {
        switch ($status) {
            case 'paid':
                return self::OPTION_CONFIRMATION_PAID_MESSAGE;
            case 'pending':
                return self::OPTION_CONFIRMATION_PENDING_MESSAGE;
            case 'failed':
                return self::OPTION_CONFIRMATION_FAILED_MESSAGE;
            case 'cancelled':
                return self::OPTION_CONFIRMATION_CANCELLED_MESSAGE;
            default:
                return null;
        }
    }
}

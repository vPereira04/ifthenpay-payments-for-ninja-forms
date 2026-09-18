<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\NinjaForms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provisions the hidden Ninja Forms form every "+ New Payment" entry is
 * filed under, so an ad hoc payment gets a real submission (real post ID,
 * "Edit Fields", the works) instead of living in a table of our own.
 *
 * Lazily created on first use and cached in a single option — cheaper than
 * re-querying Ninja Forms' tables on every request, and self-healing if the
 * form (or a field) is ever deleted out from under us.
 */
class AdHocForm
{
    private const OPTION = 'iftp_nf_adhoc_form';

    private const NAME_FIELD_KEY  = 'iftp_adhoc_name';
    private const EMAIL_FIELD_KEY = 'iftp_adhoc_email';

    /**
     * @var array{form_id: int, name_field_id: int, email_field_id: int}|null
     */
    private static ?array $cache = null;

    /**
     * 0 when Ninja Forms isn't available to provision against — callers
     * treat that as "can't create an ad hoc entry right now".
     */
    public static function form_id(): int
    {
        return self::ids()['form_id'];
    }

    public static function name_field_id(): int
    {
        return self::ids()['name_field_id'];
    }

    public static function email_field_id(): int
    {
        return self::ids()['email_field_id'];
    }

    /**
     * @return array{form_id: int, name_field_id: int, email_field_id: int}
     */
    private static function ids(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $stored = get_option(self::OPTION, []);

        if (is_array($stored) && self::is_valid($stored)) {
            return self::$cache = [
                'form_id'        => (int) $stored['form_id'],
                'name_field_id'  => (int) $stored['name_field_id'],
                'email_field_id' => (int) $stored['email_field_id'],
            ];
        }

        $ids = self::create();

        if (0 !== $ids['form_id']) {
            update_option(self::OPTION, $ids, false);
        }

        return self::$cache = $ids;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private static function is_valid(array $stored): bool
    {
        $form_id = (int) ($stored['form_id'] ?? 0);

        if ($form_id <= 0 || (int) ($stored['name_field_id'] ?? 0) <= 0 || (int) ($stored['email_field_id'] ?? 0) <= 0) {
            return false;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}nf3_forms WHERE id = %d",
            $form_id
        )) > 0;
    }

    /**
     * @return array{form_id: int, name_field_id: int, email_field_id: int}
     */
    private static function create(): array
    {
        $none = ['form_id' => 0, 'name_field_id' => 0, 'email_field_id' => 0];

        if (! function_exists('Ninja_Forms')) {
            return $none;
        }

        $form = Ninja_Forms()->form()->get();
        $form->update_setting('title', __('Ad Hoc Payments', 'ifthenpay-payments-for-ninja-forms'));
        $form->save();

        $form_id = $form->get_id();

        if ($form_id <= 0) {
            return $none;
        }

        $name_field_id  = self::create_field($form_id, 'firstname', self::NAME_FIELD_KEY, __('Name', 'ifthenpay-payments-for-ninja-forms'));
        $email_field_id = self::create_field($form_id, 'email', self::EMAIL_FIELD_KEY, __('Email', 'ifthenpay-payments-for-ninja-forms'));

        if ($name_field_id <= 0 || $email_field_id <= 0) {
            return $none;
        }

        return [
            'form_id'        => $form_id,
            'name_field_id'  => $name_field_id,
            'email_field_id' => $email_field_id,
        ];
    }

    private static function create_field(int $form_id, string $type, string $key, string $label): int
    {
        $field = Ninja_Forms()->form($form_id)->field()->get();
        $field->update_settings(['type' => $type, 'key' => $key, 'label' => $label]);
        $field->save();

        return $field->get_id();
    }
}

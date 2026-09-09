<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\NinjaForms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persists ifthenpay payment state independently of Ninja Forms' own PHP
 * session (which `nf_ajax_resume` relies on and which cannot be trusted to
 * survive the hours/days an offline method like Multibanco or Payshop can
 * take to actually get paid).
 *
 * One WP option per payment, keyed by our own reference. The option holds
 * everything needed to complete the submission later (`ResumeController`)
 * from the webhook alone, with no dependency on the customer's browser ever
 * coming back.
 */
class SubmissionStore
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    /**
     * Extra-value (post meta) keys written onto the real Ninja Forms
     * submission once it's created (see
     * `Api\Webhook\WebhookController::handle_success()`), for anyone
     * inspecting the submission directly. `Admin\EntriesPage` is the primary
     * place to check payment status — it doesn't depend on a submission
     * having been created at all, since a pending/failed/cancelled attempt
     * never gets one.
     */
    public const META_STATUS     = 'ifthenpay_status';
    public const META_REF        = 'ifthenpay_ref';
    public const META_AMOUNT     = 'ifthenpay_amount';
    public const META_PAY_METHOD = 'ifthenpay_pay_method';
    public const META_REQUEST_ID = 'ifthenpay_request_id';

    private const OPTION_PREFIX = 'iftp_nf_payment_';

    /**
     * @param array<string, mixed> $data The Ninja Forms `$data` array captured at
     *                                    halt time (fields, extra, processed_actions, ...).
     */
    public function store_pending(
        string $ref,
        int $form_id,
        array $data,
        float $amount,
        string $gateway_key
    ): void {
        $record = [
            'ref'          => $ref,
            'form_id'      => $form_id,
            'data'         => $data,
            'amount'       => $amount,
            'gateway_key'  => $gateway_key,
            'status'       => self::STATUS_PENDING,
            'pay_method'   => '',
            'request_id'   => '',
            'created_at'   => time(),
            'updated_at'   => time(),
        ];

        update_option(self::option_name($ref), $record, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $ref): ?array
    {
        $record = get_option(self::option_name($ref), null);

        return is_array($record) ? $record : null;
    }

    public function mark_paid(string $ref, string $pay_method, string $request_id): bool
    {
        return $this->update_status($ref, self::STATUS_PAID, [
            'pay_method' => $pay_method,
            'request_id' => $request_id,
        ]);
    }

    public function mark_failed(string $ref): bool
    {
        return $this->update_status($ref, self::STATUS_FAILED);
    }

    public function mark_cancelled(string $ref): bool
    {
        return $this->update_status($ref, self::STATUS_CANCELLED);
    }

    public function mark_expired(string $ref): bool
    {
        return $this->update_status($ref, self::STATUS_EXPIRED);
    }

    /**
     * Once paid, the record is terminal and is never moved to any other
     * status (Multibanco/Payshop references can still be confirmed after
     * being reported cancelled/expired, so only "paid" ever locks the door).
     *
     * @param array<string, mixed> $extra
     */
    private function update_status(string $ref, string $status, array $extra = []): bool
    {
        $record = $this->get($ref);

        if (null === $record) {
            return false;
        }

        if (self::STATUS_PAID === $record['status']) {
            return true;
        }

        $record = array_merge($record, $extra, [
            'status'     => $status,
            'updated_at' => time(),
        ]);

        update_option(self::option_name($ref), $record, false);

        return true;
    }

    /**
     * Every payment reference ever recorded, regardless of status —
     * options are already indexed by `option_name` in the DB, so this scans
     * that rather than maintaining a separate index that a bug (or a future
     * change) could silently drop entries from. Used by `Admin\EntriesPage`
     * and `Cron\ExpiredPaymentsCron`.
     *
     * @return array<int, string>
     */
    public function get_all_refs(): array
    {
        global $wpdb;

        $prefix = self::OPTION_PREFIX;
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );

        return array_map(static fn (string $name): string => substr($name, strlen($prefix)), $names);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_all(): array
    {
        $records = array_filter(array_map([$this, 'get'], $this->get_all_refs()));

        return array_values($records);
    }

    /**
     * Builds the extra-value payload to merge into `$data['extra']` before
     * replaying the form's remaining actions, so `Save` persists these as
     * real post meta on the submission it creates.
     *
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    public function submission_extra_values(array $record): array
    {
        return [
            self::META_STATUS     => (string) $record['status'],
            self::META_REF        => (string) $record['ref'],
            self::META_AMOUNT     => number_format((float) $record['amount'], 2, '.', ''),
            self::META_PAY_METHOD => (string) $record['pay_method'],
            self::META_REQUEST_ID => (string) $record['request_id'],
        ];
    }

    private static function option_name(string $ref): string
    {
        return self::OPTION_PREFIX . $ref;
    }
}

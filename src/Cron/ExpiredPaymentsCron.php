<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Cron;

use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Daily sweep marking still-pending payments as expired once they're older
 * than the configured Expiry Days. An expired Multibanco/Payshop reference
 * can still be paid later — expiry is a UI/reporting label, not a lock;
 * SubmissionStore::mark_expired() only ever moves a record away from
 * "pending", and a later webhook can still mark it paid.
 */
class ExpiredPaymentsCron
{
    public const HOOK = 'iftp_nf_expire_payments';

    private SettingsRepository $settings;
    private SubmissionStore $submissions;

    public function __construct(?SettingsRepository $settings = null, ?SubmissionStore $submissions = null)
    {
        $this->settings    = $settings ?? new SettingsRepository();
        $this->submissions = $submissions ?? new SubmissionStore();
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        if (false !== $timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run(): void
    {
        $expiry_seconds = $this->settings->get_expiry_days() * DAY_IN_SECONDS;
        $cutoff = time() - $expiry_seconds;

        foreach ($this->submissions->get_all_refs() as $ref) {
            $record = $this->submissions->get($ref);

            if (null === $record || SubmissionStore::STATUS_PENDING !== $record['status']) {
                continue;
            }

            if ((int) $record['created_at'] < $cutoff) {
                $this->submissions->mark_expired($ref);
            }
        }
    }
}

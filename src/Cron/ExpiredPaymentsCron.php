<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Cron;

use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Daily sweep that flags payments as expired once they're past the
 * configured Expiry Days. I treat "expired" as just a display label — an
 * old Multibanco/Payshop reference can still get paid later.
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
        $cutoff = time() - $this->settings->get_expiry_days() * DAY_IN_SECONDS;

        foreach ($this->submissions->pending_refs_before($cutoff) as $ref) {
            // The option row is the source of truth, so I re-check it before
            // trusting the index.
            $record = $this->submissions->get($ref);

            if (null !== $record && SubmissionStore::STATUS_PENDING === $record['status']) {
                $this->submissions->mark_expired($ref);
            }
        }
    }
}

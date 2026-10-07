<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Gateway;

use Ifthenpay\NinjaForms\Api\IfthenpayClient;
use Ifthenpay\NinjaForms\Api\IfthenpayPayload;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;
use NF_Abstracts_PaymentGateway;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ifthenpay Gateway (Pay by Link), registered into Ninja Forms'
 * "Collect Payment" action.
 *
 * All config is global, not per-form, so `$_settings` stays empty here —
 * Collect Payment just gets an "ifthenpay" option in its gateway dropdown.
 */
class IfthenpayGateway extends NF_Abstracts_PaymentGateway
{
    public const SLUG = 'ifthenpay';

    /**
     * Values I write into (and later read back from) the `ARG_STATUS`
     * return-URL param. Named constants so the writer and reader can't
     * silently drift apart.
     *
     * Deliberately its own vocabulary — don't confuse these with the
     * webhook's own status values, they're a different contract.
     */
    public const RETURN_STATUS_SUCCESS = 'success';
    public const RETURN_STATUS_ERROR   = 'error';
    public const RETURN_STATUS_CANCEL  = 'cancel';

    /**
     * Our return-URL params. Prefixed, since the customer lands back on
     * any page of the site and a bare `ref` gets read as a referral by
     * affiliate plugins.
     */
    public const ARG_STATUS = 'iftp_nf_pay';
    public const ARG_REF    = 'iftp_nf_ref';
    public const ARG_KEY    = 'iftp_nf_key';
    public const ARG_TXN    = 'iftp_nf_txn';

    private SettingsRepository $settings;
    private IfthenpayClient $client;
    private SubmissionStore $submissions;

    public function __construct(
        ?SettingsRepository $settings = null,
        ?IfthenpayClient $client = null,
        ?SubmissionStore $submissions = null
    ) {
        $this->_slug     = self::SLUG;
        $this->_name     = 'ifthenpay | Payment Gateway';
        $this->_settings = [];

        $this->settings    = $settings ?? new SettingsRepository();
        $this->client       = $client ?? new IfthenpayClient();
        $this->submissions = $submissions ?? new SubmissionStore();

        parent::__construct();
    }

    /**
     * @param array<string, mixed> $action_settings
     * @param int|string           $form_id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function process($action_settings, $form_id, $data)
    {
        // Preview contexts (the builder's preview button, and Ninja Forms'
        // own Gutenberg block, which always renders as preview) never create
        // a real submission, so I can't build a real reference for them —
        // I run these through as an obviously-marked test instead.
        $is_test = ! empty($data['settings']['is_preview']);

        if (! $this->settings->is_connected() || '' === $this->settings->get_gateway_key()) {
            return $this->fail($data, __('ifthenpay is not configured yet. Please contact the site administrator.', 'ifthenpay-payments-for-ninja-forms'));
        }

        $enabled_methods = $this->settings->get_enabled_methods();

        if ([] === $enabled_methods) {
            return $this->fail($data, __('No ifthenpay payment methods are enabled yet. Please contact the site administrator.', 'ifthenpay-payments-for-ninja-forms'));
        }

        $amount = (float) ($action_settings['payment_total'] ?? 0);

        if ($amount <= 0) {
            return $this->fail($data, __('Invalid payment amount.', 'ifthenpay-payments-for-ninja-forms'));
        }

        $data['processed_actions'][] = $action_settings['id'];
        $sub_id = null;

        if ($is_test) {
            $ref = $this->generate_test_reference();
        } else {
            // Reserving the submission now so I can build the reference from
            // its real ID instead of a random string.
            $data = $this->reserve_submission((int) $form_id, $data);
            $sub_id = $data['actions']['save']['sub_id'] ?? null;

            $ref = $this->generate_reference((int) $form_id, is_int($sub_id) ? $sub_id : null);
        }

        $gateway_key = $this->settings->get_gateway_key();
        $return_base_url = $this->resolve_return_base_url();

        $payload = IfthenpayPayload::build_payment_payload(
            $ref,
            $amount,
            $this->settings->get_description(),
            $enabled_methods,
            $this->build_return_url(self::RETURN_STATUS_SUCCESS, $ref, $return_base_url),
            $this->build_return_url(self::RETURN_STATUS_ERROR, $ref, $return_base_url),
            $this->build_return_url(self::RETURN_STATUS_CANCEL, $ref, $return_base_url),
            IfthenpayPayload::locale_to_lang(get_locale()),
            $this->settings->get_default_method_position()
        );

        $result = $this->client->create_payment_link($gateway_key, $payload);

        if (false === $result) {
            // Logging this unconditionally — a failed payment start matters
            // even on sites that don't run with WP_DEBUG on.
            error_log('ifthenpay Payments for Ninja Forms: create_payment_link failed - ' . $this->client->get_last_error());

            // The submission was only reserved to mint this reference — undo
            // it so a payment that never started doesn't leave one behind.
            if (is_int($sub_id)) {
                $this->delete_submission((int) $form_id, $sub_id);
            }

            return $this->fail($data, __('Unable to start the ifthenpay payment. Please try again.', 'ifthenpay-payments-for-ninja-forms'));
        }

        $this->submissions->store_pending($ref, (int) $form_id, $data, $amount, $gateway_key, (string) $result['RedirectUrl'], $is_test);

        $data['actions']['redirect'] = $result['RedirectUrl'];
        $data['halt'] = true;

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function fail(array $data, string $message): array
    {
        $data['errors']['form'][] = $message;

        return $data;
    }

    /**
     * I use the real submission's post ID as the reference whenever one
     * exists, so the same ID also opens the submission directly in the
     * admin. That makes references guessable (sequential), so the return
     * page only trusts a ref that comes with its `return_key()`, and the
     * webhook is authenticated by gateway key + amount, not secrecy.
     *
     * Falls back to a random reference when there's no submission ID to
     * build from.
     */
    private function generate_reference(int $form_id, ?int $sub_id): string
    {
        if (null !== $sub_id) {
            return (string) $sub_id;
        }

        return $form_id . '_' . wp_generate_password(12, false, false);
    }

    /**
     * The secret that proves a return URL is the one we built for `$ref`.
     * Refs are sequential, so without it anyone could walk them and read
     * other customers' payment popups (and their entry data).
     */
    public static function return_key(string $ref): string
    {
        return substr(hash_hmac('sha256', 'iftp_nf_return|' . $ref, wp_salt('auth')), 0, 32);
    }

    public static function is_valid_return_key(string $ref, string $key): bool
    {
        return '' !== $ref && '' !== $key && hash_equals(self::return_key($ref), $key);
    }

    /**
     * A `TEST_n` reference for preview/test payments — obviously distinct
     * from a real one at a glance, wherever it shows up. `n` is just an
     * incrementing counter to tell separate attempts apart.
     */
    private function generate_test_reference(): string
    {
        $next = (int) get_option('iftp_nf_test_ref_seq', 0) + 1;

        update_option('iftp_nf_test_ref_seq', $next, false);

        return 'TEST_' . $next;
    }

    /**
     * I create the real submission right away, instead of waiting for
     * payment confirmation, so its ID is stable before the payment link is
     * even requested — `generate_reference()` builds off it, and a failed
     * `create_payment_link()` call undoes this via `delete_submission()`.
     *
     * In practice Ninja Forms' own action loop already runs "save" before
     * we get here (it sorts by priority within the same timing group), so
     * this only actually mints a submission itself in the rare case that
     * didn't happen — re-running "save" unconditionally used to create a
     * duplicate submission on every payment, which is why the early return
     * below exists.
     *
     * I run "save" even if the form owner turned "Save Submissions" off —
     * the reference needs a real ID, not a random string.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function reserve_submission(int $form_id, array $data): array
    {
        $existing_sub_id = $data['actions']['save']['sub_id'] ?? null;

        if (null !== $existing_sub_id) {
            if (! isset($data['actions']['save']['seq_num'])) {
                $data['actions']['save']['seq_num'] = (int) get_post_meta((int) $existing_sub_id, '_seq_num', true);
            }

            return $data;
        }

        if (! function_exists('Ninja_Forms')) {
            return $data;
        }

        $save_action = Ninja_Forms()->actions['save'] ?? null;

        if (! is_object($save_action) || ! method_exists($save_action, 'process')) {
            return $data;
        }

        $save_settings = null;

        foreach (Ninja_Forms()->form($form_id)->get_actions() as $action) {
            $settings = $action->get_settings();

            if ('save' === ($settings['type'] ?? '')) {
                $save_settings       = $settings;
                $save_settings['id'] = $action->get_id();
                break;
            }
        }

        if (null === $save_settings) {
            // No "save" action on this form at all — the only case the random
            // reference fallback is meant for.
            error_log(sprintf('ifthenpay Payments for Ninja Forms: form #%d has no "save" action, falling back to a random reference', $form_id));

            return $data;
        }

        $data = $save_action->process($save_settings, $form_id, $data);
        $data['processed_actions'][] = $save_settings['id'];

        $sub_id = $data['actions']['save']['sub_id'] ?? null;

        if (null === $sub_id) {
            // Logging this: "save" ran but returned no sub_id, so the
            // reference will fall back to a random one — likely another
            // plugin's filter rejected the save.
            error_log(sprintf('ifthenpay Payments for Ninja Forms: "save" action on form #%d ran but produced no sub_id', $form_id));
        } else {
            // Backfilling seq_num too — it's just an extra detail shown
            // in the entries admin, already in post meta by now.
            $data['actions']['save']['seq_num'] = (int) get_post_meta((int) $sub_id, '_seq_num', true);
        }

        return $data;
    }

    /**
     * Cleans up after a failed `create_payment_link()` — the submission was
     * only created to mint a reference, so I don't want it lingering
     * alongside genuine attempts.
     */
    private function delete_submission(int $form_id, int $sub_id): void
    {
        if (! function_exists('Ninja_Forms')) {
            return;
        }

        Ninja_Forms()->form($form_id)->sub($sub_id)->get()->delete();
    }

    /**
     * I use the `Referer` header (host-validated by `wp_get_referer()`) to
     * find the page the form was embedded on — the AJAX submission itself
     * never carries that URL. Falls back to the home URL if it's missing.
     *
     * I strip out our own and ifthenpay's leftover query params first,
     * since the referer can still carry them from a previous attempt and
     * they'd otherwise keep piling up on every return URL after that.
     */
    private function resolve_return_base_url(): string
    {
        $referer  = wp_get_referer();
        $base_url = false !== $referer && '' !== $referer ? $referer : home_url('/');

        return remove_query_arg(
            [self::ARG_STATUS, self::ARG_REF, self::ARG_KEY, self::ARG_TXN, 'id', 'amount', 'requestId', 'sk', 'brand', 'pan', 'lang'],
            $base_url
        );
    }

    /**
     * The success URL also carries a `[TRANSACTIONID]` placeholder that
     * ifthenpay fills in — only on success, since error/cancel never have a
     * transaction to look up. Lets us resolve the payment right away
     * instead of waiting on the async webhook.
     */
    private function build_return_url(string $status, string $ref, string $base_url): string
    {
        $args = [
            self::ARG_STATUS => $status,
            self::ARG_REF    => $ref,
            self::ARG_KEY    => self::return_key($ref),
        ];

        if (self::RETURN_STATUS_SUCCESS === $status) {
            $args[self::ARG_TXN] = '[TRANSACTIONID]';
        }

        return add_query_arg($args, $base_url);
    }
}

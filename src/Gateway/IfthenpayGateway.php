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
 * The ifthenpay Gateway (Pay by Link), registered into Ninja Forms' built-in
 * "Collect Payment" action via the `ninja_forms_register_payment_gateways`
 * filter.
 *
 * All configuration (Backoffice Key, Gateway Key, methods, default method,
 * description, expiry days) is global — see `SettingsRepository` — so this
 * class declares no gateway-specific settings of its own; `$_settings` stays
 * empty and Collect Payment only gains the "ifthenpay" option in its own
 * gateway dropdown.
 */
class IfthenpayGateway extends NF_Abstracts_PaymentGateway
{
    public const SLUG = 'ifthenpay';

    /**
     * Values written into (and later read back from) the `iftp_nf_pay`
     * return-URL query param that ifthenpay's hosted payment page redirects
     * the browser back to (see `build_return_url()`), consumed by
     * `Plugin::resolve_display_status()`. Kept as named constants, shared
     * between the writer and the reader, so the two can never silently drift
     * apart the way bare string literals could.
     *
     * Deliberately a separate vocabulary from `Api\Webhook\WebhookPayload`'s
     * `status` values (`cancelled`/`error`), which belong to a different,
     * ifthenpay-defined contract for the async server-to-server webhook —
     * the two must never be conflated.
     */
    public const RETURN_STATUS_SUCCESS = 'success';
    public const RETURN_STATUS_ERROR   = 'error';
    public const RETURN_STATUS_CANCEL  = 'cancel';

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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function process($action_settings, $form_id, $data)
    {
        // The Form Builder's own "Preview" button (`NF_Display_Render::localize_preview()`)
        // — and Ninja Forms' own Gutenberg block, which always renders this
        // way regardless of context, a Ninja Forms core bug (see
        // `Plugin::maybe_block_broken_ninja_forms_block()`, which stops the
        // block from being used at all) — flags every submission
        // `is_preview`, so Ninja Forms' own "Record Submission" action never
        // writes a real submission for it. There's nothing for
        // `reserve_submission()` to build a real reference from, so this
        // skips it entirely rather than let it fail every time, and runs the
        // payment through as an obviously-marked test instead of refusing
        // outright: a `TEST_n` reference (`generate_test_reference()`), and
        // `SubmissionStore::store_pending()`'s `$is_test` flag so
        // `Admin\EntriesPage` never confuses it for a real customer payment.
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
            // Reserved before the reference is even generated (not just
            // before the redirect, as before) so that reference can be built
            // from the real submission ID instead of a disconnected random
            // string — see `generate_reference()`.
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
            // Always logged, not gated behind WP_DEBUG: a failed payment start
            // is an operational signal, not a routine debug trace, and many
            // sites that need to see this never run with WP_DEBUG on.
            error_log('ifthenpay Payments for Ninja Forms: create_payment_link failed - ' . $this->client->get_last_error());

            // The submission above was already reserved (and, with it, its
            // real ID spent) purely to mint this reference — undo it rather
            // than leaving a real Ninja Forms submission behind for a
            // payment that never actually started.
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
     * Hands ifthenpay something to identify the payment by, and lets us look
     * up the pending record when its webhook calls back. Built from the real
     * Ninja Forms submission's WP post ID (`sub_id`) whenever one was
     * reserved (see `reserve_submission()`) — at Victor's request, so
     * `Admin\EntriesPage` and ifthenpay's own backoffice key off the same
     * post ID used to open the submission directly (`post.php?post={sub_id}`),
     * rather than Ninja Forms' own per-form "Submission ID" (`_seq_num`),
     * which only means something inside Ninja Forms' own Submissions screen.
     * Deliberate tradeoff: this makes the reference guessable (sequential),
     * so anyone who knows the URL shape could probe another order's generic
     * pending/paid/failed status via the return-banner query param
     * (`Plugin::maybe_enqueue_return_banner()`) — no amount, fields, or
     * payment method are exposed that way, and the webhook itself is
     * authenticated by `gateway_key` + amount (`WebhookValidator`), not by
     * the reference being secret.
     *
     * Falls back to the old random form-scoped reference only for the rare
     * form with no "save" action attached at all, where no submission ID
     * exists to build one from.
     */
    private function generate_reference(int $form_id, ?int $sub_id): string
    {
        if (null !== $sub_id) {
            return (string) $sub_id;
        }

        return $form_id . '_' . wp_generate_password(12, false, false);
    }

    /**
     * A `TEST_n` reference for a payment started from a context Ninja Forms
     * itself flags `is_preview` (see `process()`) — obviously distinct from
     * a real customer reference at a glance, both in `Admin\EntriesPage` and
     * in ifthenpay's own backoffice. `n` is a simple incrementing counter,
     * not tied to any real submission (there never is one for these) — just
     * enough to tell separate test attempts apart.
     */
    private function generate_test_reference(): string
    {
        $next = (int) get_option('iftp_nf_test_ref_seq', 0) + 1;

        update_option('iftp_nf_test_ref_seq', $next, false);

        return 'TEST_' . $next;
    }

    /**
     * Creates the real Ninja Forms submission right away — instead of
     * waiting for payment confirmation like every other action does (see
     * `NinjaForms\ResumeController`) — so its numeric ID (the same one
     * Ninja Forms' own Submissions screen shows) is knowable and stable
     * before the payment link is even requested, not just if/once the
     * payment is ever confirmed. That ID is what `generate_reference()`
     * builds ifthenpay's own order reference from, so a failed
     * `create_payment_link()` call must undo this reservation (see
     * `delete_submission()`) rather than leave a real submission behind for
     * a payment that never started. Recording the "save" action's id in
     * `$data['processed_actions']` here is what makes `ResumeController`
     * skip re-running it once the webhook lands; `SubmissionStore` keeps
     * this same submission's post meta in sync on every later status
     * change instead of Ninja Forms' own Save action doing it.
     *
     * In practice, Ninja Forms' own action loop (`NF_AJAX_Controllers_Submission::process()`)
     * already runs "save" before this method ever gets a chance to: both
     * "save" and "collectpayment" are `late`-timing actions, but "save" has
     * priority `-1` against "collectpayment"'s `0`, and that loop sorts
     * ascending by priority within a timing group — so by the time our
     * gateway's own `process()` runs (as "collectpayment"), `$data` already
     * carries `actions.save.sub_id` from that natural run. Re-running
     * `process()` here regardless, as this method used to, created a
     * *second* real submission for every single payment — the fix is the
     * early return below, which only lets this method mint a submission
     * itself in the rare case Ninja Forms' own loop didn't already.
     *
     * Runs the "save" action's `process()` even if the form owner switched
     * "Save Submissions" off in the builder — Victor wants ifthenpay's
     * reference and `Admin\EntriesPage` to always key off the real Ninja
     * Forms ID, never a disconnected random string, so a submission is
     * always minted here regardless of that toggle. `process()` itself
     * doesn't look at `active`, only at field/extra-value settings, so this
     * is safe. `ResumeController` later sees this action's id already in
     * `processed_actions` and skips it, whether or not it's active.
     *
     * No-op — and no ID reserved, so the reference falls back to a random
     * one and `Admin\EntriesPage` shows "—" — only if the form has no
     * "save" action attached at all.
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

        if (null === $save_action || ! method_exists($save_action, 'process')) {
            return $data;
        }

        foreach (Ninja_Forms()->form($form_id)->get_actions() as $action) {
            $settings = $action->get_settings();

            if ('save' !== ($settings['type'] ?? '')) {
                continue;
            }

            $settings['id'] = $action->get_id();

            $data = $save_action->process($settings, $form_id, $data);
            $data['processed_actions'][] = $settings['id'];

            $sub_id = $data['actions']['save']['sub_id'] ?? null;

            if (null === $sub_id) {
                // Logged, not silently swallowed: `process()` ran but returned
                // no sub_id, so `generate_reference()` is about to fall back
                // to a random reference for this attempt. The `is_preview`
                // case is now caught earlier in `process()`, before this can
                // even be reached — so reaching here means some other
                // plugin/add-on's `ninja_forms_save_submission` filter
                // returned false for this form.
                error_log(sprintf('ifthenpay Payments for Ninja Forms: "save" action on form #%d ran but produced no sub_id', $form_id));
            } else {
                // `sub_id` (the WP post ID) is what `generate_reference()`
                // and `Admin\EntriesPage`'s ID column key off, at Victor's
                // request. `_seq_num` — what Ninja Forms itself calls the
                // "Submission ID" (`Admin\Menus\Submissions`'s "#" column), a
                // per-form sequence — is only needed as an extra detail shown
                // in `Admin\EntriesPage`'s entry-details panel, so it's still
                // backfilled here too. Set synchronously inside `$sub->save()`
                // above (`NF_Database_Models_Submission::save()`), so it's
                // already in post meta by the time we read it back here.
                $data['actions']['save']['seq_num'] = (int) get_post_meta((int) $sub_id, '_seq_num', true);
            }

            return $data;
        }

        // No "save" action attached to this form at all — the only case
        // `generate_reference()`'s random fallback is meant for.
        error_log(sprintf('ifthenpay Payments for Ninja Forms: form #%d has no "save" action, falling back to a random reference', $form_id));

        return $data;
    }

    /**
     * Undoes `reserve_submission()` when `create_payment_link()` fails right
     * after it — a real Ninja Forms submission was only ever created to mint
     * a stable ID for `generate_reference()`, so one left behind for a
     * payment that never actually started would misleadingly show up
     * alongside genuine attempts in Ninja Forms' own Submissions screen.
     */
    private function delete_submission(int $form_id, int $sub_id): void
    {
        if (! function_exists('Ninja_Forms')) {
            return;
        }

        Ninja_Forms()->form($form_id)->sub($sub_id)->get()->delete();
    }

    /**
     * The page ifthenpay's hosted payment page redirects back to once the
     * customer finishes (or abandons) paying. `NF_Abstracts_PaymentGateway::process()`
     * runs as part of Ninja Forms' own `nf_ajax_submit` handler, so `$data`
     * carries submitted field/action state but never the URL of the page the
     * form itself was embedded on; the browser's `Referer` header for that
     * same AJAX request is the only reliable signal available for it,
     * captured here (via `wp_get_referer()`, which validates the host)
     * rather than trusting `$_SERVER['HTTP_REFERER']` directly. Falls back
     * to the site's home URL if the header is missing or fails validation
     * (e.g. a referrer policy stripped it), matching prior behaviour.
     *
     * Stripped of every return/verification query param before use: the
     * referer is the customer's *current* address bar, which — until
     * `assets/js/frontend.js`'s `stripReturnParamsFromUrl()` has had a
     * chance to run — can still carry either our own `iftp_nf_pay`/`ref`
     * from a previous attempt, or ifthenpay's own hosted card-payment page
     * decorating that same previous return with `id`/`amount`/`requestId`/
     * `sk`/`brand`/`pan`. Left in, `build_return_url()`'s `add_query_arg()`
     * only overwrites the two keys it sets — it would carry every leftover
     * param forward into this attempt's return URL too, and again into
     * every attempt after that.
     */
    private function resolve_return_base_url(): string
    {
        $referer  = wp_get_referer();
        $base_url = false !== $referer && '' !== $referer ? $referer : home_url('/');

        return remove_query_arg(
            ['iftp_nf_pay', 'ref', 'transaction_id', 'id', 'amount', 'requestId', 'sk', 'brand', 'pan', 'lang'],
            $base_url
        );
    }

    /**
     * The success URL alone also carries a `[TRANSACTIONID]` placeholder,
     * which ifthenpay fills in only on a genuine success return — never on
     * error/cancel, since those never carry a transaction to look up.
     * `Plugin::maybe_enqueue_return_banner()` reads it back and hands it to
     * `Ajax\FrontendController::verify_payment()`, which resolves the payment
     * immediately via `Api\Webhook\WebhookController::confirm_via_transaction_status()`
     * instead of waiting on the asynchronous webhook.
     */
    private function build_return_url(string $status, string $ref, string $base_url): string
    {
        $args = [
            'iftp_nf_pay' => $status,
            'ref'         => $ref,
        ];

        if (self::RETURN_STATUS_SUCCESS === $status) {
            $args['transaction_id'] = '[TRANSACTIONID]';
        }

        return add_query_arg($args, $base_url);
    }
}

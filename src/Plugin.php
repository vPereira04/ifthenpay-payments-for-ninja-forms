<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms;

use Ifthenpay\NinjaForms\Admin\ConfirmationPage;
use Ifthenpay\NinjaForms\Admin\EntriesPage;
use Ifthenpay\NinjaForms\Admin\SettingsPage;
use Ifthenpay\NinjaForms\Ajax\Controller as AjaxController;
use Ifthenpay\NinjaForms\Ajax\FrontendController;
use Ifthenpay\NinjaForms\Api\Webhook\WebhookController;
use Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron;
use Ifthenpay\NinjaForms\Gateway\IfthenpayGateway;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin bootstrap: wires every piece together once, on `ninja_forms_loaded`.
 */
class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        add_action('ninja_forms_loaded', [$this, 'register_gateway'], -5);

        (new SettingsPage())->register();
        (new ConfirmationPage())->register();
        (new EntriesPage())->register();
        (new AjaxController())->register();
        (new FrontendController())->register();
        (new WebhookController())->register();
        (new ExpiredPaymentsCron())->register();

        add_action('template_redirect', [$this, 'maybe_redirect_paid_confirmation']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_return_banner']);
        add_action('wp_footer', [$this, 'maybe_enqueue_pay_by_link_spinner'], 1);
        add_filter('render_block_ninja-forms/form', [$this, 'maybe_block_broken_gutenberg_block'], 10, 2);
    }

    /**
     * Hooked at priority -5 on `ninja_forms_loaded`, so our `add_filter`
     * call below is in place before CollectPayment's own handler on the
     * same action (priority -1) applies the filter.
     */
    public function register_gateway(): void
    {
        add_filter('ninja_forms_register_payment_gateways', static function (array $gateways): array {
            $gateways[IfthenpayGateway::SLUG] = new IfthenpayGateway();

            return $gateways;
        });
    }

    /**
     * Parses and resolves this request's return-banner context — shared by
     * `maybe_redirect_paid_confirmation()` and `maybe_enqueue_return_banner()`
     * so both agree on exactly the same resolved status/record for a single
     * request instead of parsing `$_GET` and re-fetching the record twice.
     *
     * @return array{ref: string, record: array<string, mixed>, query_status: string, status: string}|null
     */
    private function resolve_return_context(): ?array
    {
        $query_status = sanitize_text_field(wp_unslash($_GET['iftp_nf_pay'] ?? ''));
        $ref          = sanitize_text_field(wp_unslash($_GET['ref'] ?? ''));

        if ('' === $query_status || '' === $ref) {
            return null;
        }

        $record = (new SubmissionStore())->get($ref);

        if (null === $record) {
            return null;
        }

        return [
            'ref'          => $ref,
            'record'       => $record,
            'query_status' => $query_status,
            'status'       => self::resolve_display_status((string) $record['status'], $query_status),
        ];
    }

    /**
     * Redirects straight to the configured "paid" destination (see
     * `Admin\ConfirmationPage`) the instant a "paid" status is already
     * resolved by the time the return page loads — before any theme output
     * starts, so `wp_safe_redirect()` can still send a `Location` header.
     * Hooked at `template_redirect`, earlier than
     * `maybe_enqueue_return_banner()`'s `wp_enqueue_scripts`, for exactly
     * that reason. A "paid" confirmation left as (or falling back to) a
     * popup has no redirect target, so this is a no-op then — the popup
     * takes over on `maybe_enqueue_return_banner()` as usual, and a payment
     * that only resolves to "paid" later (after this page has already
     * rendered) redirects instead from `assets/js/frontend.js` once its
     * live poll catches up.
     */
    public function maybe_redirect_paid_confirmation(): void
    {
        $context = $this->resolve_return_context();

        if (null === $context || SubmissionStore::STATUS_PAID !== $context['status']) {
            return;
        }

        $redirect_url = (new SettingsRepository())->get_paid_redirect_url();

        if ('' === $redirect_url) {
            return;
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function maybe_enqueue_return_banner(): void
    {
        $context = $this->resolve_return_context();

        if (null === $context) {
            return;
        }

        $record       = $context['record'];
        $query_status = $context['query_status'];
        $status       = $context['status'];
        $settings     = new SettingsRepository();

        wp_enqueue_style('iftp-nf-frontend', IFTP_NF_URL . 'assets/css/frontend.css', [], IFTP_NF_VERSION);
        wp_enqueue_script('iftp-nf-frontend', IFTP_NF_URL . 'assets/js/frontend.js', [], IFTP_NF_VERSION, true);

        // Only ever present on a genuine success return (see
        // `Gateway\IfthenpayGateway::build_return_url()`'s `[TRANSACTIONID]`
        // placeholder) — handed to `assets/js/frontend.js` so it can ask
        // `Ajax\FrontendController::verify_payment()` to resolve the payment
        // immediately via `Api\Webhook\WebhookController::confirm_via_transaction_status()`
        // instead of only ever waiting on the asynchronous webhook.
        $transaction_id = IfthenpayGateway::RETURN_STATUS_SUCCESS === $query_status
            ? sanitize_text_field(wp_unslash($_GET['transaction_id'] ?? ''))
            : '';

        // Only ever populated once this specific request already resolved
        // "paid" (never speculatively) — the "Show Entry Data" checkbox
        // (`Admin\ConfirmationPage`) is meaningless before that, and nothing
        // about this customer's submission should reach the browser before
        // their payment is actually confirmed.
        $entry_data = SubmissionStore::STATUS_PAID === $status && $settings->get_show_entry_data()
            ? (new SubmissionStore())->entry_data_pairs($record)
            : [];

        wp_localize_script('iftp-nf-frontend', 'iftpNfReturn', [
            'status'          => $status,
            'message'         => self::status_message($status),
            'ref'             => $context['ref'],
            'formId'          => (int) $record['form_id'],
            'queryStatus'     => $query_status,
            'transactionId'   => $transaction_id,
            // Resolved once, here, from the same settings a "paid at load"
            // request would already have been redirected by
            // (`maybe_redirect_paid_confirmation()`) — only ever actually
            // used by `assets/js/frontend.js` if this payment is still
            // unresolved right now and later resolves to "paid" during its
            // live poll, since a "paid" confirmation type change doesn't
            // itself apply retroactively mid-poll.
            'paidRedirectUrl' => $settings->get_paid_redirect_url(),
            'entryData'       => $entry_data,
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce(FrontendController::NONCE_ACTION),
            'okLabel'         => __('OK', 'ifthenpay-payments-for-ninja-forms'),
            'closeLabel'      => __('Close', 'ifthenpay-payments-for-ninja-forms'),
            'confirmingLabel' => __('Confirming your payment…', 'ifthenpay-payments-for-ninja-forms'),
        ]);
    }

    /**
     * Enqueues a small spinner overlay on any page a Ninja Forms form is
     * actually rendered on (detected via NF core's own `nf-front-end`
     * script handle, so this never loads on pages without a form), so that
     * the moment `IfthenpayGateway::process()` hands the browser a
     * `redirect` action, the user sees feedback instead of a dead page
     * while the browser finishes navigating to the payment link.
     *
     * Hooked at `wp_footer` priority 1 — before core's `wp_print_footer_scripts`
     * (priority 20) — because Ninja Forms only calls `wp_enqueue_script()` for
     * `nf-front-end` while rendering the form itself (`Display/Render.php`),
     * which happens earlier in the page during `the_content`/shortcode
     * rendering, not during the `wp_enqueue_scripts` action. By the time
     * `wp_footer` fires, that enqueue call has already happened if a form was
     * on the page.
     */
    public function maybe_enqueue_pay_by_link_spinner(): void
    {
        if (! wp_script_is('nf-front-end', 'enqueued') && ! wp_script_is('nf-front-end', 'done')) {
            return;
        }

        wp_enqueue_style('iftp-nf-frontend', IFTP_NF_URL . 'assets/css/frontend.css', [], IFTP_NF_VERSION);
        wp_enqueue_script(
            'iftp-nf-pay-by-link',
            IFTP_NF_URL . 'assets/js/pay-by-link.js',
            ['jquery', 'nf-front-end'],
            IFTP_NF_VERSION,
            true
        );

        wp_localize_script('iftp-nf-pay-by-link', 'iftpNfPayByLink', [
            'redirectingLabel' => __('Redirecting to secure payment…', 'ifthenpay-payments-for-ninja-forms'),
        ]);
    }

    /**
     * Ninja Forms' own Gutenberg block (`ninja-forms/form`) hard-codes
     * `$preview = true` in its `render_callback` (`ninja-forms/blocks/bootstrap.php`)
     * regardless of whether it's actually rendering in the editor or on a
     * published page — a Ninja Forms core bug, not anything this plugin can
     * fix from the outside. That flag makes Ninja Forms' own "Record
     * Submission" action refuse to ever create a real submission for a form
     * embedded this way (see `Gateway\IfthenpayGateway::process()`'s
     * `$is_test` handling), so a form that takes payment can never actually
     * work through this block — no matter what this plugin does.
     *
     * Rather than let a customer reach a real payment link for a submission
     * that was never going to be recorded, this replaces the block's output
     * outright for any form with the ifthenpay gateway selected, on every
     * render (editor preview and live page alike, since both go through
     * `render_block_{$name}`) — with an actionable notice for anyone who can
     * edit the page, or nothing at all for a regular visitor.
     */
    public function maybe_block_broken_gutenberg_block(string $block_content, array $block): string
    {
        $form_id = (int) ($block['attrs']['formID'] ?? 0);

        if ($form_id <= 0 || ! $this->form_uses_ifthenpay($form_id)) {
            return $block_content;
        }

        if (! current_user_can('edit_posts')) {
            return '';
        }

        $message = sprintf(
            /* translators: %d is the Ninja Forms form ID. */
            __('This form takes ifthenpay payments. The Ninja Forms Gutenberg block cannot process them correctly — it always renders as a Preview, a known Ninja Forms core issue — so it has been disabled here. Please replace this block with the shortcode [ninja_form id="%d"] instead.', 'ifthenpay-payments-for-ninja-forms'),
            $form_id
        );

        return '<div class="notice notice-error" style="padding: 1em; margin: 1em 0;">' . esc_html($message) . '</div>';
    }

    /**
     * Whether `$form_id` has the ifthenpay gateway selected on an active
     * Ninja Forms "Collect Payment" action — the same thing a customer would
     * actually hit if the (broken) Gutenberg block were left alone.
     */
    private function form_uses_ifthenpay(int $form_id): bool
    {
        if (! function_exists('Ninja_Forms')) {
            return false;
        }

        foreach (Ninja_Forms()->form($form_id)->get_actions() as $action) {
            $settings = $action->get_settings();

            if ('collectpayment' !== ($settings['type'] ?? '')) {
                continue;
            }

            if (! empty($settings['active']) && IfthenpayGateway::SLUG === ($settings['payment_gateways'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored record (`SubmissionStore`) only ever advances past
     * "pending" once ifthenpay's async webhook lands
     * (`Api\Webhook\WebhookController::handle_failure()`/`handle_success()`),
     * which can take longer than the customer's browser takes to bounce back
     * from ifthenpay's hosted payment page. If the browser gets here first,
     * the record is still "pending" even though ifthenpay's own redirect
     * already told us (via `iftp_nf_pay=error|cancel`, set by
     * `Gateway\IfthenpayGateway::build_return_url()`) that the payment
     * failed or was cancelled — so a still-"pending" stored status defers to
     * that query value for what to *display* right now. Once the stored
     * status has itself moved past "pending" (webhook beat the redirect, or
     * this page is reloaded/revisited later), it's strictly more
     * authoritative than a query param and wins outright.
     *
     * Public and static so `Ajax\FrontendController::verify_payment()` can
     * apply this same fallback on every later poll tick, not only on this
     * initial page load — otherwise a customer shown "Payment cancelled"
     * here would see it flip back to "Payment pending" the moment the first
     * poll tick read the still-"pending" stored status directly.
     */
    public static function resolve_display_status(string $stored_status, string $query_status): string
    {
        if (SubmissionStore::STATUS_PENDING !== $stored_status) {
            return $stored_status;
        }

        switch ($query_status) {
            case IfthenpayGateway::RETURN_STATUS_ERROR:
                return SubmissionStore::STATUS_FAILED;
            case IfthenpayGateway::RETURN_STATUS_CANCEL:
                return SubmissionStore::STATUS_CANCELLED;
            default:
                return $stored_status;
        }
    }

    /**
     * Shared with `Ajax\FrontendController::verify_payment()`, so the message
     * shown after a live poll update matches the one shown on first page
     * load exactly, without duplicating this lookup in two places. Prefers
     * whatever an admin configured on the "Confirmation Type" tab
     * (`Admin\ConfirmationPage`) for paid/pending/failed/cancelled, falling
     * back to `default_status_message()` wherever nothing was configured
     * (including "expired", which has no configurable message at all).
     */
    public static function status_message(string $status): string
    {
        $configured = (new SettingsRepository())->get_confirmation_message($status);

        return '' !== $configured ? $configured : self::default_status_message($status);
    }

    public static function default_status_message(string $status): string
    {
        switch ($status) {
            case SubmissionStore::STATUS_PAID:
                return __('Payment confirmed. Thank you!', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_FAILED:
                return __('Payment failed. Please try again.', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_CANCELLED:
                return __('Payment cancelled.', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_EXPIRED:
                return __('This payment link expired.', 'ifthenpay-payments-for-ninja-forms');
            default:
                // Deliberately doesn't name any specific payment method (e.g.
                // Multibanco/Payshop) — the actual method is chosen by the
                // customer on ifthenpay's own hosted page and is never known
                // here, at initial page load or on any later poll tick alike
                // (see `Ajax\FrontendController::verify_payment()`), so a
                // method-specific message here would be a guess at best and
                // wrong for every other method.
                return __("We're waiting for your payment to be confirmed. You don't need to do anything else — this will update automatically once it's complete.", 'ifthenpay-payments-for-ninja-forms');
        }
    }
}

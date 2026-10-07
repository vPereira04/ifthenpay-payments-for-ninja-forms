<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms;

use Ifthenpay\NinjaForms\Admin\AdminFooter;
use Ifthenpay\NinjaForms\Admin\ConfirmationPage;
use Ifthenpay\NinjaForms\Admin\EntriesPage;
use Ifthenpay\NinjaForms\Admin\SettingsPage;
use Ifthenpay\NinjaForms\Ajax\Controller as AjaxController;
use Ifthenpay\NinjaForms\Ajax\FrontendController;
use Ifthenpay\NinjaForms\Api\Webhook\WebhookController;
use Ifthenpay\NinjaForms\Cron\ExpiredPaymentsCron;
use Ifthenpay\NinjaForms\Gateway\IfthenpayGateway;
use Ifthenpay\NinjaForms\NinjaForms\AdHocForm;
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
        add_action('ninja_forms_after_form_delete', [AdHocForm::class, 'forget_form']);

        (new SettingsPage())->register();
        (new ConfirmationPage())->register();
        (new EntriesPage())->register();
        (new AdminFooter())->register();
        (new AjaxController())->register();
        (new FrontendController())->register();
        (new WebhookController())->register();
        (new ExpiredPaymentsCron())->register();
        // Activation only fires on a fresh install/reactivation, so a site
        // that already had the plugin active when this cron shipped would
        // never get the event scheduled — cheap to re-check every load since
        // schedule() itself no-ops once wp_next_scheduled() finds it.
        ExpiredPaymentsCron::schedule();

        add_action('template_redirect', [$this, 'maybe_redirect_paid_confirmation']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_return_banner']);
        add_action('wp_footer', [$this, 'maybe_enqueue_pay_by_link_spinner'], 1);
        add_filter('render_block_ninja-forms/form', [$this, 'maybe_block_broken_gutenberg_block'], 10, 2);
    }

    /**
     * The `?ver=` for one of our assets. While debugging it's the file's
     * mtime, so an edited file can't hide behind a cached copy; in
     * production it's the plugin version.
     */
    public static function asset_version(string $relative_path): string
    {
        if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)) {
            $file = IFTP_NF_PATH . $relative_path;

            if (file_exists($file)) {
                return (string) filemtime($file);
            }
        }

        return IFTP_NF_VERSION;
    }

    /**
     * I hook at priority -5 so this filter is registered before
     * CollectPayment's own handler (priority -1) reads it.
     */
    public function register_gateway(): void
    {
        add_filter('ninja_forms_register_payment_gateways', static function (array $gateways): array {
            $gateways[IfthenpayGateway::SLUG] = new IfthenpayGateway();

            return $gateways;
        });
    }

    /**
     * Shared by the two return-handling methods below so both agree on the
     * exact same resolved status/record instead of re-parsing `$_GET` twice.
     *
     * The customer lands here from ifthenpay's hosted page, so there's no
     * nonce to check. The signed key in the URL is what proves the link is
     * theirs.
     *
     * @return array{ref: string, key: string, record: array<string, mixed>, query_status: string, status: string, transaction_id: string}|null
     */
    private function resolve_return_context(): ?array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Return redirect from ifthenpay's hosted page; authenticated by the HMAC return key instead.
        $query_status   = sanitize_text_field(wp_unslash($_GET[IfthenpayGateway::ARG_STATUS] ?? ''));
        $ref            = sanitize_text_field(wp_unslash($_GET[IfthenpayGateway::ARG_REF] ?? ''));
        $key            = sanitize_text_field(wp_unslash($_GET[IfthenpayGateway::ARG_KEY] ?? ''));
        $transaction_id = sanitize_text_field(wp_unslash($_GET[IfthenpayGateway::ARG_TXN] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ('' === $query_status || ! IfthenpayGateway::is_valid_return_key($ref, $key)) {
            return null;
        }

        $record = (new SubmissionStore())->get($ref);

        if (null === $record) {
            return null;
        }

        return [
            'ref'            => $ref,
            'key'            => $key,
            'record'         => $record,
            'query_status'   => $query_status,
            'status'         => self::resolve_display_status((string) $record['status'], $query_status),
            // ifthenpay only fills this in on a genuine success return.
            'transaction_id' => IfthenpayGateway::RETURN_STATUS_SUCCESS === $query_status ? $transaction_id : '',
        ];
    }

    /**
     * Redirects to the configured "paid" destination the instant we already
     * know the payment is paid by the time the return page loads. Hooked at
     * `template_redirect` so it's still early enough to send headers. A
     * popup confirmation type has no redirect target, so this is a no-op
     * then — and a payment that resolves to "paid" later gets redirected
     * from the frontend poll instead.
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

        // The admin chose this URL and it can be off-site, which
        // wp_safe_redirect() would otherwise swap for wp-admin.
        $host = (string) wp_parse_url($redirect_url, PHP_URL_HOST);

        add_filter('allowed_redirect_hosts', static fn (array $hosts): array => array_merge($hosts, [$host]));

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

        wp_enqueue_style('iftp-nf-frontend', IFTP_NF_URL . 'assets/css/frontend.css', [], self::asset_version('assets/css/frontend.css'));
        wp_enqueue_script('iftp-nf-frontend', IFTP_NF_URL . 'assets/js/frontend.js', [], self::asset_version('assets/js/frontend.js'), true);

        // Only populated once this request has already resolved "paid" —
        // nothing about the submission should reach the browser before
        // payment is actually confirmed.
        $entry_data = SubmissionStore::STATUS_PAID === $status && $settings->get_show_entry_data()
            ? (new SubmissionStore())->entry_data_pairs($record)
            : [];

        wp_localize_script('iftp-nf-frontend', 'iftpNfReturn', [
            'status'          => $status,
            'message'         => self::status_message($status),
            'ref'             => $context['ref'],
            'key'             => $context['key'],
            'formId'          => (int) $record['form_id'],
            'queryStatus'     => $query_status,
            // Lets the frontend ask to resolve the payment right away
            // instead of waiting on the async webhook.
            'transactionId'   => $context['transaction_id'],
            // Only actually used if this payment is still unresolved now and
            // later resolves to "paid" during the frontend's live poll.
            'paidRedirectUrl' => $settings->get_paid_redirect_url(),
            'entryData'       => $entry_data,
            // Every status's title up front, so a status that changes
            // mid-poll gets its own title with no extra round trip.
            'titles'          => self::status_titles($settings),
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce(FrontendController::NONCE_ACTION),
            'okLabel'         => __('OK', 'ifthenpay-payments-for-ninja-forms'),
            'closeLabel'      => __('Close', 'ifthenpay-payments-for-ninja-forms'),
            'confirmingLabel' => __('Confirming your payment…', 'ifthenpay-payments-for-ninja-forms'),
        ]);
    }

    /**
     * Enqueues a small spinner overlay on any page where a form actually
     * rendered, so the user sees feedback while the browser navigates to
     * the payment link instead of staring at a dead page.
     *
     * I detect that via NF's own `nf-front-end` script handle, and hook at
     * `wp_footer` (not `wp_enqueue_scripts`) because Ninja Forms only
     * enqueues that handle while rendering the form itself, earlier in the
     * page.
     */
    public function maybe_enqueue_pay_by_link_spinner(): void
    {
        if (! wp_script_is('nf-front-end', 'enqueued') && ! wp_script_is('nf-front-end', 'done')) {
            return;
        }

        wp_enqueue_style('iftp-nf-frontend', IFTP_NF_URL . 'assets/css/frontend.css', [], self::asset_version('assets/css/frontend.css'));
        wp_enqueue_script(
            'iftp-nf-pay-by-link',
            IFTP_NF_URL . 'assets/js/pay-by-link.js',
            ['jquery', 'nf-front-end'],
            self::asset_version('assets/js/pay-by-link.js'),
            true
        );

        wp_localize_script('iftp-nf-pay-by-link', 'iftpNfPayByLink', [
            'redirectingLabel' => __('Redirecting to secure payment…', 'ifthenpay-payments-for-ninja-forms'),
        ]);
    }

    /**
     * Ninja Forms' own Gutenberg block always renders as preview, a core
     * bug that means "Record Submission" never creates a real submission
     * through it — so a form taking payment can never work through this
     * block, no matter what we do.
     *
     * Rather than send a customer to a real payment link for a submission
     * that was never going to be recorded, I replace the block's output
     * with a notice for editors, or nothing for a regular visitor.
     *
     * @param array<string, mixed> $block
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
     * The webhook can land after the browser already bounced back, so a
     * still-"pending" stored status defers to what the redirect itself told
     * us for what to display right now. Once the stored status moves past
     * "pending", it's authoritative and wins outright.
     *
     * Public and static so the frontend poll can apply the same fallback on
     * every tick, not just on initial page load.
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
     * Shared with the frontend poll so its message always matches what's
     * shown on first page load. Prefers the admin-configured message,
     * falling back to the default wherever nothing's configured.
     */
    public static function status_message(string $status): string
    {
        $configured = (new SettingsRepository())->get_confirmation_message($status);

        return '' !== $configured ? $configured : self::default_status_message($status);
    }

    /**
     * Popup title per status, '' where the admin left it hidden.
     *
     * @return array<string, string>
     */
    public static function status_titles(SettingsRepository $settings): array
    {
        $titles = [];

        foreach (SubmissionStore::ALL_STATUSES as $status) {
            $title = $settings->get_confirmation_title($status);

            if (! $title['shown']) {
                $titles[$status] = '';
                continue;
            }

            $titles[$status] = '' !== $title['text'] ? $title['text'] : self::default_status_title($status);
        }

        return $titles;
    }

    public static function default_status_title(string $status): string
    {
        switch ($status) {
            case SubmissionStore::STATUS_PAID:
                return __('Payment confirmed', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_FAILED:
                return __('Payment failed', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_CANCELLED:
                return __('Payment cancelled', 'ifthenpay-payments-for-ninja-forms');
            case SubmissionStore::STATUS_EXPIRED:
                return __('Payment link expired', 'ifthenpay-payments-for-ninja-forms');
            default:
                return __('Waiting for payment', 'ifthenpay-payments-for-ninja-forms');
        }
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
                // Not naming a specific method — the customer picks it on
                // ifthenpay's hosted page, and we never know which one here.
                return __("We're waiting for your payment to be confirmed. You don't need to do anything else — this will update automatically once it's complete.", 'ifthenpay-payments-for-ninja-forms');
        }
    }
}

<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Plugin;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "Confirmation Type" tab (`admin.php?page=nf-settings&tab=ifthenpay-confirmation`)
 * — lets an admin choose what a customer sees right after checkout, per
 * outcome: for "Paid", a popup message, a WordPress page, or a custom URL to
 * redirect to; for "Pending"/"Failed"/"Cancelled", a popup message only.
 *
 * Registered the same way as `SettingsPage` (see that class' docblock for why
 * a Ninja Forms settings tab is a meta box saved over AJAX rather than a
 * plain settings form): its own `ninja_forms_settings_tabs` entry, its own
 * meta box on its own `nf_settings_{tab}` screen, and its own AJAX save
 * handler (`Ajax\Controller::save_confirmation_settings()`).
 */
class ConfirmationPage
{
    private const TAB_SLUG   = 'ifthenpay-confirmation';
    private const SCREEN_ID  = 'nf_settings_ifthenpay-confirmation';
    private const METABOX_ID = 'iftp-nf-confirmation';

    private SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function register(): void
    {
        add_filter('ninja_forms_settings_tabs', [$this, 'add_tab']);
        add_action('current_screen', [$this, 'add_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * @param array<string, string> $tabs
     * @return array<string, string>
     */
    public function add_tab(array $tabs): array
    {
        if (! isset($tabs[self::TAB_SLUG])) {
            $tabs[self::TAB_SLUG] = __('Confirmation Type', 'ifthenpay-payments-for-ninja-forms');
        }

        return $tabs;
    }

    public function add_meta_box(): void
    {
        if (! $this->is_confirmation_tab()) {
            return;
        }

        add_meta_box(
            self::METABOX_ID,
            __('ifthenpay', 'ifthenpay-payments-for-ninja-forms'),
            [$this, 'render'],
            self::SCREEN_ID,
            'advanced',
            'default'
        );
    }

    public function enqueue_assets(): void
    {
        if (! $this->is_confirmation_tab()) {
            return;
        }

        wp_enqueue_style('iftp-nf-admin', IFTP_NF_URL . 'assets/css/admin.css', [], IFTP_NF_VERSION);
        wp_enqueue_script('iftp-nf-confirmation', IFTP_NF_URL . 'assets/js/confirmation.js', [], IFTP_NF_VERSION, true);

        wp_localize_script('iftp-nf-confirmation', 'iftpNfConfirmation', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('iftp_nf_admin'),
            'i18n'    => [
                'error' => __('Something went wrong. Please try again.', 'ifthenpay-payments-for-ninja-forms'),
            ],
        ]);
    }

    /**
     * Renders as a meta box callback: no <div class="wrap">/<h1>, the
     * postbox/title chrome is already provided by do_meta_boxes().
     */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $paid_type        = $this->settings->get_paid_confirmation_type();
        $paid_page_id     = $this->settings->get_paid_confirmation_page_id();
        $paid_url         = $this->settings->get_paid_confirmation_url();
        $show_entry_data  = $this->settings->get_show_entry_data();

        $paid_message      = $this->settings->get_confirmation_message(SubmissionStore::STATUS_PAID);
        $pending_message   = $this->settings->get_confirmation_message(SubmissionStore::STATUS_PENDING);
        $failed_message    = $this->settings->get_confirmation_message(SubmissionStore::STATUS_FAILED);
        $cancelled_message = $this->settings->get_confirmation_message(SubmissionStore::STATUS_CANCELLED);

        $page_dropdown = wp_dropdown_pages([
            'name'              => 'paid_page_id',
            'id'                => 'iftp-nf-confirmation-paid-page',
            'selected'          => $paid_page_id,
            'show_option_none'  => __('— Select a page —', 'ifthenpay-payments-for-ninja-forms'),
            'option_none_value' => '0',
            'echo'              => 0,
        ]);
        ?>
        <div class="iftp-nf-settings iftp-nf-confirmation">
            <div class="iftp-nf-settings-header">
                <span class="iftp-nf-brand-badge">
                    <img src="<?php echo esc_url(IFTP_NF_URL . 'assets/img/icon-white.svg'); ?>" alt="" />
                </span>
                <div class="iftp-nf-settings-header-p">
                    <p><?php esc_html_e('Choose what a customer sees right after checkout, for every payment outcome.', 'ifthenpay-payments-for-ninja-forms'); ?></p>
                </div>
            </div>

            <div class="iftp-nf-confirmation-tabs" role="tablist">
                <button type="button" class="iftp-nf-confirmation-tab is-active" data-target="paid" role="tab" aria-selected="true">
                    <?php esc_html_e('Paid', 'ifthenpay-payments-for-ninja-forms'); ?>
                </button>
                <button type="button" class="iftp-nf-confirmation-tab" data-target="pending" role="tab" aria-selected="false">
                    <?php esc_html_e('Pending', 'ifthenpay-payments-for-ninja-forms'); ?>
                </button>
                <button type="button" class="iftp-nf-confirmation-tab" data-target="failed" role="tab" aria-selected="false">
                    <?php esc_html_e('Failed', 'ifthenpay-payments-for-ninja-forms'); ?>
                </button>
                <button type="button" class="iftp-nf-confirmation-tab" data-target="cancelled" role="tab" aria-selected="false">
                    <?php esc_html_e('Cancelled', 'ifthenpay-payments-for-ninja-forms'); ?>
                </button>
            </div>

            <form id="iftp-nf-confirmation-form">
                <div class="iftp-nf-card iftp-nf-confirmation-panel" data-panel="paid">
                    <h3><?php esc_html_e('Paid', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                    <div class="iftp-nf-type-field">
                        <span class="iftp-nf-type-label"><?php esc_html_e('Confirmation Type', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                        <div class="iftp-nf-type-switch" role="tablist">
                            <?php
                            $type_options = [
                                SettingsRepository::CONFIRMATION_TYPE_POPUP => __('Popup message', 'ifthenpay-payments-for-ninja-forms'),
                                SettingsRepository::CONFIRMATION_TYPE_PAGE  => __('WordPress page', 'ifthenpay-payments-for-ninja-forms'),
                                SettingsRepository::CONFIRMATION_TYPE_URL   => __('Custom URL', 'ifthenpay-payments-for-ninja-forms'),
                            ];
                            foreach ($type_options as $value => $label) :
                                $is_active = $paid_type === $value;
                                ?>
                                <button
                                    type="button"
                                    class="iftp-nf-type-option <?php echo $is_active ? 'is-active' : ''; ?>"
                                    data-value="<?php echo esc_attr($value); ?>"
                                    role="tab"
                                    aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                                >
                                    <?php echo esc_html($label); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" id="iftp-nf-confirmation-paid-type" name="paid_type" value="<?php echo esc_attr($paid_type); ?>" />
                    </div>

                    <div class="iftp-nf-confirmation-option" data-option="popup" <?php echo SettingsRepository::CONFIRMATION_TYPE_POPUP === $paid_type ? '' : 'hidden'; ?>>
                        <label><?php esc_html_e('Popup Message', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                        <?php
                        $this->render_message_field(
                            'iftp-nf-confirmation-paid-message',
                            'paid_message',
                            $paid_message,
                            Plugin::default_status_message(SubmissionStore::STATUS_PAID)
                        );
                        ?>
                        <label class="iftp-nf-confirmation-checkbox">
                            <input
                                type="checkbox"
                                id="iftp-nf-confirmation-show-entry-data"
                                name="show_entry_data"
                                <?php checked($show_entry_data); ?>
                            />
                            <?php esc_html_e('Show Entry Data', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </label>
                    </div>

                    <div class="iftp-nf-confirmation-option" data-option="page" <?php echo SettingsRepository::CONFIRMATION_TYPE_PAGE === $paid_type ? '' : 'hidden'; ?>>
                        <label for="iftp-nf-confirmation-paid-page"><?php esc_html_e('Redirect Page', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                        <?php echo $page_dropdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() output is already-escaped core markup ?>
                    </div>

                    <div class="iftp-nf-confirmation-option" data-option="url" <?php echo SettingsRepository::CONFIRMATION_TYPE_URL === $paid_type ? '' : 'hidden'; ?>>
                        <label for="iftp-nf-confirmation-paid-url"><?php esc_html_e('Redirect URL', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                        <input
                            type="url"
                            id="iftp-nf-confirmation-paid-url"
                            name="paid_url"
                            class="large-text"
                            placeholder="https://example.com/thank-you"
                            value="<?php echo esc_attr($paid_url); ?>"
                        />
                    </div>
                </div>

                <div class="iftp-nf-card iftp-nf-confirmation-panel" data-panel="pending" hidden>
                    <h3><?php esc_html_e('Pending', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                    <label><?php esc_html_e('Popup Message', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                    <?php
                    $this->render_message_field(
                        'iftp-nf-confirmation-pending-message',
                        'pending_message',
                        $pending_message,
                        Plugin::default_status_message(SubmissionStore::STATUS_PENDING)
                    );
                    ?>
                </div>

                <div class="iftp-nf-card iftp-nf-confirmation-panel" data-panel="failed" hidden>
                    <h3><?php esc_html_e('Failed', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                    <label><?php esc_html_e('Popup Message', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                    <?php
                    $this->render_message_field(
                        'iftp-nf-confirmation-failed-message',
                        'failed_message',
                        $failed_message,
                        Plugin::default_status_message(SubmissionStore::STATUS_FAILED)
                    );
                    ?>
                </div>

                <div class="iftp-nf-card iftp-nf-confirmation-panel" data-panel="cancelled" hidden>
                    <h3><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                    <label><?php esc_html_e('Popup Message', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                    <?php
                    $this->render_message_field(
                        'iftp-nf-confirmation-cancelled-message',
                        'cancelled_message',
                        $cancelled_message,
                        Plugin::default_status_message(SubmissionStore::STATUS_CANCELLED)
                    );
                    ?>
                </div>

                <p class="iftp-nf-save-row">
                    <button type="submit" class="button button-primary" id="iftp-nf-save-confirmation">
                        <span class="iftp-nf-spinner" aria-hidden="true"></span>
                        <?php esc_html_e('Save Settings', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </button>
                    <span class="iftp-nf-save-status iftp-nf-save-status--success" aria-live="polite">
                        <?php esc_html_e('Saved', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </span>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * A popup message field with two synced views (`assets/js/confirmation.js`
     * keeps them in lockstep on every keystroke, in both directions): a
     * "Normal" `contenteditable` view for typing the message with a small
     * bold/italic toolbar, and a "Raw" `<textarea>` for viewing/editing the
     * underlying HTML directly. Only the `<textarea>` — `$id`/`$name` — is
     * the actual form field that gets submitted; the "Normal" view is what
     * the admin edits by default, mirrored into it live.
     */
    private function render_message_field(string $id, string $name, string $message, string $default): void
    {
        ?>
        <div class="iftp-nf-message-editor">
            <div class="iftp-nf-message-tabs" role="tablist">
                <button type="button" class="iftp-nf-message-tab is-active" data-mode="normal" role="tab" aria-selected="true">
                    <?php esc_html_e('Normal', 'ifthenpay-payments-for-ninja-forms'); ?>
                </button>
                <button type="button" class="iftp-nf-message-tab" data-mode="raw" role="tab" aria-selected="false">
                    <?php esc_html_e('Raw', 'ifthenpay-payments-for-ninja-forms'); ?>
                </button>
                <div class="iftp-nf-message-toolbar">
                    <button type="button" class="iftp-nf-message-format" data-command="bold" aria-label="<?php esc_attr_e('Bold', 'ifthenpay-payments-for-ninja-forms'); ?>"><strong>B</strong></button>
                    <button type="button" class="iftp-nf-message-format" data-command="italic" aria-label="<?php esc_attr_e('Italic', 'ifthenpay-payments-for-ninja-forms'); ?>"><em>I</em></button>
                </div>
            </div>
            <div
                class="iftp-nf-message-normal"
                data-view="normal"
                contenteditable="true"
                role="textbox"
                aria-multiline="true"
                aria-label="<?php esc_attr_e('Popup Message', 'ifthenpay-payments-for-ninja-forms'); ?>"
                data-placeholder="<?php echo esc_attr($default); ?>"
            ><?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already sanitized with wp_kses_post() on save, Ajax\Controller::save_confirmation_settings() ?></div>
            <textarea
                class="iftp-nf-message-raw"
                data-view="raw"
                hidden
                id="<?php echo esc_attr($id); ?>"
                name="<?php echo esc_attr($name); ?>"
                rows="4"
                placeholder="<?php echo esc_attr($default); ?>"
            ><?php echo esc_textarea($message); ?></textarea>
        </div>
        <?php
    }

    private function is_confirmation_tab(): bool
    {
        $page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
        $tab  = sanitize_text_field(wp_unslash($_GET['tab'] ?? ''));

        return 'nf-settings' === $page && self::TAB_SLUG === $tab;
    }
}

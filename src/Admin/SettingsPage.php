<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

use Ifthenpay\NinjaForms\Plugin;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;
use Ifthenpay\NinjaForms\Sync\GatewaySync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The global ifthenpay settings screen.
 *
 * Ninja Forms has no "Payments" tab by default, so I add my own via the
 * `ninja_forms_settings_tabs` filter. Non-`settings` tabs render as a meta
 * box with no enclosing `<form>`, so I save over AJAX instead of relying on
 * Ninja Forms' core settings POST handler.
 *
 * All settings — Backoffice Key, Gateway Key, methods table, default
 * method, description, expiry days — live on this one screen and save
 * together.
 */
class SettingsPage
{
    private const TAB_SLUG    = 'payments';
    private const SCREEN_ID   = 'nf_settings_payments';
    private const METABOX_ID  = 'iftp-nf-settings';

    private SettingsRepository $settings;
    private GatewaySettingsField $methods_field;
    private GatewaySync $sync;

    public function __construct(
        ?SettingsRepository $settings = null,
        ?GatewaySettingsField $methods_field = null,
        ?GatewaySync $sync = null
    ) {
        $this->settings      = $settings ?? new SettingsRepository();
        $this->methods_field = $methods_field ?? new GatewaySettingsField();
        $this->sync           = $sync ?? new GatewaySync($this->settings);
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
            $tabs[self::TAB_SLUG] = __('Payments', 'ifthenpay-payments-for-ninja-forms');
        }

        return $tabs;
    }

    public function add_meta_box(): void
    {
        if (! $this->is_payments_tab()) {
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
        if (! $this->is_payments_tab()) {
            return;
        }

        $admin_css = 'assets/css/admin.css';
        $admin_js  = 'assets/js/admin.js';

        wp_enqueue_style('iftp-nf-admin', IFTP_NF_URL . $admin_css, [], Plugin::asset_version($admin_css));
        wp_enqueue_script('iftp-nf-admin', IFTP_NF_URL . $admin_js, [], Plugin::asset_version($admin_js), true);

        wp_localize_script('iftp-nf-admin', 'iftpNfAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('iftp_nf_admin'),
            'i18n'    => [
                'requesting' => __('Requesting…', 'ifthenpay-payments-for-ninja-forms'),
                'requested'  => __('Requested', 'ifthenpay-payments-for-ninja-forms'),
                'connecting' => __('Connecting…', 'ifthenpay-payments-for-ninja-forms'),
                'error'      => __('Something went wrong. Please try again.', 'ifthenpay-payments-for-ninja-forms'),
            ],
        ]);
    }

    /**
     * I render as a meta box callback, so no wrap/h1 — do_meta_boxes()
     * already gives us the postbox chrome.
     */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if ($this->sync->needs_backfill()) {
            $this->sync->sync();
        }

        $connected = $this->settings->is_connected();
        ?>
        <div class="iftp-nf-settings" id="iftp-nf-settings-page">
            <div class="iftp-nf-settings-header">
                <span class="iftp-nf-brand-badge">
                    <img src="<?php echo esc_url(IFTP_NF_URL . 'assets/img/icon-white.svg'); ?>" alt="" />
                </span>
                <div class="iftp-nf-settings-header-p">
                    <p><?php esc_html_e('Connect your ifthenpay account and choose which payment methods to offer.', 'ifthenpay-payments-for-ninja-forms'); ?></p>
                </div>
            </div>

            <div class="iftp-nf-card" id="iftp-nf-connection-card">
                <h3><?php esc_html_e('Backoffice Key', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                <?php if ($connected) : ?>
                    <p>
                        <?php esc_html_e('Connected:', 'ifthenpay-payments-for-ninja-forms'); ?>
                        <code><?php echo esc_html(str_repeat('*', 18)); ?></code>
                    </p>
                    <button type="button" class="button" id="iftp-nf-disconnect">
                        <?php esc_html_e('Disconnect', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </button>
                <?php else : ?>
                    <p>
                        <input type="text" id="iftp-nf-backoffice-key" placeholder="<?php esc_attr_e('Insert your Backoffice Key here...', 'ifthenpay-payments-for-ninja-forms'); ?>" class="regular-text" />
                        <button type="button" class="button button-primary" id="iftp-nf-connect">
                            <?php esc_html_e('Connect', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                    </p>
                <?php endif; ?>
                <p id="iftp-nf-connection-message"></p>
            </div>

            <?php if ($connected) : ?>
                <form id="iftp-nf-settings-form">
                    <div class="iftp-nf-card">
                        <h3><?php esc_html_e('Gateway Key', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                        <select id="iftp-nf-gateway-key">
                            <?php foreach ($this->settings->get_gateway_keys() as $key) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $this->settings->get_gateway_key()); ?>>
                                    <?php echo esc_html($key); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="iftp-nf-card">
                        <h3><?php esc_html_e('Payment Methods', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                        <div id="iftp-nf-methods-table-wrapper">
                            <?php echo $this->methods_field->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped fragment ?>
                        </div>
                    </div>

                    <div class="iftp-nf-card">
                        <h3><?php esc_html_e('Hosted Payment Page', 'ifthenpay-payments-for-ninja-forms'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="iftp-nf-description"><?php esc_html_e('Description', 'ifthenpay-payments-for-ninja-forms'); ?></label></th>
                                <td>
                                    <input
                                        type="text"
                                        id="iftp-nf-description"
                                        name="description"
                                        class="regular-text"
                                        value="<?php echo esc_attr($this->settings->get_description()); ?>"
                                    />
                                </td>
                            </tr>
                            <tr>
                                <th><label for="iftp-nf-expiry-days"><?php esc_html_e('Expiry Days', 'ifthenpay-payments-for-ninja-forms'); ?></label></th>
                                <td>
                                    <input
                                        type="number"
                                        min="1"
                                        id="iftp-nf-expiry-days"
                                        name="expiry_days"
                                        value="<?php echo esc_attr((string) $this->settings->get_expiry_days()); ?>"
                                    />
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="iftp-nf-save-row">
                        <button type="submit" class="button button-primary" id="iftp-nf-save-settings">
                            <span class="iftp-nf-spinner" aria-hidden="true"></span>
                            <?php esc_html_e('Save Settings', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                        <span class="iftp-nf-save-status iftp-nf-save-status--success" aria-live="polite">
                            <?php esc_html_e('Saved', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </span>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function is_payments_tab(): bool
    {
        return AdminScreen::is('nf-settings', self::TAB_SLUG);
    }
}

<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

use Ifthenpay\NinjaForms\Repository\SettingsRepository;
use Ifthenpay\NinjaForms\Sync\GatewaySync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The global ifthenpay settings screen.
 *
 * Ninja Forms' own Settings screen (`admin.php?page=nf-settings`) has no
 * "Payments" tab by default — only `settings` and `licenses`
 * (`includes/Admin/Menus/Settings.php::display()`). Payment add-ons are
 * expected to add their own tab via the `ninja_forms_settings_tabs` filter,
 * then render into it: for any tab other than `settings`, the settings
 * template calls `do_meta_boxes('nf_settings_' . $active_tab, 'advanced', null)`
 * with no enclosing `<form>` — so this registers a meta box on the
 * `nf_settings_payments` screen and owns its own AJAX-based saving
 * (`Ajax\Controller`) rather than Ninja Forms' core settings POST handler.
 *
 * Carries the six fields of the "Default Settings Page Contract" (see
 * `.claude/agents/wp-reverse-engineer.md`): Backoffice Key, Gateway Key,
 * methods table, default method, description, expiry days — all on one
 * screen, saved together.
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

        wp_enqueue_style('iftp-nf-admin', IFTP_NF_URL . 'assets/css/admin.css', [], IFTP_NF_VERSION);
        wp_enqueue_script('iftp-nf-admin', IFTP_NF_URL . 'assets/js/admin.js', [], IFTP_NF_VERSION, true);

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
     * Renders as a meta box callback: no <div class="wrap">/<h1>, the
     * postbox/title chrome is already provided by do_meta_boxes().
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
        <div class="iftp-nf-settings">
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
                        <input type="text" id="iftp-nf-backoffice-key" placeholder="XXXX-XXXX-XXXX-XXXX" class="regular-text" />
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

                    <p>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Save Settings', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function is_payments_tab(): bool
    {
        $page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
        $tab  = sanitize_text_field(wp_unslash($_GET['tab'] ?? ''));

        return 'nf-settings' === $page && self::TAB_SLUG === $tab;
    }
}

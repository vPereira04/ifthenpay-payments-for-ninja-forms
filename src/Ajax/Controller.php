<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Ajax;

use Ifthenpay\NinjaForms\Admin\GatewaySettingsField;
use Ifthenpay\NinjaForms\Api\IfthenpayClient;
use Ifthenpay\NinjaForms\Mail\IfthenpayEmailHelper;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;
use Ifthenpay\NinjaForms\Sync\GatewaySync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin-only AJAX endpoints backing the settings screen: connect/disconnect
 * the Backoffice Key, switch/refresh the Gateway Key's methods table, save
 * settings, and request activation of a not-yet-provisioned method.
 */
class Controller
{
    private const NONCE_ACTION = 'iftp_nf_admin';

    private SettingsRepository $settings;
    private IfthenpayClient $client;
    private GatewaySync $sync;
    private GatewaySettingsField $methods_field;

    public function __construct(
        ?SettingsRepository $settings = null,
        ?IfthenpayClient $client = null,
        ?GatewaySettingsField $methods_field = null,
        ?GatewaySync $sync = null
    ) {
        $this->settings      = $settings ?? new SettingsRepository();
        $this->client         = $client ?? new IfthenpayClient();
        $this->methods_field = $methods_field ?? new GatewaySettingsField();
        $this->sync           = $sync ?? new GatewaySync($this->settings, $this->client);
    }

    public function register(): void
    {
        add_action('wp_ajax_iftp_nf_connect_backoffice', [$this, 'connect_backoffice']);
        add_action('wp_ajax_iftp_nf_disconnect_backoffice', [$this, 'disconnect_backoffice']);
        add_action('wp_ajax_iftp_nf_refresh_methods', [$this, 'refresh_methods']);
        add_action('wp_ajax_iftp_nf_select_gateway_key', [$this, 'select_gateway_key']);
        add_action('wp_ajax_iftp_nf_save_settings', [$this, 'save_settings']);
        add_action('wp_ajax_iftp_nf_request_activation', [$this, 'request_activation']);
    }

    public function connect_backoffice(): void
    {
        $this->guard();

        $backoffice_key = sanitize_text_field(wp_unslash($_POST['backoffice_key'] ?? ''));

        if (! preg_match('/^\d{4}-\d{4}-\d{4}-\d{4}$/', $backoffice_key)) {
            wp_send_json_error(['message' => __('Invalid Backoffice Key format.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        if (! $this->sync->connect($backoffice_key)) {
            wp_send_json_error(['message' => __('No Ninja Forms gateway found for this Backoffice Key.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        $this->register_webhook($this->settings->get_gateway_key());

        wp_send_json_success([
            'gateway_key'  => $this->settings->get_gateway_key(),
            'gateway_keys' => $this->settings->get_gateway_keys(),
            'table_html'   => $this->methods_field->render(),
        ]);
    }

    /**
     * Switches which of this Backoffice Key's Gateway Key rows is active,
     * re-fetching that row's provisioned methods.
     */
    public function select_gateway_key(): void
    {
        $this->guard();

        $gateway_key = sanitize_text_field(wp_unslash($_POST['gateway_key'] ?? ''));

        if (! $this->sync->switch_gateway_key($gateway_key)) {
            wp_send_json_error(['message' => __('Unknown Gateway Key.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        $this->register_webhook($gateway_key);

        wp_send_json_success(['table_html' => $this->methods_field->render()]);
    }

    public function disconnect_backoffice(): void
    {
        $this->guard();

        $this->settings->delete_all();

        wp_send_json_success();
    }

    public function refresh_methods(): void
    {
        $this->guard();

        $this->sync->sync();

        wp_send_json_success(['table_html' => $this->methods_field->render()]);
    }

    public function save_settings(): void
    {
        $this->guard();

        $enabled_entities = array_map('sanitize_text_field', wp_unslash((array) ($_POST['enabled_methods'] ?? [])));
        $default_method   = sanitize_text_field(wp_unslash($_POST['default_method'] ?? ''));
        $description      = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $expiry_days      = (int) ($_POST['expiry_days'] ?? 3);

        $methods = $this->settings->get_methods();

        foreach ($methods as &$method) {
            $method['enabled'] = in_array($method['entity'], $enabled_entities, true) && '' !== $method['account'];
        }
        unset($method);

        if (! in_array($default_method, array_column(array_filter($methods, static fn ($m) => $m['enabled']), 'entity'), true)) {
            $default_method = '';
        }

        $this->settings->set_methods($methods);
        $this->settings->set_default_method($default_method);
        $this->settings->set_description($description);
        $this->settings->set_expiry_days($expiry_days);

        wp_send_json_success(['table_html' => $this->methods_field->render()]);
    }

    public function request_activation(): void
    {
        $this->guard();

        $entity      = strtoupper(sanitize_text_field(wp_unslash($_POST['entity'] ?? '')));
        $gateway_key = $this->settings->get_gateway_key();

        if ('' === $entity || '' === $gateway_key) {
            wp_send_json_error(['message' => __('Missing method or Gateway Key.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        $cooldown_key = 'iftp_nf_activation_' . md5($gateway_key . '|' . $entity);

        if (get_transient($cooldown_key)) {
            wp_send_json_error(['message' => __('An activation request for this method was already sent recently.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        $sent = IfthenpayEmailHelper::send_activation_email([
            'gateway_key'          => $gateway_key,
            'entity'               => $entity,
            'backoffice_key'       => $this->settings->get_backoffice_key(),
            'customer_email'       => wp_get_current_user()->user_email,
            'site_url'             => home_url('/'),
            'site_name'            => get_bloginfo('name'),
            'wp_version'           => get_bloginfo('version'),
            'ninja_forms_version'  => defined('NF_VERSION') ? NF_VERSION : '',
            'plugin_version'       => defined('IFTP_NF_VERSION') ? IFTP_NF_VERSION : '',
        ]);

        if (! $sent) {
            wp_send_json_error(['message' => __('Could not send the activation request. Please try again.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        set_transient($cooldown_key, true, DAY_IN_SECONDS);

        wp_send_json_success();
    }

    private function guard(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'ifthenpay-payments-for-ninja-forms')], 403);
        }
    }

    private function register_webhook(string $gateway_key): void
    {
        if ('' === $gateway_key) {
            return;
        }

        $this->client->activate_callback($gateway_key, \Ifthenpay\NinjaForms\Api\Webhook\WebhookController::url());
    }
}

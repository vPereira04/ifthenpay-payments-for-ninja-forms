<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Ajax;

use Ifthenpay\NinjaForms\Admin\GatewaySettingsField;
use Ifthenpay\NinjaForms\Api\IfthenpayClient;
use Ifthenpay\NinjaForms\Mail\IfthenpayEmailHelper;
use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;
use Ifthenpay\NinjaForms\Sync\GatewaySync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * I built this as the router for the settings screen's admin AJAX calls —
 * connecting/disconnecting the Backoffice Key, switching or refreshing the
 * Gateway Key table, saving settings, and requesting activation.
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
        add_action('wp_ajax_iftp_nf_save_confirmation_settings', [$this, 'save_confirmation_settings']);
    }

    public function connect_backoffice(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

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
     * I switch the active Gateway Key row and re-fetch its provisioned methods.
     */
    public function select_gateway_key(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $gateway_key = sanitize_text_field(wp_unslash($_POST['gateway_key'] ?? ''));

        if (! $this->sync->switch_gateway_key($gateway_key)) {
            wp_send_json_error(['message' => __('Unknown Gateway Key.', 'ifthenpay-payments-for-ninja-forms')]);
        }

        $this->register_webhook($gateway_key);

        wp_send_json_success(['table_html' => $this->methods_field->render()]);
    }

    public function disconnect_backoffice(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $this->settings->delete_all();
        IfthenpayClient::clear_cache();

        wp_send_json_success();
    }

    public function refresh_methods(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $this->sync->sync(true);

        wp_send_json_success(['table_html' => $this->methods_field->render()]);
    }

    public function save_settings(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $enabled_entities = array_map('sanitize_text_field', wp_unslash((array) ($_POST['enabled_methods'] ?? [])));
        $default_method   = sanitize_text_field(wp_unslash($_POST['default_method'] ?? ''));
        $description      = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $expiry_days      = absint(wp_unslash($_POST['expiry_days'] ?? 3));

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

    /**
     * I sanitize the messages with wp_kses_post() rather than
     * sanitize_textarea_field() — the editor lets admins add basic
     * formatting, and we render the saved value as HTML on the frontend.
     */
    public function save_confirmation_settings(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $paid_type       = sanitize_text_field(wp_unslash($_POST['paid_type'] ?? ''));
        $paid_page_id    = absint(wp_unslash($_POST['paid_page_id'] ?? 0));
        $paid_url        = esc_url_raw(wp_unslash($_POST['paid_url'] ?? ''));
        $show_entry_data = ! empty($_POST['show_entry_data']);

        $paid_message      = wp_kses_post(wp_unslash($_POST['paid_message'] ?? ''));
        $pending_message   = wp_kses_post(wp_unslash($_POST['pending_message'] ?? ''));
        $failed_message    = wp_kses_post(wp_unslash($_POST['failed_message'] ?? ''));
        $cancelled_message = wp_kses_post(wp_unslash($_POST['cancelled_message'] ?? ''));

        $this->settings->set_paid_confirmation_type($paid_type);
        $this->settings->set_paid_confirmation_page_id($paid_page_id);
        $this->settings->set_paid_confirmation_url($paid_url);
        $this->settings->set_show_entry_data($show_entry_data);
        $this->settings->set_confirmation_message(SubmissionStore::STATUS_PAID, $paid_message);
        $this->settings->set_confirmation_message(SubmissionStore::STATUS_PENDING, $pending_message);
        $this->settings->set_confirmation_message(SubmissionStore::STATUS_FAILED, $failed_message);
        $this->settings->set_confirmation_message(SubmissionStore::STATUS_CANCELLED, $cancelled_message);

        $titles = [];

        foreach ([SubmissionStore::STATUS_PAID, SubmissionStore::STATUS_PENDING, SubmissionStore::STATUS_FAILED, SubmissionStore::STATUS_CANCELLED] as $status) {
            $titles[$status] = [
                'text'  => sanitize_text_field(wp_unslash($_POST[$status . '_title'] ?? '')),
                'shown' => ! empty($_POST[$status . '_title_shown']),
            ];
        }

        $this->settings->set_confirmation_titles($titles);

        wp_send_json_success();
    }

    public function request_activation(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

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

    private function authorize(): void
    {
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

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

        $ref = $this->generate_reference((int) $form_id);
        $gateway_key = $this->settings->get_gateway_key();

        $payload = IfthenpayPayload::build_payment_payload(
            $ref,
            $amount,
            $this->settings->get_description(),
            $enabled_methods,
            $this->build_return_url('success', $ref),
            $this->build_return_url('error', $ref),
            $this->build_return_url('cancel', $ref),
            IfthenpayPayload::locale_to_lang(get_locale()),
            $this->settings->get_default_method_position()
        );

        $result = $this->client->create_payment_link($gateway_key, $payload);

        if (false === $result) {
            // Always logged, not gated behind WP_DEBUG: a failed payment start
            // is an operational signal, not a routine debug trace, and many
            // sites that need to see this never run with WP_DEBUG on.
            error_log('ifthenpay Payments for Ninja Forms: create_payment_link failed - ' . $this->client->get_last_error());

            return $this->fail($data, __('Unable to start the ifthenpay payment. Please try again.', 'ifthenpay-payments-for-ninja-forms'));
        }

        $data['processed_actions'][] = $action_settings['id'];

        $this->submissions->store_pending($ref, (int) $form_id, $data, $amount, $gateway_key);

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
     * Ninja Forms' own submission IDs (1, 2, 3, ...) don't exist yet at this
     * point — a submission is only ever created once payment is confirmed
     * (see `Api\Webhook\WebhookController`) — so this can't reuse them; it
     * needs its own reference before that, to hand to ifthenpay and to look
     * up when the webhook calls back. It's random rather than sequential on
     * purpose: a guessable reference would let anyone probe other
     * customers' payment status. 12 characters (~71 bits) is already far
     * more than enough to rule out collisions; `Admin\EntriesPage` adds a
     * plain sequential "#" column for the at-a-glance number this isn't
     * meant to be.
     */
    private function generate_reference(int $form_id): string
    {
        return 'nf' . $form_id . '_' . wp_generate_password(12, false, false);
    }

    private function build_return_url(string $status, string $ref): string
    {
        return add_query_arg(
            [
                'iftp_nf_pay' => $status,
                'ref'         => $ref,
            ],
            home_url('/')
        );
    }
}

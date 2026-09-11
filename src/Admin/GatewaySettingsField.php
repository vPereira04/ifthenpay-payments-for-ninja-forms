<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders the methods table: one row per catalog method, an "Enabled"
 * checkbox, a star-toggle "Default Method" radio, and a "Request
 * Activation" button for anything not yet provisioned.
 *
 * Follows the mandatory "Default Method Selector" contract in
 * `.claude/agents/wp-reverse-engineer.md` — a radio group, never a select.
 */
class GatewaySettingsField
{
    private SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function render(): string
    {
        $methods = $this->settings->get_methods();
        $default_method = $this->settings->get_default_method();

        if ([] === $methods) {
            return '<p class="iftp-nf-empty">' . esc_html__('Connect your Backoffice Key to load the available payment methods.', 'ifthenpay-payments-for-ninja-forms') . '</p>';
        }

        ob_start();
        ?>
        <table class="widefat striped iftp-nf-methods-table">
            <thead>
                <tr>
                    <th class="iftp-nf-col-control"><?php esc_html_e('Enabled', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                    <th class="iftp-nf-col-control iftp-nf-col-divider"><?php esc_html_e('Default', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                    <th><?php esc_html_e('Method', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                    <th><?php esc_html_e('Account', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                    <th><?php esc_html_e('Status', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($methods as $method) : ?>
                    <?php $this->render_row($method, $default_method); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array{entity: string, alias: string, logo?: string, enabled: bool, account: string, position: int} $method
     */
    private function render_row(array $method, string $default_method): void
    {
        $logo = $method['logo'] ?? '';
        $provisioned = '' !== $method['account'];
        // $method['account'] is the full "ENTITY|ACCOUNT" accounts-string
        // segment (see Sync\GatewaySync::resolve_account()); show just the
        // bare code here, the Method column already names the entity.
        // Multibanco is the exception: its two parts are Entidade/Subentidade
        // (e.g. "11686|000"), not an entity label + account, so both halves
        // are meaningful and shown as "11686 | 000".
        $account_parts   = explode('|', $method['account']);
        $account_display = ! $provisioned ? '' : (
            'MB' === $method['entity'] ? implode(' | ', $account_parts) : end($account_parts)
        );
        $is_default  = $method['entity'] === $default_method;
        $row_class   = $provisioned ? '' : ' iftp-nf-row--unprovisioned';
        ?>
        <tr class="iftp-nf-method-row<?php echo esc_attr($row_class); ?>" data-entity="<?php echo esc_attr($method['entity']); ?>">
            <td class="iftp-nf-col-control">
                <input
                    type="checkbox"
                    class="iftp-nf-method-enabled"
                    name="enabled_methods[]"
                    value="<?php echo esc_attr($method['entity']); ?>"
                    <?php checked($method['enabled']); ?>
                    <?php disabled(! $provisioned); ?>
                />
            </td>
            <td class="iftp-nf-col-control iftp-nf-col-divider">
                <input
                    type="radio"
                    class="screen-reader-text iftp-nf-default-method"
                    id="iftp-nf-default-<?php echo esc_attr($method['entity']); ?>"
                    name="default_method"
                    value="<?php echo esc_attr($method['entity']); ?>"
                    <?php checked($is_default); ?>
                    <?php disabled(! $method['enabled']); ?>
                />
                <label
                    for="iftp-nf-default-<?php echo esc_attr($method['entity']); ?>"
                    class="iftp-nf-star<?php echo $method['enabled'] ? '' : ' iftp-nf-star--hidden'; ?>"
                    title="<?php esc_attr_e('Default payment method', 'ifthenpay-payments-for-ninja-forms'); ?>"
                >&#9733;</label>
            </td>
            <td class="iftp-nf-col-method">
                <span class="iftp-nf-logo-slot">
                    <?php if ('' !== $logo) : ?>
                        <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($method['alias']); ?>" loading="lazy" />
                    <?php else : ?>
                        <?php echo esc_html($method['entity']); ?>
                    <?php endif; ?>
                </span>
                <span class="iftp-nf-method-label<?php echo $provisioned ? '' : ' iftp-nf-dimmed'; ?>">
                    <?php echo esc_html($method['alias']); ?>
                </span>
            </td>
            <td>
                <?php if ($provisioned) : ?>
                    <code class="iftp-nf-account-pill"><?php echo esc_html($account_display); ?></code>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($provisioned) : ?>
                    <span class="iftp-nf-status iftp-nf-status--ok"><?php esc_html_e('Activated', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                <?php else : ?>
                    <span class="iftp-nf-status iftp-nf-dimmed"><?php esc_html_e('Not activated', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <button
                        type="button"
                        class="button button-secondary iftp-nf-request-activation"
                        data-entity="<?php echo esc_attr($method['entity']); ?>"
                    >
                        <?php esc_html_e('Request Activation', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

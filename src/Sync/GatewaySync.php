<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Sync;

use Ifthenpay\NinjaForms\Api\IfthenpayClient;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fetches Gateway Key rows and the methods catalog from ifthenpay and
 * reconciles them into settings. Shared by the connect/switch AJAX flows
 * and the settings page's lazy backfill for older connections.
 */
class GatewaySync
{
    private SettingsRepository $settings;
    private IfthenpayClient $client;

    public function __construct(?SettingsRepository $settings = null, ?IfthenpayClient $client = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
        $this->client    = $client ?? new IfthenpayClient();
    }

    /**
     * First-time connection: I validate the Backoffice Key has at least one
     * Gateway Key row, store it, and build its methods table.
     */
    public function connect(string $backoffice_key): bool
    {
        $rows = $this->client->get_gateway_keys($backoffice_key, true);

        if ([] === $rows) {
            return false;
        }

        $this->settings->set_backoffice_key($backoffice_key);

        $gateway_keys = array_map([$this, 'extract_gateway_key'], $rows);
        $this->settings->set_gateway_keys($gateway_keys);
        $this->settings->set_gateway_key($gateway_keys[0]);
        $this->settings->set_methods($this->build_methods($rows[0], true));

        return true;
    }

    /**
     * Re-fetches the Gateway Key list and rebuilds the methods table for
     * whichever key is selected, falling back to the first row if it's no
     * longer valid. $fresh skips the API caches (the Refresh button).
     */
    public function sync(bool $fresh = false): bool
    {
        $rows = $this->client->get_gateway_keys($this->settings->get_backoffice_key(), $fresh);

        if ([] === $rows) {
            return false;
        }

        $gateway_keys = array_map([$this, 'extract_gateway_key'], $rows);
        $this->settings->set_gateway_keys($gateway_keys);

        $gateway_key = $this->settings->get_gateway_key();

        if (! in_array($gateway_key, $gateway_keys, true)) {
            $gateway_key = $gateway_keys[0];
            $this->settings->set_gateway_key($gateway_key);
        }

        $row = $this->find_row_for_gateway_key($rows, $gateway_key);
        $this->settings->set_methods($this->build_methods($row ?? [], $fresh));

        return true;
    }

    /**
     * Explicitly switches to a different, already-known Gateway Key row.
     */
    public function switch_gateway_key(string $gateway_key): bool
    {
        if (! in_array($gateway_key, $this->settings->get_gateway_keys(), true)) {
            return false;
        }

        $rows = $this->client->get_gateway_keys($this->settings->get_backoffice_key());
        $row  = $this->find_row_for_gateway_key($rows, $gateway_key);

        $this->settings->set_gateway_key($gateway_key);
        $this->settings->set_methods($this->build_methods($row ?? []));
        $this->settings->set_default_method('');

        return true;
    }

    /**
     * True when stored settings predate a fix: no Gateway Key list yet, or
     * a methods entry missing its display name (alias used to come out
     * empty before I confirmed the catalog's real field name).
     */
    public function needs_backfill(): bool
    {
        if (! $this->settings->is_connected()) {
            return false;
        }

        if ([] === $this->settings->get_gateway_keys()) {
            return true;
        }

        return $this->settings->has_outdated_methods();
    }

    /**
     * Merges the ifthenpay methods catalog with the accounts already
     * provisioned on this gateway row.
     *
     * I confirmed these field names against live API responses: the display
     * name is `Method`, not `Alias`; the logo is `SmallImageUrl` (falling
     * back to `ImageUrl`), not `Logo` — neither of those fields exists.
     *
     * @param array<string, mixed> $gateway_row
     * @return array<int, array{entity: string, alias: string, logo: string, enabled: bool, account: string, position: int}>
     */
    private function build_methods(array $gateway_row, bool $fresh = false): array
    {
        $catalog = $this->client->get_available_methods($fresh);
        $existing = array_column($this->settings->get_methods(), null, 'entity');
        $methods = [];

        foreach ($catalog as $entry) {
            if (empty($entry['IsVisible'])) {
                continue;
            }

            $entity = (string) ($entry['Entity'] ?? '');
            $alias  = (string) ($entry['Method'] ?? '');
            $account = $this->resolve_account($gateway_row, $entity);

            $methods[] = [
                'entity'   => $entity,
                'alias'    => $alias,
                'logo'     => (string) ($entry['SmallImageUrl'] ?? $entry['ImageUrl'] ?? ''),
                'account'  => $account,
                'enabled'  => '' !== $account && ! empty($existing[$entity]['enabled']),
                'position' => (int) ($entry['Position'] ?? 0),
            ];
        }

        return $methods;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extract_gateway_key(array $row): string
    {
        return (string) ($row['GatewayKey'] ?? $row['gatewayKey'] ?? $row['Chave'] ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function find_row_for_gateway_key(array $rows, string $gateway_key): ?array
    {
        foreach ($rows as $row) {
            if ($this->extract_gateway_key($row) === $gateway_key) {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * Multibanco's column is named "Multibanco", not "MB" like every other
     * method's column matches its entity code. The value is already a
     * complete `ENTITY|ACCOUNT` segment — I just normalize stray spacing.
     *
     * @return string The normalized `ENTITY|ACCOUNT` segment, or '' if this
     *                 method isn't provisioned on this gateway row.
     *
     * @param array<string, mixed> $row
     */
    private function resolve_account(array $row, string $entity): string
    {
        $column = 'MB' === $entity ? 'Multibanco' : $entity;
        $value  = $row[$column] ?? '';

        if (! is_string($value) || '' === trim($value)) {
            return '';
        }

        return implode('|', array_map('trim', explode('|', $value, 2)));
    }
}

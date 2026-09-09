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
 * reconciles them into `SettingsRepository`. Shared by the AJAX connect/
 * switch flows (`Ajax\Controller`) and by `Admin\SettingsPage`'s lazy
 * backfill for a connection made before the Gateway Key list or method
 * logos existed in stored settings.
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
     * First-time connection: validates the Backoffice Key has at least one
     * Ninja Forms Gateway Key row, stores it, and builds its methods table.
     */
    public function connect(string $backoffice_key): bool
    {
        $rows = $this->client->get_gateway_keys($backoffice_key);

        if ([] === $rows) {
            return false;
        }

        $this->settings->set_backoffice_key($backoffice_key);

        $gateway_keys = array_map([$this, 'extract_gateway_key'], $rows);
        $this->settings->set_gateway_keys($gateway_keys);
        $this->settings->set_gateway_key($gateway_keys[0]);
        $this->settings->set_methods($this->build_methods($rows[0]));

        return true;
    }

    /**
     * Re-fetches the Gateway Key list and rebuilds the methods table for
     * whichever key is currently selected (falling back to the first row if
     * the stored key is no longer valid). Idempotent — safe to call on
     * every settings-page render as well as from "Refresh".
     */
    public function sync(): bool
    {
        $rows = $this->client->get_gateway_keys($this->settings->get_backoffice_key());

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
        $this->settings->set_methods($this->build_methods($row ?? []));

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
     * True when stored settings predate a fix that needed a resync: no
     * Gateway Key list yet, or a methods entry missing its display name
     * (the `alias` field was empty for everyone until the catalog's real
     * field name — `Method`, not `Alias` — was confirmed against a live
     * response; see `build_methods()`).
     */
    public function needs_backfill(): bool
    {
        if (! $this->settings->is_connected()) {
            return false;
        }

        if ([] === $this->settings->get_gateway_keys()) {
            return true;
        }

        foreach ($this->settings->get_methods() as $method) {
            if (! array_key_exists('logo', $method) || '' === ($method['alias'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges the ifthenpay methods catalog with the accounts already
     * provisioned on this gateway row.
     *
     * Field names confirmed against a live `/gateway/get?Type=ninjaforms`
     * response and a live `/gateway/methods/available` response:
     * - The catalog's display name is `Method` (e.g. "MBWAY", "VISA / MASTERCARD"),
     *   not `Alias` — there is no `Alias` field on a methods-catalog entry.
     * - The catalog's logo is `SmallImageUrl` (falling back to `ImageUrl`),
     *   not `Logo` — there is no `Logo` field either.
     * - The gateway row's per-method columns store the FULL, ready-to-use
     *   accounts-string segment already — e.g. `CCARD` => `"CCARD | AAA-000000"`,
     *   `Multibanco` => `"11686 | 000"` — not a bare account code. See
     *   `resolve_account()`.
     *
     * @param array<string, mixed> $gateway_row
     * @return array<int, array{entity: string, alias: string, logo: string, enabled: bool, account: string, position: int}>
     */
    private function build_methods(array $gateway_row): array
    {
        $catalog = $this->client->get_available_methods();
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
     * The gateway row's per-method column is keyed by the entity code for
     * every method except Multibanco, whose column is literally named
     * "Multibanco" (its value pair is the numeric Entidade/Subentidade, e.g.
     * `"11686 | 000"`, not the literal string "MB"). The column's value is
     * already the complete `ENTITY|ACCOUNT` accounts-string segment — just
     * with stray spaces around the pipe — so this only normalizes that
     * spacing and never re-derives it from our own catalog entity code.
     *
     * @return string The normalized `ENTITY|ACCOUNT` segment, or '' if this
     *                 method isn't provisioned on this gateway row.
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

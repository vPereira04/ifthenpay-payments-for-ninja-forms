<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\NinjaForms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persists ifthenpay payment state independently of Ninja Forms' own PHP
 * session (which `nf_ajax_resume` relies on and which cannot be trusted to
 * survive the hours/days an offline method like Multibanco or Payshop can
 * take to actually get paid).
 *
 * One WP option per payment, keyed by our own reference. The option holds
 * everything needed to complete the submission later (`ResumeController`)
 * from the webhook alone, with no dependency on the customer's browser ever
 * coming back.
 *
 * `Gateway\IfthenpayGateway` reserves the real Ninja Forms submission (and
 * its numeric ID) up front, as soon as the payment link is created — not
 * only once payment is confirmed — so that ID is stable and known from the
 * start instead of being assigned later and potentially confused with a
 * separately-computed number. Every status change this store records is
 * mirrored onto that submission's post meta (`sync_submission_meta()`) so
 * anyone inspecting the submission directly sees current, accurate state.
 */
class SubmissionStore
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /**
     * Extra-value (post meta) keys kept in sync onto the real Ninja Forms
     * submission every time this store's status changes, for anyone
     * inspecting the submission directly — see `sync_submission_meta()`.
     */
    public const META_STATUS         = 'ifthenpay_status';
    public const META_REF            = 'ifthenpay_ref';
    public const META_AMOUNT         = 'ifthenpay_amount';
    public const META_PAY_METHOD     = 'ifthenpay_pay_method';
    public const META_REQUEST_ID     = 'ifthenpay_request_id';
    public const META_PAYMENT_URL    = 'ifthenpay_payment_url';
    public const META_TRANSACTION_ID = 'ifthenpay_transaction_id';

    private const OPTION_PREFIX = 'iftp_nf_payment_';

    /**
     * `Admin\EntriesPage` re-fetches `get_all()` on every single page/filter
     * click otherwise — a fresh full-table read every time. Short TTL is
     * just to survive a burst of clicks; anything that changes a record
     * (`store_pending()`, `update_status()`, `update_data()`, `delete()`)
     * clears it immediately, so nothing here is ever stale after a write.
     */
    private const CACHE_KEY = 'iftp_nf_payments_all';
    private const CACHE_TTL = 60;

    /**
     * A record is one `wp_option` row (see class docblock) — there's no way
     * to filter/sort/paginate those at the SQL level, so `get_all()` would
     * otherwise have to load every single one into PHP first on every
     * request. `query_index()` is the real fix: a small indexed table
     * (`form_id`, `sub_id`, `amount`, `pay_method`, `status`, `is_test`,
     * `created_at`, `updated_at`, keyed by `ref`) kept in sync alongside
     * every write below, so `Admin\EntriesPage` can filter, sort and
     * `LIMIT`/`OFFSET` in SQL and only ever unserialize the handful of full
     * records it's actually about to render.
     */
    private const INDEX_VERSION_OPTION = 'iftp_nf_payments_index_version';
    private const INDEX_VERSION = 1;

    /**
     * @param array<string, mixed> $data The Ninja Forms `$data` array captured at
     *                                    halt time (fields, extra, processed_actions, ...).
     */
    public function store_pending(
        string $ref,
        int $form_id,
        array $data,
        float $amount,
        string $gateway_key,
        string $payment_url = '',
        bool $is_test = false
    ): void {
        $record = [
            'ref'            => $ref,
            'form_id'        => $form_id,
            'data'           => $data,
            'amount'         => $amount,
            'gateway_key'    => $gateway_key,
            'payment_url'    => $payment_url,
            'status'         => self::STATUS_PENDING,
            'pay_method'     => '',
            'request_id'     => '',
            'transaction_id' => '',
            'is_test'        => $is_test,
            'created_at'     => time(),
            'updated_at'     => time(),
        ];

        update_option(self::option_name($ref), $record, false);

        $this->sync_submission_meta($record);
        $this->sync_index($record);
        $this->invalidate_all_cache();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $ref): ?array
    {
        $record = get_option(self::option_name($ref), null);

        return is_array($record) ? $record : null;
    }

    public function mark_paid(string $ref, string $pay_method, string $request_id): bool
    {
        return $this->update_status($ref, self::STATUS_PAID, [
            'pay_method' => $pay_method,
            'request_id' => $request_id,
        ]);
    }

    public function mark_failed(string $ref): bool
    {
        return $this->update_status($ref, self::STATUS_FAILED);
    }

    public function mark_cancelled(string $ref): bool
    {
        return $this->update_status($ref, self::STATUS_CANCELLED);
    }

    public function mark_expired(string $ref): bool
    {
        return $this->update_status($ref, self::STATUS_EXPIRED);
    }

    /**
     * Records a transaction id the moment it's received from the browser on
     * a success return (`Ajax\FrontendController::verify_payment()`) —
     * independent of whether `Api\Webhook\WebhookController::confirm_via_transaction_status()`
     * (called right after this) manages to confirm the payment with it.
     * Without this, an id that arrived but failed to confirm (e.g.
     * ifthenpay's transaction-status API was transiently unreachable, or the
     * payment was still a Multibanco/Payshop reference at that point) was
     * never kept anywhere. Never touches `status` — only the genuine webhook
     * or a successful transaction-status confirmation may do that (see
     * `update_status()`).
     */
    public function record_transaction_id(string $ref, string $transaction_id): void
    {
        $record = $this->get($ref);

        if (null === $record || ($record['transaction_id'] ?? '') === $transaction_id) {
            return;
        }

        $record['transaction_id'] = $transaction_id;
        $record['updated_at']     = time();

        update_option(self::option_name($ref), $record, false);

        $this->sync_submission_meta($record);
        $this->invalidate_all_cache();
    }

    /**
     * Persists the `$data` snapshot returned by `ResumeController` after it
     * has run the form's remaining actions. In the rare case where
     * `Gateway\IfthenpayGateway::reserve_submission()` never got a `sub_id`
     * up front, `ResumeController` is what actually runs the "save" action
     * and mints the real submission — for the first time, here. Without
     * writing that back onto this record, the new ID would never make it
     * into this store: `Admin\EntriesPage` would keep showing "—" for it
     * and the submission's post meta would never get synced, even though the
     * submission now genuinely exists.
     *
     * @param array<string, mixed> $data
     */
    public function update_data(string $ref, array $data): void
    {
        $record = $this->get($ref);

        if (null === $record) {
            return;
        }

        $record['data']       = $data;
        $record['updated_at'] = time();

        update_option(self::option_name($ref), $record, false);

        $this->sync_submission_meta($record);
        $this->sync_index($record);
        $this->invalidate_all_cache();
    }

    /**
     * Once paid, the record is terminal and is never moved to any other
     * status (Multibanco/Payshop references can still be confirmed after
     * being reported cancelled/expired, so only "paid" ever locks the door).
     *
     * @param array<string, mixed> $extra
     */
    private function update_status(string $ref, string $status, array $extra = []): bool
    {
        $record = $this->get($ref);

        if (null === $record) {
            return false;
        }

        if (self::STATUS_PAID === $record['status']) {
            return true;
        }

        $record = array_merge($record, $extra, [
            'status'     => $status,
            'updated_at' => time(),
        ]);

        update_option(self::option_name($ref), $record, false);

        $this->sync_submission_meta($record);
        $this->sync_index($record);
        $this->invalidate_all_cache();

        return true;
    }

    /**
     * Explicit admin correction from the Entries screen's bulk-actions bar —
     * unlike `update_status()` above (webhook/payment-lifecycle driven, and
     * deliberately locked once a record reaches "paid"), this always writes
     * the requested status, since it's a direct human override rather than
     * an automated race against the webhook.
     */
    public function admin_set_status(string $ref, string $status): bool
    {
        if (! in_array($status, self::ALL_STATUSES, true)) {
            return false;
        }

        $record = $this->get($ref);

        if (null === $record) {
            return false;
        }

        $record = array_merge($record, [
            'status'     => $status,
            'updated_at' => time(),
        ]);

        update_option(self::option_name($ref), $record, false);

        $this->sync_submission_meta($record);
        $this->sync_index($record);
        $this->invalidate_all_cache();

        return true;
    }

    /**
     * Removes this plugin's own payment-tracking record for `$ref` — never
     * the real Ninja Forms submission itself, if one exists.
     */
    public function delete(string $ref): void
    {
        global $wpdb;

        delete_option(self::option_name($ref));

        $wpdb->delete(self::index_table_name(), ['ref' => $ref], ['%s']);

        $this->invalidate_all_cache();
    }

    /**
     * Every payment reference ever recorded, regardless of status —
     * options are already indexed by `option_name` in the DB, so this scans
     * that rather than maintaining a separate index that a bug (or a future
     * change) could silently drop entries from. Used by `Cron\ExpiredPaymentsCron`.
     *
     * @return array<int, string>
     */
    public function get_all_refs(): array
    {
        global $wpdb;

        $prefix = self::OPTION_PREFIX;
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );

        return array_map(static fn (string $name): string => substr($name, strlen($prefix)), $names);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_all(): array
    {
        $cached = get_transient(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $records = array_values(array_filter(array_map([$this, 'get'], $this->get_all_refs())));

        set_transient(self::CACHE_KEY, $records, self::CACHE_TTL);

        return $records;
    }

    /**
     * Every distinct form id that has ever had a payment attempt recorded —
     * backs the "All forms" filter dropdown on the Entries screen.
     *
     * @return array<int, int>
     */
    public function distinct_form_ids(): array
    {
        global $wpdb;

        $this->maybe_build_index();

        $ids = $wpdb->get_col('SELECT DISTINCT form_id FROM ' . self::index_table_name() . ' ORDER BY form_id ASC');

        return array_map('intval', $ids);
    }

    /**
     * Filters/sorts/paginates entirely in SQL against the index table, then
     * only unserializes the handful of full records the current page
     * actually needs.
     *
     * @param array<string, mixed> $args
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, paged: int}
     */
    public function query_index(array $args): array
    {
        global $wpdb;

        $this->maybe_build_index();

        $table = self::index_table_name();
        [$where, $params] = $this->build_where($args, true);
        $where_sql = [] === $where ? '1=1' : implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) ([] === $params ? $wpdb->get_var($count_sql) : $wpdb->get_var($wpdb->prepare($count_sql, $params)));

        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $pages    = max(1, (int) ceil($total / $per_page));
        $paged    = min($pages, max(1, (int) ($args['paged'] ?? 1)));
        $offset   = ($paged - 1) * $per_page;

        $orderby_map = [
            'id'      => 'sub_id',
            'amount'  => 'amount',
            'status'  => 'status',
            'created' => 'created_at',
            'updated' => 'updated_at',
        ];
        $orderby = $orderby_map[$args['orderby'] ?? ''] ?? 'created_at';
        $order   = 'asc' === strtolower((string) ($args['order'] ?? '')) ? 'ASC' : 'DESC';

        $select_sql = "SELECT ref FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $refs = $wpdb->get_col($wpdb->prepare($select_sql, array_merge($params, [$per_page, $offset])));

        $items = array_values(array_filter(array_map([$this, 'get'], $refs)));

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'paged' => $paged,
        ];
    }

    /**
     * Status-tab counts for the Entries screen — deliberately always ignores
     * the `status` key of `$args` (via `build_where($args, false)`) so
     * switching tabs never looks like entries disappeared from the other
     * tabs' counts.
     *
     * @param array<string, mixed> $args
     * @return array<string, int>
     */
    public function status_counts(array $args): array
    {
        global $wpdb;

        $table = self::index_table_name();

        [$where, $params] = $this->build_where($args, false);

        $where_sql = [] === $where ? '1=1' : implode(' AND ', $where);

        $sql = "SELECT status, COUNT(*) AS total FROM {$table} WHERE {$where_sql} GROUP BY status";
        $rows = [] === $params ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, $params));

        $counts = ['' => 0];

        foreach ($rows as $row) {
            $count = (int) $row->total;
            $counts[(string) $row->status] = $count;
            $counts[''] += $count;
        }

        return $counts;
    }

    /**
     * Shared `WHERE` builder behind `query_index()` and `status_counts()` —
     * the latter always passes `$include_status = false` so its counts stay
     * independent of whichever status tab is currently active.
     *
     * @param array<string, mixed> $args
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function build_where(array $args, bool $include_status): array
    {
        global $wpdb;

        $where  = [];
        $params = [];

        $form_id = (int) ($args['form_id'] ?? 0);

        if ($form_id > 0) {
            $where[]  = 'form_id = %d';
            $params[] = $form_id;
        }

        $date_from = (int) ($args['date_from'] ?? 0);

        if ($date_from > 0) {
            $where[]  = 'created_at >= %d';
            $params[] = $date_from;
        }

        $date_to = (int) ($args['date_to'] ?? 0);

        if ($date_to > 0) {
            $where[]  = 'created_at <= %d';
            $params[] = $date_to;
        }

        $search = (string) ($args['search'] ?? '');

        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $search_form_ids = (array) ($args['search_form_ids'] ?? []);

            $search_clauses  = ['ref LIKE %s', 'pay_method LIKE %s', 'status LIKE %s'];
            $search_params   = [$like, $like, $like];

            if ([] !== $search_form_ids) {
                $placeholders = implode(',', array_fill(0, count($search_form_ids), '%d'));
                $search_clauses[] = "form_id IN ({$placeholders})";
                $search_params = array_merge($search_params, array_map('intval', $search_form_ids));
            }

            if (is_numeric($search)) {
                $search_clauses[] = 'amount = %f';
                $search_params[]  = (float) $search;
            }

            $where[] = '(' . implode(' OR ', $search_clauses) . ')';
            $params  = array_merge($params, $search_params);
        }

        if ($include_status) {
            $status = (string) ($args['status'] ?? '');

            if ('' !== $status) {
                $where[]  = 'status = %s';
                $params[] = $status;
            }
        }

        return [$where, $params];
    }

    /**
     * Flat `meta_key => value` map of this record's ifthenpay-tracked
     * fields — the same set `sync_submission_meta()` writes onto the real
     * submission's post meta, exposed here so `Api\Webhook\WebhookController::finalize_paid()`
     * can merge them into Ninja Forms' own `$data['extra']` too, making them
     * available as extra-value merge tags for whatever actions run after
     * payment confirmation (Email, Success Message, ...).
     *
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    public function submission_extra_values(array $record): array
    {
        return [
            self::META_STATUS         => (string) $record['status'],
            self::META_REF            => (string) $record['ref'],
            self::META_AMOUNT         => number_format((float) $record['amount'], 2, '.', ''),
            self::META_PAY_METHOD     => (string) ($record['pay_method'] ?? ''),
            self::META_REQUEST_ID     => (string) ($record['request_id'] ?? ''),
            self::META_PAYMENT_URL    => (string) ($record['payment_url'] ?? ''),
            self::META_TRANSACTION_ID => (string) ($record['transaction_id'] ?? ''),
        ];
    }

    /**
     * Mirrors current status/amount/method/etc. onto the real Ninja Forms
     * submission's post meta, if one has already been reserved
     * (`Gateway\IfthenpayGateway::reserve_submission()`) — a no-op until
     * then, since there's nothing to attach meta to yet.
     *
     * @param array<string, mixed> $record
     */
    private function sync_submission_meta(array $record): void
    {
        $sub_id = $record['data']['actions']['save']['sub_id'] ?? null;

        if (null === $sub_id) {
            return;
        }

        $sub_id = (int) $sub_id;

        foreach ($this->submission_extra_values($record) as $meta_key => $value) {
            update_post_meta($sub_id, $meta_key, $value);
        }
    }

    /**
     * Keeps the index table's row for `$record['ref']` in sync — a
     * `REPLACE INTO` keyed on the unique `ref` column, so every write above
     * (create, status change, data update) only ever needs to call this once
     * afterwards rather than juggling insert-vs-update itself.
     *
     * @param array<string, mixed> $record
     */
    private function sync_index(array $record): void
    {
        global $wpdb;

        $sub_id = $record['data']['actions']['save']['sub_id'] ?? null;

        $wpdb->replace(
            self::index_table_name(),
            [
                'ref'        => (string) $record['ref'],
                'form_id'    => (int) $record['form_id'],
                'sub_id'     => null !== $sub_id ? (int) $sub_id : null,
                'amount'     => (float) $record['amount'],
                'pay_method' => (string) ($record['pay_method'] ?? ''),
                'status'     => (string) $record['status'],
                'is_test'    => ! empty($record['is_test']) ? 1 : 0,
                'created_at' => (int) $record['created_at'],
                'updated_at' => (int) $record['updated_at'],
            ],
            ['%s', '%d', '%d', '%f', '%s', '%s', '%d', '%d', '%d']
        );
    }

    private function invalidate_all_cache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    private static function option_name(string $ref): string
    {
        return self::OPTION_PREFIX . $ref;
    }

    private static function index_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'iftp_nf_payments_index';
    }

    /**
     * Creates (or upgrades) the index table the first time it's needed,
     * guarded by `INDEX_VERSION_OPTION` so the potentially-expensive
     * rebuild-from-`wp_options` scan below only ever runs once per version
     * bump rather than on every single page load.
     */
    public static function maybe_build_index(): void
    {
        if ((int) get_option(self::INDEX_VERSION_OPTION, 0) === self::INDEX_VERSION) {
            return;
        }

        global $wpdb;

        $table           = self::index_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$table} (
            ref VARCHAR(191) NOT NULL,
            form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sub_id BIGINT UNSIGNED NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            pay_method VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT '',
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (ref),
            KEY status (status),
            KEY form_id (form_id),
            KEY created_at (created_at)
        ) {$charset_collate};");

        $store = new self();

        foreach ($store->get_all_refs() as $ref) {
            $record = $store->get($ref);

            if (null !== $record) {
                $store->sync_index($record);
            }
        }

        update_option(self::INDEX_VERSION_OPTION, self::INDEX_VERSION, false);
    }

    /**
     * Best-effort label => value list from the captured submission snapshot,
     * skipping structural field types with nothing meaningful to show.
     * Shared by `Admin\EntriesPage` (the entry details panel) and the
     * customer-facing "Show Entry Data" box on the paid confirmation popup
     * (`Admin\ConfirmationPage`, `Plugin::maybe_enqueue_return_banner()`,
     * `Ajax\FrontendController::verify_payment()`) — one source of truth for
     * what counts as a real, displayable submitted field.
     *
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    public function submitted_fields(array $record): array
    {
        $skip_types = ['submit', 'html', 'hr', 'divider', 'recaptcha', 'hcaptcha', 'turnstile'];
        $fields = [];

        foreach ((array) ($record['data']['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $settings = (array) ($field['settings'] ?? []);
            $type     = (string) ($settings['type'] ?? $field['type'] ?? '');
            $label    = (string) ($settings['label'] ?? $field['label'] ?? '');
            $value    = $field['value'] ?? '';

            if ('' === $label || in_array($type, $skip_types, true) || ! is_scalar($value) || '' === (string) $value) {
                continue;
            }

            $fields[$label] = (string) $value;
        }

        return $fields;
    }

    /**
     * `submitted_fields()` above, reshaped into an ordered list of
     * `{label, value}` pairs — what the customer-facing "Show Entry Data"
     * box actually sends to the browser (`wp_localize_script()`/
     * `wp_send_json_success()` both encode an associative array as a JSON
     * object, which is harder for `assets/js/frontend.js` to iterate in a
     * stable order than a plain list, and collapses two fields that happen
     * to share the exact same label).
     *
     * @param array<string, mixed> $record
     * @return array<int, array{label: string, value: string}>
     */
    public function entry_data_pairs(array $record): array
    {
        $pairs = [];

        foreach ($this->submitted_fields($record) as $label => $value) {
            $pairs[] = ['label' => $label, 'value' => $value];
        }

        return $pairs;
    }
}

<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\NinjaForms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persists ifthenpay payment state independently of Ninja Forms' own PHP
 * session, which can't be trusted to survive the hours/days an offline
 * method like Multibanco can take to get paid.
 *
 * One WP option per payment, keyed by our reference — enough for
 * `ResumeController` to finish the submission later from the webhook alone,
 * with no dependency on the customer's browser coming back. Every status
 * change here also gets mirrored onto the real submission's post meta.
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
     * Post meta keys I keep in sync on the real submission every time
     * status changes, so anyone inspecting it directly sees current state.
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
     * Object-cache group for index reads. Every write bumps its
     * `last_changed` token, so a cached page/filter result is never stale.
     */
    private const CACHE_GROUP = 'iftp_nf_payments';

    /**
     * Schema revision of the index table, stored in `iftp_nf_db_version`.
     * Records live as `wp_option` rows, which can't be filtered/sorted in
     * SQL — the table mirrors the key fields so `query_index()` can.
     *
     * Rev 2 moved every "+ New Payment" entry onto a real Ninja Forms
     * submission (`migrate_legacy_adhoc_record()`), at Victor's request.
     */
    private const SCHEMA_REVISION = 2;

    /**
     * Migration-only — the "form_id" every "+ New Payment" entry was tagged
     * with before v2, back when it lived in its own table instead of a real
     * Ninja Forms submission. `maybe_build_index()` uses this to find those
     * rows and migrate them; nothing new is ever tagged with it.
     */
    private const LEGACY_AD_HOC_FORM_ID = -1;

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
     * From the Entries screen's "+ New Payment" popup — records a payment
     * taken outside the normal checkout (over the phone, in person). Gets a
     * real Ninja Forms submission on `AdHocForm`'s hidden form, exactly like
     * a checkout would, so it has a real post ID — "Edit Fields" and the
     * Submission ID column work on it like any other entry, at Victor's
     * request.
     *
     * @return string|null the generated ref, or null if Ninja Forms
     *                      couldn't be reached to create the submission, or
     *                      the index write failed (`$wpdb->last_error` has
     *                      why) — either way nothing is left behind as an
     *                      entry the Entries list can never find.
     */
    public function create_manual(
        string $customer_name,
        string $customer_email,
        float $amount,
        string $pay_method,
        string $status,
        string $request_id = ''
    ): ?string {
        self::maybe_build_index();

        $form_id = AdHocForm::form_id();

        if (0 === $form_id) {
            return null;
        }

        $submission = $this->create_adhoc_submission($form_id, $customer_name, $customer_email);

        if (null === $submission) {
            return null;
        }

        $ref = $this->generate_manual_ref();

        $fields = [];

        if ('' !== $customer_name) {
            $fields[] = ['settings' => ['type' => 'firstname', 'label' => 'Name'], 'value' => $customer_name];
        }

        if ('' !== $customer_email) {
            $fields[] = ['settings' => ['type' => 'email', 'label' => 'Email'], 'value' => $customer_email];
        }

        $record = [
            'ref'            => $ref,
            'form_id'        => $form_id,
            'data'           => ['fields' => $fields, 'actions' => ['save' => $submission]],
            'amount'         => $amount,
            'gateway_key'    => 'manual',
            'payment_url'    => '',
            'status'         => $status,
            'pay_method'     => $pay_method,
            'request_id'     => $request_id,
            'transaction_id' => '',
            'is_test'        => false,
            'created_at'     => time(),
            'updated_at'     => time(),
        ];

        update_option(self::option_name($ref), $record, false);

        $this->sync_submission_meta($record);
        $indexed = $this->sync_index($record);
        $this->invalidate_all_cache();

        if (! $indexed) {
            delete_option(self::option_name($ref));

            if (function_exists('Ninja_Forms')) {
                Ninja_Forms()->form($form_id)->sub($submission['sub_id'])->get()->delete();
            }

            return null;
        }

        return $ref;
    }

    /**
     * Creates the real submission behind a "+ New Payment" entry, on
     * `AdHocForm`'s hidden form — same model Ninja Forms itself uses to
     * save a checkout submission (`NF_Actions_Save::process()`), just
     * called directly since there's no real form submission to run actions
     * against here.
     *
     * Both fields are always set, even blank — Ninja Forms only persists
     * `_form_id`/`_seq_num` once at least one field value exists
     * (`NF_Database_Models_Submission::_save_field_values()`), and without
     * them the submission would never resolve back to its form.
     *
     * @return array{sub_id: int, seq_num: int}|null
     */
    private function create_adhoc_submission(int $form_id, string $customer_name, string $customer_email): ?array
    {
        if (! function_exists('Ninja_Forms')) {
            return null;
        }

        $sub = Ninja_Forms()->form($form_id)->sub()->get();
        $sub->update_field_value(AdHocForm::name_field_id(), $customer_name);
        $sub->update_field_value(AdHocForm::email_field_id(), $customer_email);
        $sub->save();

        $sub_id = $sub->get_id();

        if ($sub_id <= 0) {
            return null;
        }

        return ['sub_id' => $sub_id, 'seq_num' => (int) get_post_meta($sub_id, '_seq_num', true)];
    }

    /**
     * `MANUAL_` keeps these visually distinct from a real gateway ref
     * (`Gateway\IfthenpayGateway::generate_reference()`) and from a
     * `TEST_n` preview attempt, at a glance in the ID column.
     */
    private function generate_manual_ref(): string
    {
        do {
            $ref = 'MANUAL_' . strtoupper(wp_generate_password(8, false, false));
        } while (null !== $this->get($ref));

        return $ref;
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
     * Saves the transaction id as soon as the browser reports it, regardless
     * of whether confirming it against the transaction-status API actually
     * succeeds right after — otherwise an id that arrived but failed to
     * confirm was never kept anywhere. Never touches `status` itself.
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
     * Saves the `$data` snapshot after `ResumeController` runs the form's
     * remaining actions. In the rare case a submission wasn't reserved up
     * front, this is where its new ID first makes it into the record —
     * without it, the entries admin would keep showing no ID for it.
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
     * Once paid, I never move a record to any other status — a
     * Multibanco/Payshop reference can still get confirmed after being
     * reported cancelled or expired, so "paid" is the only thing that locks.
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
     * An explicit admin override from the Entries bulk-actions bar — unlike
     * `update_status()`, this always writes the requested status, since a
     * human override shouldn't get blocked by the "paid is terminal" rule.
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
     * I only remove this plugin's tracking record for `$ref` here — never
     * the real Ninja Forms submission itself.
     */
    public function delete(string $ref): void
    {
        global $wpdb;

        delete_option(self::option_name($ref));
        $wpdb->delete(self::index_table_name(), ['ref' => $ref], ['%s']);

        $this->invalidate_all_cache();
    }

    /**
     * Every payment reference ever recorded. I scan `wp_options` directly by
     * name prefix rather than maintain a separate index that could silently
     * drop entries.
     *
     * @return array<int, string>
     */
    public function get_all_refs(): array
    {
        global $wpdb;

        $prefix    = self::OPTION_PREFIX;
        $cache_key = self::cache_key('all_refs');
        $names     = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (! is_array($names)) {
            $names = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT option_name FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $wpdb->esc_like($prefix) . '%'
                )
            );
            wp_cache_set($cache_key, $names, self::CACHE_GROUP);
        }

        return array_map(static fn (string $name): string => substr($name, strlen($prefix)), $names);
    }

    /**
     * Pending refs created before `$cutoff`, for the daily expiry sweep.
     *
     * @return array<int, string>
     */
    public function pending_refs_before(int $cutoff): array
    {
        global $wpdb;

        self::maybe_build_index();

        $cache_key = self::cache_key('pending_before', [$cutoff]);
        $refs      = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (! is_array($refs)) {
            $refs = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT ref FROM %i WHERE status = %s AND created_at < %d',
                    self::index_table_name(),
                    self::STATUS_PENDING,
                    $cutoff
                )
            );
            wp_cache_set($cache_key, $refs, self::CACHE_GROUP);
        }

        return $refs;
    }

    /**
     * Backs the "All forms" filter dropdown on the Entries screen.
     *
     * @return array<int, int>
     */
    public function distinct_form_ids(): array
    {
        global $wpdb;

        self::maybe_build_index();

        $cache_key = self::cache_key('form_ids');
        $form_ids  = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (! is_array($form_ids)) {
            $form_ids = $wpdb->get_col(
                $wpdb->prepare('SELECT DISTINCT form_id FROM %i ORDER BY form_id ASC', self::index_table_name())
            );
            wp_cache_set($cache_key, $form_ids, self::CACHE_GROUP);
        }

        return array_map('intval', $form_ids);
    }

    /**
     * I filter/sort/paginate in SQL against the index table, then only
     * unserialize the handful of full records the current page needs.
     *
     * @param array<string, mixed> $args
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, paged: int}
     */
    public function query_index(array $args): array
    {
        global $wpdb;

        self::maybe_build_index();

        [$where, $params] = $this->build_where($args, true);
        $where_sql = [] === $where ? '1=1' : implode(' AND ', $where);
        $params    = array_merge([self::index_table_name()], $params);

        $count_key = self::cache_key('count', $params);
        $total     = wp_cache_get($count_key, self::CACHE_GROUP);

        if (false === $total) {
            // $where_sql only holds placeholders from build_where().
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where_sql}", ...$params));
            wp_cache_set($count_key, $total, self::CACHE_GROUP);
        }

        $total    = (int) $total;
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

        $select_params = array_merge($params, [$per_page, $offset]);
        $refs_key      = self::cache_key('refs', array_merge($select_params, [$orderby, $order]));
        $refs          = wp_cache_get($refs_key, self::CACHE_GROUP);

        if (! is_array($refs)) {
            // $where_sql only holds placeholders, and ORDER BY comes from the whitelist above.
            // PHPCS can't count the spread args, so it also misreads the placeholder count.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $refs = $wpdb->get_col($wpdb->prepare("SELECT ref FROM %i WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", ...$select_params));
            wp_cache_set($refs_key, $refs, self::CACHE_GROUP);
        }

        $items = array_values(array_filter(array_map([$this, 'get'], $refs)));

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'paged' => $paged,
        ];
    }

    /**
     * Status-tab counts for the Entries screen. I ignore the `status` key of
     * `$args` here so switching tabs never makes entries look like they
     * disappeared from the other tabs' counts.
     *
     * @param array<string, mixed> $args
     * @return array<string, int>
     */
    public function status_counts(array $args): array
    {
        $counts = ['' => 0];

        foreach ($this->status_counts_for_table($this->build_where($args, false)) as $status => $count) {
            $counts[$status] = ($counts[$status] ?? 0) + $count;
            $counts[''] += $count;
        }

        // Folded into "Failed" — see `build_where()`.
        if (isset($counts[self::STATUS_CANCELLED])) {
            $counts[self::STATUS_FAILED] = ($counts[self::STATUS_FAILED] ?? 0) + $counts[self::STATUS_CANCELLED];
            unset($counts[self::STATUS_CANCELLED]);
        }

        return $counts;
    }

    /**
     * @param array{0: array<int, string>, 1: array<int, mixed>} $where
     * @return array<string, int>
     */
    private function status_counts_for_table(array $where): array
    {
        global $wpdb;

        [$clauses, $params] = $where;
        $where_sql = [] === $clauses ? '1=1' : implode(' AND ', $clauses);
        $params    = array_merge([self::index_table_name()], $params);

        $cache_key = self::cache_key('status_counts', $params);
        $rows      = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (! is_array($rows)) {
            // $where_sql only holds placeholders from build_where().
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS total FROM %i WHERE {$where_sql} GROUP BY status", ...$params));
            wp_cache_set($cache_key, $rows, self::CACHE_GROUP);
        }

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Date-range + status clauses shared by every filtered query against the
     * index table — split out from `build_where()` only because
     * `status_counts()` needs the status clause left out of its own count.
     *
     * @param array<string, mixed> $args
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function build_common_where(array $args, bool $include_status): array
    {
        $where  = [];
        $params = [];

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

        if ($include_status) {
            $status = (string) ($args['status'] ?? '');

            // The admin list no longer treats "cancelled" as its own status
            // — it reads as "Failed" everywhere here (see `EntriesPage`) —
            // so filtering by "failed" pulls in cancelled rows too, at
            // Victor's request. The `cancelled` status itself still exists
            // in the database (the webhook still writes it, and the
            // customer-facing cancelled confirmation message still keys off
            // it), this only affects how the admin list queries it.
            if (self::STATUS_FAILED === $status) {
                $where[]  = 'status IN (%s, %s)';
                $params[] = self::STATUS_FAILED;
                $params[] = self::STATUS_CANCELLED;
            } elseif ('' !== $status) {
                $where[]  = 'status = %s';
                $params[] = $status;
            }
        }

        return [$where, $params];
    }

    /**
     * `WHERE` builder for the index table — used by `query_index()` and
     * `status_counts()`.
     *
     * @param array<string, mixed> $args
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function build_where(array $args, bool $include_status): array
    {
        global $wpdb;

        [$where, $params] = $this->build_common_where($args, $include_status);

        $form_id = (int) ($args['form_id'] ?? 0);

        if (0 !== $form_id) {
            $where[]  = 'form_id = %d';
            $params[] = $form_id;
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

        return [$where, $params];
    }

    /**
     * Flat `meta_key => value` map of this record's tracked fields — reused
     * so the webhook can merge them into `$data['extra']` too, making them
     * available as merge tags for actions that run after payment.
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
     * Mirrors status/amount/method onto the real submission's post meta, if
     * one's been reserved already — a no-op until then.
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
     * Keeps the index row for `$record['ref']` in sync — a `REPLACE INTO`
     * keyed on `ref`, so callers never have to juggle insert-vs-update.
     *
     * Every other caller of this treats it as fire-and-forget (the option
     * row is the source of truth; the index is just what `query_index()`
     * searches/sorts against) — `create_manual()` is the one caller that
     * checks the return, since a manually-created entry with no matching
     * index row would report "created" while staying permanently invisible
     * to the Entries list, with no error anywhere to explain why.
     *
     * @param array<string, mixed> $record
     * @return bool false means `$wpdb->last_error` has the reason.
     */
    private function sync_index(array $record): bool
    {
        global $wpdb;

        $sub_id = $record['data']['actions']['save']['sub_id'] ?? null;

        $result = $wpdb->replace(
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

        return false !== $result;
    }

    private function invalidate_all_cache(): void
    {
        wp_cache_set_last_changed(self::CACHE_GROUP);
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function cache_key(string $name, array $args = []): string
    {
        return $name . ':' . md5((string) wp_json_encode($args)) . ':' . wp_cache_get_last_changed(self::CACHE_GROUP);
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
     * Creates (or upgrades) the index table the first time it's needed. The
     * version guard keeps the rebuild scan from running on every page load.
     */
    public static function maybe_build_index(): void
    {
        if (version_compare((string) get_option('iftp_nf_db_version', '0'), (string) self::SCHEMA_REVISION, '>=')) {
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

            if (null === $record) {
                continue;
            }

            if (self::LEGACY_AD_HOC_FORM_ID === (int) ($record['form_id'] ?? 0)) {
                $record = $store->migrate_legacy_adhoc_record($record);
            }

            if (null !== $record) {
                $store->sync_index($record);
            }
        }

        // The version used to live under these names. The old ad hoc table
        // itself is left for uninstall.php to drop.
        delete_option('iftp_nf_payments_index_version');
        delete_option('iftp_nf_adhoc_payments_index_version');

        $store->invalidate_all_cache();
        update_option('iftp_nf_db_version', self::SCHEMA_REVISION, false);
    }

    /**
     * Gives a pre-v2 "+ New Payment" entry the real Ninja Forms submission
     * it never had — same as `create_manual()` does for a new one — so it
     * ends up with a real post ID too. Its original Name/Email, captured in
     * `$record['data']['fields']` back when it was created, seed the new
     * submission's fields.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null null if Ninja Forms couldn't be
     *                                    reached — left for the next run to
     *                                    retry rather than lost.
     */
    private function migrate_legacy_adhoc_record(array $record): ?array
    {
        $form_id = AdHocForm::form_id();

        if (0 === $form_id) {
            return null;
        }

        $name  = '';
        $email = '';

        foreach ((array) ($record['data']['fields'] ?? []) as $field) {
            $type = (string) ($field['settings']['type'] ?? '');

            if ('firstname' === $type) {
                $name = (string) ($field['value'] ?? '');
            } elseif ('email' === $type) {
                $email = (string) ($field['value'] ?? '');
            }
        }

        $submission = $this->create_adhoc_submission($form_id, $name, $email);

        if (null === $submission) {
            return null;
        }

        $record['form_id'] = $form_id;
        $record['data']['actions']['save'] = $submission;

        update_option(self::option_name((string) $record['ref']), $record, false);
        $this->sync_submission_meta($record);

        return $record;
    }

    /**
     * Best-effort label => value list from the submission snapshot, skipping
     * structural field types with nothing to show. One source of truth for
     * what counts as a real, displayable field, shared by the admin entries
     * panel and the customer-facing "Show Entry Data" box.
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
     * Same data as `submitted_fields()`, reshaped into an ordered list of
     * pairs — an associative array would encode as a JSON object, which
     * loses ordering and collapses duplicate labels.
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

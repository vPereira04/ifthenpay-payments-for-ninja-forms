<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Plugin;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "ifthenpay Entries" screen — lists every payment attempt (pending,
 * paid, failed, cancelled, expired), not just the ones that got paid, so
 * it's the place to check status at a glance.
 *
 * The "ID" column shows the submission's WP post ID, reserved up front for
 * every attempt before Ninja Forms creates the real row.
 */
class EntriesPage
{
    private const PAGE_SLUG        = 'ifthenpay-nf-entries';
    private const DEFAULT_PER_PAGE = 20;
    private const PER_PAGE_OPTIONS = [10, 20, 30];
    private const PER_PAGE_MAX     = 250;
    private const NONCE_ACTION     = 'iftp_nf_entries_delete';

    /**
     * Legacy only — rows created back when the "+ New Payment" popup let the
     * admin pick a method still store this bare value for cash. New rows
     * skip it and go straight to `AD_HOC_LABEL`. Kept so `render_method_cell()`
     * still renders those old rows correctly.
     */
    private const MANUAL_CASH_METHOD = 'cash';

    /**
     * Legacy only — the label `MANUAL_CASH_METHOD` rows and old
     * `AD_HOC_LABEL_PREFIX`-prefixed cash rows used. Kept in sync with what's
     * already stored so `render_method_cell()`'s icon match doesn't break.
     */
    private const MANUAL_CASH_LABEL = 'Dinheiro';

    /**
     * Legacy only — old "+ New Payment" rows stamp their Method as
     * "Ad Hoc - {method}" (e.g. "Ad Hoc - MBWAY", "Ad Hoc - Dinheiro"), from
     * when the popup let the admin pick a method. New rows use `AD_HOC_LABEL`
     * instead. Kept so `render_method_cell()` still renders those old rows.
     */
    private const AD_HOC_LABEL_PREFIX = 'Ad Hoc - ';

    /**
     * Every "+ New Payment" entry is cash taken outside checkout, so this is
     * the only Method value new ad hoc rows ever get — no picker, at
     * Victor's request. Rendered with the Dinheiro icon (`render_method_cell()`).
     */
    private const AD_HOC_LABEL = 'Ad Hoc';

    private SubmissionStore $submissions;
    private SettingsRepository $settings;

    public function __construct(?SubmissionStore $submissions = null, ?SettingsRepository $settings = null)
    {
        $this->submissions = $submissions ?? new SubmissionStore();
        $this->settings    = $settings ?? new SettingsRepository();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_iftp_nf_delete_entries', [$this, 'ajax_delete_entries']);
        add_action('wp_ajax_iftp_nf_update_status', [$this, 'ajax_update_status']);
        add_action('wp_ajax_iftp_nf_create_entry', [$this, 'ajax_create_entry']);
        add_filter('admin_footer_text', [$this, 'inject_ninja_toggle']);
        add_action('admin_enqueue_scripts', [$this, 'add_menu_color_style']);
    }

    /**
     * I color this screen's submenu link green so it stands out. #adminmenu
     * renders on every wp-admin screen, so I attach this to core's own
     * admin-menu stylesheet instead of loading a file of ours everywhere.
     * The !important beats the admin color schemes' own link colors.
     */
    public function add_menu_color_style(): void
    {
        $link = '#adminmenu a[href*="page=' . self::PAGE_SLUG . '"]';

        wp_add_inline_style(
            'admin-menu',
            $link . ' { color: #84cc1e !important; }'
            . $link . ':hover, ' . $link . ':focus, #adminmenu li.current a[href*="page=' . self::PAGE_SLUG . '"] { color: #9ee62a !important; }'
        );
    }

    public function add_menu_page(): void
    {
        add_submenu_page(
            'ninja-forms',
            __('ifthenpay Entries', 'ifthenpay-payments-for-ninja-forms'),
            __('ifthenpay Entries', 'ifthenpay-payments-for-ninja-forms'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (false === strpos($hook, self::PAGE_SLUG)) {
            return;
        }

        $admin_css  = 'assets/css/admin.css';
        $entries_js = 'assets/js/entries.js';

        wp_enqueue_style('iftp-nf-admin', IFTP_NF_URL . $admin_css, [], Plugin::asset_version($admin_css));
        wp_enqueue_script('iftp-nf-entries', IFTP_NF_URL . $entries_js, [], Plugin::asset_version($entries_js), true);

        wp_localize_script('iftp-nf-entries', 'iftpNfEntries', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
            'currentFilters' => $this->current_filters(),
            'columns'        => $this->organizable_columns(),
            'bulkActions'    => [
                ['value' => 'paid', 'label' => __('Mark as paid', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#007017'],
                ['value' => 'pending', 'label' => __('Mark as pending', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#8a6100'],
                ['value' => 'failed', 'label' => __('Mark as failed', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#a72b2b'],
                ['value' => 'expired', 'label' => __('Mark as expired', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#50575e'],
                ['value' => 'delete', 'label' => __('Delete', 'ifthenpay-payments-for-ninja-forms'), 'danger' => true],
            ],
            'i18n'           => [
                'confirmDeleteTitle'   => __('Delete selected entries?', 'ifthenpay-payments-for-ninja-forms'),
                'confirmDeleteMessage' => __('This only removes their payment-tracking record here — the real Ninja Forms submissions (if any) are left untouched.', 'ifthenpay-payments-for-ninja-forms'),
                'confirmDeleteButton'  => __('Delete', 'ifthenpay-payments-for-ninja-forms'),
                'confirmStatusTitle'   => __('Change status?', 'ifthenpay-payments-for-ninja-forms'),
                /* translators: %s: target status label, e.g. "Paid" */
                'confirmStatusMessage' => __('Mark the selected entries as "%s".', 'ifthenpay-payments-for-ninja-forms'),
                'confirmStatusButton'  => __('Change status', 'ifthenpay-payments-for-ninja-forms'),
                'deleteError'          => __('Could not delete the selected entries. Please try again.', 'ifthenpay-payments-for-ninja-forms'),
                'updateStatusError'    => __('Could not update the selected entries. Please try again.', 'ifthenpay-payments-for-ninja-forms'),
                'deleteToastSuccess'   => __('Selected entries deleted.', 'ifthenpay-payments-for-ninja-forms'),
                'statusToastSuccess'   => __('Selected entries updated.', 'ifthenpay-payments-for-ninja-forms'),
                /* translators: 1: number of selected entries, 2: total number of entries */
                'selectedOfTotal'      => __('%1$s selected out of %2$s', 'ifthenpay-payments-for-ninja-forms'),
                'calendarPrevMonth'    => __('Previous month', 'ifthenpay-payments-for-ninja-forms'),
                'calendarNextMonth'    => __('Next month', 'ifthenpay-payments-for-ninja-forms'),
                'calendarToday'        => __('Today', 'ifthenpay-payments-for-ninja-forms'),
                'calendarClear'        => __('Clear', 'ifthenpay-payments-for-ninja-forms'),
                'columnsReset'         => __('Reset to default', 'ifthenpay-payments-for-ninja-forms'),
                /* translators: %s: column label, e.g. "Customer" */
                'columnsDragLabel'     => __('Drag to reorder %s', 'ifthenpay-payments-for-ninja-forms'),
                /* translators: %s: column label, e.g. "Customer" */
                'columnsToggleLabel'   => __('Show or hide %s', 'ifthenpay-payments-for-ninja-forms'),
                /* translators: %s: column label, e.g. "ID" */
                'columnsLockedPosition' => __('%s always stays in place', 'ifthenpay-payments-for-ninja-forms'),
                /* translators: %s: column label, e.g. "Amount" */
                'columnsLockedVisibility' => __('%s is always shown', 'ifthenpay-payments-for-ninja-forms'),
                'newEntryCreating'     => __('Creating…', 'ifthenpay-payments-for-ninja-forms'),
                'newEntryToastSuccess' => __('Payment recorded.', 'ifthenpay-payments-for-ninja-forms'),
                'newEntryError'        => __('Could not record the payment. Please try again.', 'ifthenpay-payments-for-ninja-forms'),
            ],
        ]);
    }

    /**
     * Easter egg: a near-invisible button in the footer that reveals the
     * peeking ninja. Hidden by default and resets on every page load —
     * nothing persisted. Unlabelled beyond an aria-label; finding it is
     * the point.
     */
    public function inject_ninja_toggle(string $footer_text): string
    {
        $screen = get_current_screen();

        if (! $screen instanceof \WP_Screen || false === strpos($screen->id, self::PAGE_SLUG)) {
            return $footer_text;
        }

        $button = '<button type="button" class="iftp-nf-ninja-toggle" id="iftp-nf-ninja-toggle" aria-label="'
            . esc_attr__('Toggle ninja', 'ifthenpay-payments-for-ninja-forms')
            . '" aria-pressed="false"></button>';

        return $button . $footer_text;
    }

    /**
     * AJAX: deletes the given refs, then re-queries the browser's current
     * filtered/sorted/paginated view and returns a fresh tbody + pagination
     * — so the next page's rows shift up to refill the gap, same as a
     * reload would show.
     */
    public function ajax_delete_entries(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $refs = array_map('sanitize_text_field', wp_unslash((array) ($_POST['refs'] ?? [])));
        $deleted = 0;

        foreach ($refs as $ref) {
            if ('' === $ref || null === $this->submissions->get($ref)) {
                continue;
            }

            $this->submissions->delete($ref);
            $deleted++;
        }

        $search      = sanitize_text_field(wp_unslash($_POST['s'] ?? ''));
        $view_status = sanitize_text_field(wp_unslash($_POST['view_status'] ?? ''));
        $form_filter = absint(wp_unslash($_POST['form_id'] ?? 0));
        $date_from   = $this->sanitize_date(sanitize_text_field(wp_unslash($_POST['date_from'] ?? '')));
        $date_to     = $this->sanitize_date(sanitize_text_field(wp_unslash($_POST['date_to'] ?? '')));
        $orderby     = $this->sanitize_orderby(sanitize_text_field(wp_unslash($_POST['orderby'] ?? '')));
        $order       = '' !== $orderby ? $this->sanitize_order(sanitize_text_field(wp_unslash($_POST['order'] ?? ''))) : '';
        $per_page    = $this->sanitize_per_page(sanitize_text_field(wp_unslash($_POST['per_page'] ?? '')));
        $paged       = max(1, absint(wp_unslash($_POST['paged'] ?? 1)));

        $search_form_ids = [];

        if ('' !== $search) {
            foreach ($this->available_forms() as $id => $label) {
                if (false !== stripos($label, $search)) {
                    $search_form_ids[] = $id;
                }
            }
        }

        $shared_filters = [
            'form_id'         => $form_filter,
            'date_from'       => '' !== $date_from ? strtotime($date_from . ' 00:00:00') : 0,
            'date_to'         => '' !== $date_to ? strtotime($date_to . ' 23:59:59') : 0,
            'search'          => $search,
            'search_form_ids' => $search_form_ids,
        ];

        $result = $this->submissions->query_index($shared_filters + [
            'status'   => $view_status,
            'orderby'  => $orderby,
            'order'    => $order,
            'per_page' => $per_page,
            'paged'    => $paged,
        ]);

        $counts = $this->submissions->status_counts($shared_filters);

        // I pass the browser's actual filter state explicitly — $_GET here
        // is admin-ajax.php's own empty query string, not the page's, so
        // without this the next pagination click would silently reset
        // per_page.
        $base = [
            's'         => $search,
            'status'    => $view_status,
            'form_id'   => $form_filter > 0 ? (string) $form_filter : '',
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'orderby'   => $orderby,
            'order'     => $order,
            'per_page'  => $per_page,
            'paged'     => $result['paged'],
        ];

        ob_start();
        $this->render_pagination($result['paged'], $result['pages'], $base);
        $pagination_html = (string) ob_get_clean();

        wp_send_json_success([
            'deleted'        => $deleted,
            'rowsHtml'       => $this->render_rows_html($result['items']),
            'paginationHtml' => $pagination_html,
            'paged'          => $result['paged'],
            'counts'         => $counts,
            'total'          => $result['total'],
            'totalLabel'     => $this->entries_range_label($result['paged'], $per_page, $result['total']),
        ]);
    }

    /**
     * AJAX: the "+ New Payment" popup's submit (see `render_create_entry_modal()`)
     * — validates, records it via `SubmissionStore::create_manual()`, then
     * refreshes the current filtered/sorted/paginated view the same way
     * `ajax_delete_entries()` does, so the new entry shows up immediately if
     * it matches what's currently on screen. The view's own filter state
     * arrives under `view_*` keys here (unlike the other AJAX handlers),
     * since a plain `form_id` key would be ambiguous with the view's own
     * "All forms" filter — every entry created here is filed under
     * `AdHocForm`'s hidden form, never a form the admin picks.
     */
    public function ajax_create_entry(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $amount = (float) sanitize_text_field(wp_unslash($_POST['amount'] ?? '0'));

        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Enter an amount greater than zero.', 'ifthenpay-payments-for-ninja-forms')], 400);
        }

        $customer_email = sanitize_text_field(wp_unslash($_POST['customer_email'] ?? ''));

        if ('' !== $customer_email && ! is_email($customer_email)) {
            wp_send_json_error(['message' => __('Enter a valid email, or leave it blank.', 'ifthenpay-payments-for-ninja-forms')], 400);
        }

        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));

        $allowed_statuses = [
            SubmissionStore::STATUS_PENDING,
            SubmissionStore::STATUS_PAID,
            SubmissionStore::STATUS_FAILED,
            SubmissionStore::STATUS_EXPIRED,
        ];

        if (! in_array($status, $allowed_statuses, true)) {
            wp_send_json_error(['message' => __('Unknown status.', 'ifthenpay-payments-for-ninja-forms')], 400);
        }

        $customer_name = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));

        // Every entry from this popup is cash taken outside checkout — no
        // method picker, always stamped `AD_HOC_LABEL`.
        $ref = $this->submissions->create_manual($customer_name, $customer_email, $amount, self::AD_HOC_LABEL, $status);

        if (null === $ref) {
            global $wpdb;

            // A real DB failure (bad column, connection hiccup, …) — this is
            // the case that used to report success while the entry quietly
            // never made it into the searchable index at all.
            wp_send_json_error(['message' => sprintf(
                /* translators: %s: the database error */
                __('Could not save the payment: %s', 'ifthenpay-payments-for-ninja-forms'),
                $wpdb->last_error ?: __('unknown database error', 'ifthenpay-payments-for-ninja-forms')
            )], 500);
        }

        $search      = sanitize_text_field(wp_unslash($_POST['view_s'] ?? ''));
        $view_status = sanitize_text_field(wp_unslash($_POST['view_status'] ?? ''));
        $form_filter = absint(wp_unslash($_POST['view_form_id'] ?? 0));
        $date_from   = $this->sanitize_date(sanitize_text_field(wp_unslash($_POST['view_date_from'] ?? '')));
        $date_to     = $this->sanitize_date(sanitize_text_field(wp_unslash($_POST['view_date_to'] ?? '')));
        $orderby     = $this->sanitize_orderby(sanitize_text_field(wp_unslash($_POST['view_orderby'] ?? '')));
        $order       = '' !== $orderby ? $this->sanitize_order(sanitize_text_field(wp_unslash($_POST['view_order'] ?? ''))) : '';
        $per_page    = $this->sanitize_per_page(sanitize_text_field(wp_unslash($_POST['view_per_page'] ?? '')));
        $paged       = max(1, absint(wp_unslash($_POST['view_paged'] ?? 1)));

        $search_form_ids = [];

        if ('' !== $search) {
            foreach ($this->available_forms() as $id => $label) {
                if (false !== stripos($label, $search)) {
                    $search_form_ids[] = $id;
                }
            }
        }

        $shared_filters = [
            'form_id'         => $form_filter,
            'date_from'       => '' !== $date_from ? strtotime($date_from . ' 00:00:00') : 0,
            'date_to'         => '' !== $date_to ? strtotime($date_to . ' 23:59:59') : 0,
            'search'          => $search,
            'search_form_ids' => $search_form_ids,
        ];

        $result = $this->submissions->query_index($shared_filters + [
            'status'   => $view_status,
            'orderby'  => $orderby,
            'order'    => $order,
            'per_page' => $per_page,
            'paged'    => $paged,
        ]);

        $counts = $this->submissions->status_counts($shared_filters);

        $base = [
            's'         => $search,
            'status'    => $view_status,
            'form_id'   => $form_filter > 0 ? (string) $form_filter : '',
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'orderby'   => $orderby,
            'order'     => $order,
            'per_page'  => $per_page,
            'paged'     => $result['paged'],
        ];

        ob_start();
        $this->render_pagination($result['paged'], $result['pages'], $base);
        $pagination_html = (string) ob_get_clean();

        wp_send_json_success([
            'ref'            => $ref,
            'rowsHtml'       => $this->render_rows_html($result['items']),
            'paginationHtml' => $pagination_html,
            'paged'          => $result['paged'],
            'counts'         => $counts,
            'total'          => $result['total'],
            'totalLabel'     => $this->entries_range_label($result['paged'], $per_page, $result['total']),
        ]);
    }

    /**
     * AJAX: bulk-corrects the status of the given refs. This is an
     * explicit admin override, so unlike the webhook path it's allowed to
     * move a record off "paid" too.
     */
    public function ajax_update_status(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->authorize();

        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));

        if (! in_array($status, SubmissionStore::ALL_STATUSES, true)) {
            wp_send_json_error(['message' => __('Unknown status.', 'ifthenpay-payments-for-ninja-forms')], 400);
        }

        $refs = array_map('sanitize_text_field', wp_unslash((array) ($_POST['refs'] ?? [])));
        $updated = 0;

        foreach ($refs as $ref) {
            if ('' === $ref || null === $this->submissions->get($ref)) {
                continue;
            }

            if ($this->submissions->admin_set_status($ref, $status)) {
                $updated++;
            }
        }

        // I recompute counts against the browser's current filtered view
        // so the status tabs and footer total stay accurate without a
        // full reload.
        $search      = sanitize_text_field(wp_unslash($_POST['s'] ?? ''));
        $view_status = sanitize_text_field(wp_unslash($_POST['view_status'] ?? ''));
        $form_filter = absint(wp_unslash($_POST['form_id'] ?? 0));
        $date_from   = $this->sanitize_date(sanitize_text_field(wp_unslash($_POST['date_from'] ?? '')));
        $date_to     = $this->sanitize_date(sanitize_text_field(wp_unslash($_POST['date_to'] ?? '')));
        $per_page    = $this->sanitize_per_page(sanitize_text_field(wp_unslash($_POST['per_page'] ?? '')));
        $paged       = max(1, absint(wp_unslash($_POST['paged'] ?? 1)));

        $search_form_ids = [];

        if ('' !== $search) {
            foreach ($this->available_forms() as $id => $label) {
                if (false !== stripos($label, $search)) {
                    $search_form_ids[] = $id;
                }
            }
        }

        $shared_filters = [
            'form_id'         => $form_filter,
            'date_from'       => '' !== $date_from ? strtotime($date_from . ' 00:00:00') : 0,
            'date_to'         => '' !== $date_to ? strtotime($date_to . ' 23:59:59') : 0,
            'search'          => $search,
            'search_form_ids' => $search_form_ids,
        ];

        $counts = $this->submissions->status_counts($shared_filters);
        $total_result = $this->submissions->query_index($shared_filters + [
            'status'   => $view_status,
            'per_page' => 1,
            'paged'    => 1,
        ]);

        wp_send_json_success([
            'updated'     => $updated,
            'status'      => $status,
            'counts'      => $counts,
            'total'       => $total_result['total'],
            'totalLabel'  => $this->entries_range_label($paged, $per_page, $total_result['total']),
            'matchesView' => '' === $view_status || $view_status === $status,
        ]);
    }

    private function authorize(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'ifthenpay-payments-for-ninja-forms')], 403);
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // I build the index table once (version-flag guarded) so
        // everything below queries that instead of loading every record
        // into PHP each time.
        SubmissionStore::maybe_build_index();

        $filters       = $this->current_filters();
        $search        = $filters['s'];
        $status_filter = $filters['status'];
        $form_filter   = $filters['form_id'];
        $date_from     = $filters['date_from'];
        $date_to       = $filters['date_to'];
        $orderby       = $filters['orderby'];
        $order         = $filters['order'];
        $per_page      = $filters['per_page'];

        $available_forms = $this->available_forms();

        // Form titles aren't stored in the index, so I resolve the search
        // against them here first, then fold the matching form IDs into
        // the query.
        $search_form_ids = [];

        if ('' !== $search) {
            foreach ($available_forms as $id => $label) {
                if (false !== stripos($label, $search)) {
                    $search_form_ids[] = $id;
                }
            }
        }

        $shared_filters = [
            'form_id'         => $form_filter,
            'date_from'       => '' !== $date_from ? strtotime($date_from . ' 00:00:00') : 0,
            'date_to'         => '' !== $date_to ? strtotime($date_to . ' 23:59:59') : 0,
            'search'          => $search,
            'search_form_ids' => $search_form_ids,
        ];

        $result = $this->submissions->query_index($shared_filters + [
            'status'    => $status_filter,
            'orderby'   => $orderby,
            'order'     => $order,
            'per_page'  => $per_page,
            'paged'     => $filters['paged'],
        ]);

        $slice  = $result['items'];
        $total  = $result['total'];
        $pages  = $result['pages'];
        $paged  = $result['paged'];
        $counts = $this->submissions->status_counts($shared_filters);
        ?>

        <div class="wrap iftp-nf-entries" id="iftp-nf-entries">
            <?php $this->render_peeking_ninja(); ?>

            <h1><?php esc_html_e('ifthenpay Entries', 'ifthenpay-payments-for-ninja-forms'); ?></h1>
            <p class="description">
                <?php esc_html_e('Every ifthenpay payment attempt on this site, whether or not it was confirmed.', 'ifthenpay-payments-for-ninja-forms'); ?>
            </p>

            <div class="iftp-nf-entries-card">
                <div class="iftp-nf-entries-card-header">
                    <ul class="iftp-nf-status-tabs">
                        <?php foreach ($this->status_filters() as $slug => $label) : ?>
                            <li>
                                <a
                                    class="iftp-nf-status-tab<?php echo $slug === $status_filter ? ' current' : ''; ?>"
                                    href="<?php echo esc_url($this->filtered_url(['status' => $slug, 'paged' => null])); ?>"
                                ><?php echo esc_html($label); ?> <span class="count" data-iftp-count="<?php echo esc_attr('' === $slug ? '_all' : $slug); ?>"><?php echo esc_html((string) ($counts[$slug] ?? 0)); ?></span></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <span class="iftp-nf-toolbar-divider" aria-hidden="true"></span>

                    <form method="get" class="iftp-nf-entries-filters">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                        <?php if ('' !== $status_filter) : ?>
                            <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>" />
                        <?php endif; ?>

                        <select name="form_id" data-iftp-enhance-select>
                            <option value=""><?php esc_html_e('All forms', 'ifthenpay-payments-for-ninja-forms'); ?></option>
                            <?php foreach ($available_forms as $id => $label) : ?>
                                <option value="<?php echo esc_attr((string) $id); ?>" <?php selected($form_filter, $id); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label class="screen-reader-text" for="iftp-nf-date-from"><?php esc_html_e('From date', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                        <input
                            type="date"
                            id="iftp-nf-date-from"
                            name="date_from"
                            value="<?php echo esc_attr($date_from); ?>"
                            data-iftp-enhance-date
                            data-placeholder="<?php esc_attr_e('From date', 'ifthenpay-payments-for-ninja-forms'); ?>"
                        />

                        <label class="screen-reader-text" for="iftp-nf-date-to"><?php esc_html_e('To date', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                        <input
                            type="date"
                            id="iftp-nf-date-to"
                            name="date_to"
                            value="<?php echo esc_attr($date_to); ?>"
                            data-iftp-enhance-date
                            data-placeholder="<?php esc_attr_e('To date', 'ifthenpay-payments-for-ninja-forms'); ?>"
                        />

                        <div class="iftp-nf-search">
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            <input
                                type="search"
                                name="s"
                                value="<?php echo esc_attr($search); ?>"
                                placeholder="<?php esc_attr_e('Search…', 'ifthenpay-payments-for-ninja-forms'); ?>"
                            />
                        </div>

                        <?php $is_custom_per_page = ! in_array($per_page, self::PER_PAGE_OPTIONS, true); ?>

                        <label class="screen-reader-text" for="iftp-nf-per-page"><?php esc_html_e('Entries per page', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                        <select id="iftp-nf-per-page" name="per_page" data-iftp-enhance-select <?php echo $is_custom_per_page ? 'disabled' : ''; ?>>
                            <?php foreach (self::PER_PAGE_OPTIONS as $option) : ?>
                                <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($per_page, $option); ?>>
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: %d: number of entries per page */
                                        __('%d per page', 'ifthenpay-payments-for-ninja-forms'),
                                        $option
                                    ));
                                    ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom" <?php selected($is_custom_per_page); ?>>
                                <?php esc_html_e('Custom…', 'ifthenpay-payments-for-ninja-forms'); ?>
                            </option>
                        </select>

                        <?php
                        /* translators: %d: maximum number of entries per page allowed */
                        $custom_per_page_label = sprintf(__('Custom per page (max %d)', 'ifthenpay-payments-for-ninja-forms'), self::PER_PAGE_MAX);
                        ?>
                        <label class="screen-reader-text" for="iftp-nf-per-page-custom"><?php echo esc_html($custom_per_page_label); ?></label>
                        <input
                            type="number"
                            id="iftp-nf-per-page-custom"
                            name="per_page"
                            class="iftp-nf-per-page-custom"
                            min="1"
                            max="<?php echo esc_attr((string) self::PER_PAGE_MAX); ?>"
                            placeholder="<?php esc_attr_e('Custom', 'ifthenpay-payments-for-ninja-forms'); ?>"
                            title="<?php echo esc_attr($custom_per_page_label); ?>"
                            value="<?php echo esc_attr($is_custom_per_page ? (string) $per_page : ''); ?>"
                            data-iftp-per-page-custom
                            <?php echo $is_custom_per_page ? '' : 'hidden disabled'; ?>
                        />

                        <button type="submit" class="iftp-nf-entries-filters-buttons button button-primary"><?php esc_html_e('Filter', 'ifthenpay-payments-for-ninja-forms'); ?></button>
                        <a href="<?php echo esc_url($this->filtered_url([], true)); ?>" class="iftp-nf-reset-filters"><?php esc_html_e('Reset filters', 'ifthenpay-payments-for-ninja-forms'); ?></a>
                    </form>

                    <div class="iftp-nf-bulk-actions" data-iftp-bulk-actions hidden>
                        <button
                            type="button"
                            class="iftp-nf-bulk-actions-trigger"
                            data-iftp-bulk-trigger
                            aria-haspopup="listbox"
                            aria-expanded="false"
                        >
                            <?php esc_html_e('Actions', 'ifthenpay-payments-for-ninja-forms'); ?>
                            <svg class="iftp-nf-bulk-actions-arrow" width="10" height="6" viewBox="0 0 10 6" aria-hidden="true">
                                <path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>

                    <button
                        type="button"
                        class="iftp-nf-new-entry-trigger"
                        data-iftp-new-entry-trigger
                        aria-haspopup="dialog"
                        title="<?php esc_attr_e('New payment', 'ifthenpay-payments-for-ninja-forms'); ?>"
                    >
                        <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M8 1.5v13M1.5 8h13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <span class="screen-reader-text"><?php esc_html_e('New payment', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    </button>

                    <div class="iftp-nf-columns-control" data-iftp-columns>
                        <button
                            type="button"
                            class="iftp-nf-columns-trigger"
                            data-iftp-columns-trigger
                            aria-haspopup="dialog"
                            aria-expanded="false"
                        >
                            <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
                                <rect x="1.5" y="2.5" width="4" height="11" rx="1" fill="none" stroke="currentColor" stroke-width="1.3" />
                                <rect x="6.5" y="2.5" width="4" height="11" rx="1" fill="none" stroke="currentColor" stroke-width="1.3" />
                                <rect x="11.5" y="2.5" width="3" height="11" rx="1" fill="none" stroke="currentColor" stroke-width="1.3" />
                            </svg>
                            <?php esc_html_e('Columns', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                    </div>
                </div>

                <div class="iftp-nf-entries-table-wrap">
                    <div class="iftp-nf-table-loading" data-iftp-table-loading hidden role="status" aria-live="polite">
                        <span class="iftp-nf-table-spinner" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e('Loading…', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    </div>
                    <table class="widefat iftp-nf-entries-table">
                        <thead>
                            <tr>
                                <th class="iftp-nf-col-check">
                                    <input type="checkbox" class="iftp-main-checkbox" data-iftp-select-all aria-label="<?php esc_attr_e('Select all', 'ifthenpay-payments-for-ninja-forms'); ?>" />
                                </th>
                                <th class="iftp-nf-col-narrow" data-col="id"><?php $this->render_sort_link('id', __('ID', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                                <th data-col="customer"><?php esc_html_e('Customer', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th data-col="form"><?php esc_html_e('Form', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th data-col="method"><?php esc_html_e('Method', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th class="iftp-nf-col-narrow" data-col="submission_id"><?php esc_html_e('Submission ID', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th class="iftp-nf-col-amount" data-col="amount"><?php $this->render_sort_link('amount', __('Amount', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                                <th data-col="status"><?php $this->render_sort_link('status', __('Status', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                                <th data-col="payment_link"><?php esc_html_e('Payment Link', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th data-col="date"><?php $this->render_sort_link('created', __('Date', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                            </tr>
                        </thead>
                        <tbody data-iftp-entries-body>
                            <?php $this->render_rows($slice); ?>
                        </tbody>
                    </table>
                </div>

                <div class="iftp-nf-entries-card-footer" data-iftp-entries-footer>
                    <div class="iftp-nf-entries-footer-left">
                        <span class="iftp-nf-entries-count" data-iftp-total-count data-total="<?php echo esc_attr((string) $total); ?>">
                            <?php echo esc_html($this->entries_range_label($paged, $per_page, $total)); ?>
                        </span>
                        <span class="iftp-nf-bulk-count" data-iftp-bulk-count hidden></span>
                        <button type="button" class="iftp-nf-bulk-cancel" data-iftp-bulk-cancel hidden>
                            <?php esc_html_e('Cancel', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                    </div>

                    <div data-iftp-pagination><?php $this->render_pagination($paged, $pages); ?></div>
                </div>
            </div>

            <?php $this->render_confirm_modal(); ?>
            <?php $this->render_create_entry_modal(); ?>
            <?php $this->render_toast(); ?>
            <?php $this->render_scroll_to_top_button($per_page); ?>
        </div>
        <?php
    }

    /**
     * The "+ New Payment" popup — for recording a payment taken outside the
     * normal checkout (over the phone, in person), same idea as Contact
     * Form 7's own "+" popups, at Victor's request. A static form (unlike
     * `render_confirm_modal()`, which is filled in by JS per bulk action)
     * submitted via AJAX to `ajax_create_entry()`, which does the real
     * validation — this only needs to get the right fields/options in
     * front of the admin.
     */
    private function render_create_entry_modal(): void
    {
        $statuses = [
            SubmissionStore::STATUS_PENDING => __('Pending', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_PAID    => __('Paid', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_FAILED  => __('Failed', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_EXPIRED => __('Expired', 'ifthenpay-payments-for-ninja-forms'),
        ];
        ?>
        <div class="iftp-nf-modal" data-iftp-new-entry-modal hidden>
            <div class="iftp-nf-modal-overlay" data-iftp-new-entry-overlay></div>
            <div class="iftp-nf-modal-box iftp-nf-modal-box--form" role="dialog" aria-modal="true" aria-labelledby="iftp-nf-new-entry-title">
                <h2 id="iftp-nf-new-entry-title"><?php esc_html_e('New payment', 'ifthenpay-payments-for-ninja-forms'); ?></h2>
                <p><?php esc_html_e('Record a payment taken outside the normal checkout — it appears in the list under "Ad Hoc Payments", like any other entry.', 'ifthenpay-payments-for-ninja-forms'); ?></p>

                <form data-iftp-new-entry-form>
                    <p class="iftp-nf-form-error" data-iftp-new-entry-error hidden></p>

                    <div class="iftp-nf-form-row">
                        <div class="iftp-nf-form-field">
                            <label for="iftp-nf-ne-name"><?php esc_html_e('Customer name', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                            <input type="text" id="iftp-nf-ne-name" name="customer_name" />
                        </div>
                        <div class="iftp-nf-form-field">
                            <label for="iftp-nf-ne-email"><?php esc_html_e('Customer email', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                            <input type="email" id="iftp-nf-ne-email" name="customer_email" />
                        </div>
                    </div>

                    <div class="iftp-nf-form-row">
                        <div class="iftp-nf-form-field">
                            <label for="iftp-nf-ne-amount"><?php esc_html_e('Amount', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                            <input type="number" id="iftp-nf-ne-amount" name="amount" step="0.01" min="0" required />
                        </div>
                        <div class="iftp-nf-form-field">
                            <label for="iftp-nf-ne-status"><?php esc_html_e('Status', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                            <select id="iftp-nf-ne-status" name="status" class="iftp-nf-modal-select" data-iftp-enhance-select>
                                <?php foreach ($statuses as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="iftp-nf-modal-actions">
                        <button type="button" class="iftp-nf-modal-cancel" data-iftp-new-entry-cancel>
                            <?php esc_html_e('Cancel', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                        <button type="submit" class="iftp-nf-modal-confirm" data-iftp-new-entry-submit>
                            <?php esc_html_e('Create', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * One reusable confirm dialog for bulk actions, in place of the
     * jarring native window.confirm(). JS fills in the title/message/
     * button per action right before opening it.
     */
    private function render_confirm_modal(): void
    {
        ?>
        <div class="iftp-nf-modal" data-iftp-modal hidden>
            <div class="iftp-nf-modal-overlay" data-iftp-modal-overlay></div>
            <div class="iftp-nf-modal-box" role="alertdialog" aria-modal="true" aria-labelledby="iftp-nf-modal-title">
                <h2 id="iftp-nf-modal-title" data-iftp-modal-title></h2>
                <p data-iftp-modal-message></p>
                <div class="iftp-nf-modal-actions">
                    <button type="button" class="iftp-nf-modal-cancel" data-iftp-modal-cancel>
                        <?php esc_html_e('Cancel', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </button>
                    <button type="button" class="iftp-nf-modal-confirm" data-iftp-modal-confirm></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * One reusable toast for bulk-action feedback, in place of
     * window.alert(). Filled in and shown/hidden entirely by JS.
     */
    private function render_toast(): void
    {
        ?>
        <div class="iftp-nf-toast" data-iftp-toast hidden role="status" aria-live="polite">
            <span class="iftp-nf-toast-message" data-iftp-toast-message></span>
        </div>
        <?php
    }

    /**
     * The tbody contents for a page of entries, buffered for the AJAX
     * handlers so a deleted row's page refills from the next page instead
     * of just leaving a gap.
     *
     * @param array<int, array<string, mixed>> $slice
     */
    private function render_rows_html(array $slice): string
    {
        ob_start();
        $this->render_rows($slice);

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $slice
     */
    private function render_rows(array $slice): void
    {
        if ([] === $slice) {
            ?>
            <tr>
                <td colspan="10" class="iftp-nf-entries-empty">
                    <div class="iftp-nf-empty-state">
                        <span class="iftp-nf-empty-icon" aria-hidden="true">&#128179;</span>
                        <?php esc_html_e('No ifthenpay payments match this view.', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </div>
                </td>
            </tr>
            <?php
        } else {
            foreach ($slice as $record) {
                $this->render_row($record);
            }
        }
    }

    /**
     * "X - Y out of Z" footer label. Blank at zero since the table body
     * already shows its own empty-state message.
     */
    private function entries_range_label(int $paged, int $per_page, int $total): string
    {
        if ($total <= 0) {
            return '';
        }

        $first = ($paged - 1) * $per_page + 1;
        $last  = min($paged * $per_page, $total);

        return sprintf(
            /* translators: 1: first entry number shown, 2: last entry number shown, 3: total number of entries */
            __('%1$d - %2$d out of %3$d', 'ifthenpay-payments-for-ninja-forms'),
            $first,
            $last,
            $total
        );
    }

    /**
     * Pagination — prev/next buttons, page-number pills, and a
     * jump-to-page field. Shows a 4-page window around the current page.
     */
    /**
     * @param array<string, mixed>|null $base Filter/sort state to build
     *     links from instead of $_GET — needed when called from an AJAX
     *     handler, where $_GET reflects the admin-ajax.php request, not
     *     the page.
     */
    private function render_pagination(int $paged, int $pages, ?array $base = null): void
    {
        if ($pages <= 1) {
            return;
        }

        $window_start = max(1, $paged - 1);
        $window_end   = min($paged + 2, $pages);

        if (($window_end - $window_start) < 3) {
            $window_end = min($window_start + 3, $pages);

            if (($window_end - $window_start) < 3) {
                $window_start = max(1, $window_end - 3);
            }
        }

        $show_page_one       = $window_start > 1;
        $show_left_ellipsis  = $window_start > 2;
        $show_last           = $window_end < $pages;
        $show_right_ellipsis = $show_last && $window_end < $pages - 1;
        ?>
        <nav class="iftp-nf-pagination" aria-label="<?php esc_attr_e('Entries pagination', 'ifthenpay-payments-for-ninja-forms'); ?>">
            <?php $this->render_pagination_nav_link($paged - 1, $paged > 1, __('Previous page', 'ifthenpay-payments-for-ninja-forms'), 'prev', $base); ?>

            <span class="iftp-nf-page-numbers">
                <?php if ($show_page_one) : ?>
                    <a class="iftp-nf-page-number" href="<?php echo esc_url($this->filtered_url(['paged' => 1], false, $base)); ?>" aria-label="<?php esc_attr_e('Page 1', 'ifthenpay-payments-for-ninja-forms'); ?>">1</a>
                <?php endif; ?>

                <?php if ($show_left_ellipsis) : ?>
                    <span class="iftp-nf-page-ellipsis" aria-hidden="true">&hellip;</span>
                <?php endif; ?>

                <?php for ($page = $window_start; $page <= $window_end; $page++) : ?>
                    <?php if ($page === $paged) : ?>
                        <span class="iftp-nf-page-number is-current" aria-current="page"><?php echo esc_html((string) $page); ?></span>
                    <?php else : ?>
                        <a
                            class="iftp-nf-page-number"
                            href="<?php echo esc_url($this->filtered_url(['paged' => $page], false, $base)); ?>"
                            aria-label="<?php echo esc_attr(sprintf(
                                /* translators: %d: page number */
                                __('Page %d', 'ifthenpay-payments-for-ninja-forms'),
                                $page
                            )); ?>"
                        ><?php echo esc_html((string) $page); ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($show_right_ellipsis) : ?>
                    <input
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="iftp-nf-page-jump-input"
                        data-total="<?php echo esc_attr((string) $pages); ?>"
                        data-base-url="<?php echo esc_url($this->filtered_url(['paged' => null], false, $base)); ?>"
                        placeholder="&hellip;"
                        title="<?php esc_attr_e('Type a page number and press Enter', 'ifthenpay-payments-for-ninja-forms'); ?>"
                        autocomplete="off"
                    />
                <?php endif; ?>

                <?php if ($show_last) : ?>
                    <a
                        class="iftp-nf-page-number"
                        href="<?php echo esc_url($this->filtered_url(['paged' => $pages], false, $base)); ?>"
                        aria-label="<?php echo esc_attr(sprintf(
                            /* translators: %d: page number */
                            __('Page %d', 'ifthenpay-payments-for-ninja-forms'),
                            $pages
                        )); ?>"
                    ><?php echo esc_html((string) $pages); ?></a>
                <?php endif; ?>
            </span>

            <?php $this->render_pagination_nav_link($paged + 1, $paged < $pages, __('Next page', 'ifthenpay-payments-for-ninja-forms'), 'next', $base); ?>
        </nav>
        <?php
    }

    /**
     * @param array<string, mixed>|null $base See `render_pagination()`.
     */
    private function render_pagination_nav_link(int $target_page, bool $enabled, string $label, string $direction, ?array $base = null): void
    {
        if ($enabled) {
            echo '<a class="iftp-nf-page-btn" href="' . esc_url($this->filtered_url(['paged' => $target_page], false, $base)) . '" aria-label="' . esc_attr($label) . '">';
        } else {
            echo '<span class="iftp-nf-page-btn is-disabled" aria-hidden="true">';
        }

        echo '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="'
            . esc_attr('prev' === $direction ? '15 18 9 12 15 6' : '9 18 15 12 9 6')
            . '" /></svg>';

        echo $enabled ? '</a>' : '</span>';
    }

    /**
     * Sticky "scroll to top" button — shown once scrolled down, and only
     * when there's enough on the page (>10 rows) to make scrolling back
     * up tedious.
     */
    private function render_scroll_to_top_button(int $per_page): void
    {
        ?>
        <button
            type="button"
            id="iftp-nf-scroll-btn"
            class="iftp-nf-scroll-btn"
            data-per-page="<?php echo esc_attr((string) $per_page); ?>"
            aria-label="<?php esc_attr_e('Scroll to top', 'ifthenpay-payments-for-ninja-forms'); ?>"
        >
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="12" y1="19" x2="12" y2="6" />
                <polyline points="6 12 12 6 18 12" />
            </svg>
        </button>
        <?php
    }

    /**
     * @param array<string, mixed> $record
     */
    private function render_row(array $record): void
    {
        // sub_id is the WP post ID, reserved up front for every attempt —
        // it's what the payment reference is built from and what links
        // to the edit screen. Missing only for the rare form with no
        // "save" action.
        $sub_id  = $record['data']['actions']['save']['sub_id'] ?? null;
        // Test-mode attempts never get a real submission, so there's no
        // sub_id — I show the TEST_n reference instead, badged so it's
        // never mistaken for a real payment.
        $is_test = ! empty($record['is_test']);
        $row_id  = 'iftp-nf-details-' . sanitize_html_class($record['ref']);
        $customer    = $this->customer_info($record);
        $payment_url = (string) ($record['payment_url'] ?? '');
        // Ninja Forms' own per-form sequential "Submission ID" — its own
        // Submissions screen's "#" column. Its own table column now
        // (`organizable_columns()`), not tucked inside the details panel.
        $seq_num = $record['data']['actions']['save']['seq_num'] ?? null;
        ?>
        <tr class="iftp-nf-entry-row" data-iftp-ref="<?php echo esc_attr($record['ref']); ?>">
            <td class="iftp-nf-col-check">
                <input type="checkbox" class="iftp-nf-row-check" data-iftp-row-check value="<?php echo esc_attr($record['ref']); ?>" aria-label="<?php esc_attr_e('Select entry', 'ifthenpay-payments-for-ninja-forms'); ?>" />
            </td>
            <td class="iftp-nf-col-narrow" data-col="id">
                <span class="iftp-nf-id-value">
                    <?php if ($is_test) : ?>
                        <span class="iftp-nf-test-badge"><?php esc_html_e('TEST', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                        <?php echo esc_html($record['ref']); ?>
                    <?php else : ?>
                        <?php $this->render_value(null !== $sub_id ? (string) $sub_id : ''); ?>
                    <?php endif; ?>
                </span>
                <?php $this->render_id_row_actions($record, $sub_id, $row_id); ?>
            </td>
            <td class="iftp-nf-col-customer" data-col="customer">
                <?php
                // Both lines always render, even blank, and admin.css gives
                // each a one-line min-height, so rows don't vary in height
                // with what got submitted (at Victor's request).
                ?>
                <div class="iftp-nf-customer-name">
                    <?php
                    if ('' !== $customer['name']) {
                        echo esc_html($customer['name']);
                    } elseif ('' === $customer['email']) {
                        $this->render_value('');
                    }
                    ?>
                </div>
                <div class="iftp-nf-customer-email"><?php echo esc_html($customer['email']); ?></div>
            </td>
            <td data-col="form"><?php echo esc_html($this->form_title((int) $record['form_id'])); ?></td>
            <td data-col="method"><?php $this->render_method_cell((string) $record['pay_method']); ?></td>
            <td class="iftp-nf-col-narrow" data-col="submission_id"><?php $this->render_value(null !== $seq_num ? (string) $seq_num : ''); ?></td>
            <td class="iftp-nf-col-amount" data-col="amount"><?php echo esc_html(number_format((float) $record['amount'], 2)); ?></td>
            <td data-col="status">
                <?php
                // Cancelled reads as "Failed" here — its own status tab and
                // bulk action are gone (see `status_filters()`), at
                // Victor's request. The stored status is untouched; this is
                // display-only.
                $display_status = SubmissionStore::STATUS_CANCELLED === $record['status'] ? SubmissionStore::STATUS_FAILED : $record['status'];
                ?>
                <span class="iftp-nf-status-badge iftp-nf-status-badge--<?php echo esc_attr($display_status); ?>">
                    <?php echo esc_html(ucfirst($display_status)); ?>
                </span>
            </td>
            <td data-col="payment_link">
                <?php if ('' !== $payment_url) : ?>
                    <a class="iftp-nf-payment-link-btn" href="<?php echo esc_url($payment_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Open', 'ifthenpay-payments-for-ninja-forms'); ?>
                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    </a>
                <?php else : ?>
                    <span class="iftp-nf-payment-link-empty"><?php $this->render_value(''); ?></span>
                <?php endif; ?>
            </td>
            <td data-col="date"><?php echo esc_html((string) wp_date('Y-m-d H:i', (int) $record['created_at'])); ?></td>
        </tr>
        <tr id="<?php echo esc_attr($row_id); ?>" class="iftp-nf-entry-details" hidden>
            <td colspan="10">
                <?php $this->render_details($record); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Hover-revealed row actions under the ID, in a single horizontal line
     * (at Victor's request — the vertical stacked version took up too much
     * space) — "View" toggles the details panel (moved here from the ID
     * itself, which is now plain text); "Edit Fields" jumps to the real
     * Ninja Forms submission (only once there's one to edit); "Delete"
     * removes just this entry, reusing the same confirm-modal flow as bulk
     * delete.
     *
     * @param array<string, mixed> $record
     * @param int|string|null      $sub_id
     */
    private function render_id_row_actions(array $record, $sub_id, string $row_id): void
    {
        $has_sub = null !== $sub_id;
        ?>
        <div class="iftp-nf-row-actions">
            <button type="button" class="button-link iftp-nf-row-action iftp-nf-row-action--view iftp-nf-details-toggle" data-details-target="<?php echo esc_attr($row_id); ?>">
                <?php esc_html_e('View', 'ifthenpay-payments-for-ninja-forms'); ?>
            </button>
            <?php if ($has_sub) : ?>
                <span class="iftp-nf-row-actions-sep" aria-hidden="true">|</span>
                <a class="iftp-nf-row-action iftp-nf-row-action--edit" href="<?php echo esc_url(admin_url('post.php?post=' . (int) $sub_id . '&action=edit')); ?>">
                    <?php esc_html_e('Edit Fields', 'ifthenpay-payments-for-ninja-forms'); ?>
                </a>
            <?php endif; ?>
            <span class="iftp-nf-row-actions-sep" aria-hidden="true">|</span>
            <button type="button" class="button-link iftp-nf-row-action iftp-nf-row-action--delete" data-iftp-row-delete="<?php echo esc_attr($record['ref']); ?>">
                <?php esc_html_e('Delete', 'ifthenpay-payments-for-ninja-forms'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Prints `$value`, or the core list-table "no value" dash when it's
     * empty: a dash on screen, a word for screen readers.
     */
    private function render_value(string $value): void
    {
        if ('' !== $value) {
            echo esc_html($value);

            return;
        }

        echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__('None', 'ifthenpay-payments-for-ninja-forms') . '</span>';
    }

    /**
     * Best-effort customer name/email for the Customer column. Ninja
     * Forms has no fixed "customer" concept, so I look for its
     * First/Last Name and Email field types, falling back to any field
     * labelled "name".
     *
     * @param array<string, mixed> $record
     * @return array{name: string, email: string}
     */
    private function customer_info(array $record): array
    {
        $email    = '';
        $first    = '';
        $last     = '';
        $fallback = '';

        foreach ((array) ($record['data']['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $settings = (array) ($field['settings'] ?? []);
            $type     = (string) ($settings['type'] ?? $field['type'] ?? '');
            $label    = (string) ($settings['label'] ?? $field['label'] ?? '');
            $value    = $field['value'] ?? '';

            if (! is_scalar($value) || '' === (string) $value) {
                continue;
            }

            $value = (string) $value;

            if ('' === $email && 'email' === $type) {
                $email = $value;
            } elseif ('' === $first && 'firstname' === $type) {
                $first = $value;
            } elseif ('' === $last && 'lastname' === $type) {
                $last = $value;
            } elseif ('' === $fallback && false !== stripos($label, 'name')) {
                $fallback = $value;
            }
        }

        $name = trim($first . ' ' . $last);

        return ['name' => '' !== $name ? $name : $fallback, 'email' => $email];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function render_details(array $record): void
    {
        ?>
        <div class="iftp-nf-details-panel">
            <div class="iftp-nf-details-grid">
                <div class="iftp-nf-detail-item">
                    <span class="iftp-nf-detail-label"><?php esc_html_e('Reference', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <code class="iftp-nf-detail-code"><?php echo esc_html($record['ref']); ?></code>
                </div>
                <div class="iftp-nf-detail-item">
                    <span class="iftp-nf-detail-label"><?php esc_html_e('Request ID', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <span class="iftp-nf-detail-value"><?php $this->render_value((string) ($record['request_id'] ?? '')); ?></span>
                </div>
                <div class="iftp-nf-detail-item">
                    <span class="iftp-nf-detail-label"><?php esc_html_e('Transaction ID', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <span class="iftp-nf-detail-value">
                    <?php
                    // I capture this as soon as the browser returns from a
                    // success redirect, independent of "Request ID" above
                    // (which only gets set once the payment actually confirms
                    // paid) — so it stays visible even if confirmation never
                    // came through.
                    $this->render_value((string) ($record['transaction_id'] ?? ''));
                    ?>
                    </span>
                </div>
                <div class="iftp-nf-detail-item">
                    <span class="iftp-nf-detail-label"><?php esc_html_e('Payment Link', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <?php $payment_url = (string) ($record['payment_url'] ?? ''); ?>
                    <?php if ('' !== $payment_url) : ?>
                        <a class="iftp-nf-detail-value iftp-nf-detail-link" href="<?php echo esc_url($payment_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html($payment_url); ?>
                        </a>
                    <?php else : ?>
                        <span class="iftp-nf-detail-value"><?php $this->render_value(''); ?></span>
                    <?php endif; ?>
                </div>
                <div class="iftp-nf-detail-item">
                    <span class="iftp-nf-detail-label"><?php esc_html_e('Form ID', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <span class="iftp-nf-detail-value">
                    <?php echo esc_html((string) $record['form_id']); ?>
                    </span>
                </div>
                <div class="iftp-nf-detail-item">
                    <span class="iftp-nf-detail-label"><?php esc_html_e('Last Updated', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <span class="iftp-nf-detail-value"><?php echo esc_html((string) wp_date('Y-m-d H:i', (int) $record['updated_at'])); ?></span>
                </div>
            </div>
            <?php
            $fields = $this->submissions->submitted_fields($record);

            if ([] !== $fields) :
                ?>
                <div class="iftp-nf-details-fields">
                    <span class="iftp-nf-detail-label iftp-nf-details-fields-label"><?php esc_html_e('Submitted Fields', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                    <dl class="iftp-nf-details-fields-list">
                        <?php foreach ($fields as $label => $value) : ?>
                            <div class="iftp-nf-details-fields-list__row">
                                <dt><?php echo esc_html($label); ?></dt>
                                <dd><?php echo esc_html($value); ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </div>
                <?php
            endif;
            ?>
        </div>
        <?php
    }


    /**
     * The Ninja Forms "peeking ninja" mascot, purely decorative. Each
     * hover advances a 4-step animation cycle (out, home, out, exit with
     * a smoke puff), and he walks himself home if left mid-cycle. Hidden
     * by default; the footer easter-egg button reveals him, resetting on
     * every reload.
     */
    private function render_peeking_ninja(): void
    {
        ?>
        <div class="nf-spark-peeking-ninja-wrapper nf-shy-ninja-home nf-shy-ninja-hidden" id="nf-peeking-ninja-wrapper">
            <div class="nf-spark-peeking-ninja" id="nf-peeking-ninja">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200.29 89.86">
                    <g>
                        <!-- Cabeça do Ninja -->
                        <path fill="currentColor" d="M171.62,89.86c.02-.17.04-1.65.04-2.48,0-12.97-2.89-25.2-8.05-36.23-.81-2.1-1.92-4.23-3.18-6.37,10.27-3.45,20.32-5.38,27.76-6.9h.01c9.06-1.86,14.26-3.05,11.22-5.54-6.62-5.41-15.03-11.27-15.03-11.27,0,0,33.81-27.94,0-19.48-6.37,1.59-11.96,3.98-16.87,6.79-9.44,5.4-16.29,12.39-20.97,18.48C131.01,11.32,109.55,1.69,85.83,1.69,38.43,1.69,0,39.99,0,87.39c0,.84.04,2.31.04,2.48h171.58Z"></path>

                        <!-- Face/Máscara Branca do Ninja -->
                        <path fill="#fff" d="M53.4,77.76c4.69,0,8.51,5.4,8.62,12.11h47.65c.11-6.71,3.92-12.11,8.62-12.11s8.51,5.4,8.62,12.11h20.31c-.35-10.72-6.21-21.09-16.36-26.7-26.69-14.74-61.81-14.79-89.48-.24-10.46,5.5-16.57,15.14-16.93,26.94h20.33c.11-6.71,3.91-12.11,8.62-12.11Z"></path>

                        <!-- Ícone Ifthenpay (icon-white.svg) - Maior e mais acima -->
                        <image href="<?php echo esc_url(IFTP_NF_URL . 'assets/img/icon-white.svg'); ?>"
                            x="71.5"
                            y="16"
                            width="30"
                            height="30" />
                    </g>
                </svg>
            </div>
            <span class="nf-spark-peeking-ninja-wrapper__smoke" id="nf-peeking-ninja-smoke" aria-hidden="true"></span>
            <span class="nf-spark-peeking-ninja-wrapper__smoke-cluster" aria-hidden="true">
                <?php
                // 22 puffs, each positioned/sized/staggered purely by its
                // `nth-child` index in admin.css — the markup itself doesn't
                // need to tell them apart.
                for ($i = 0; $i < 22; $i++) :
                    ?>
                    <span class="nf-spark-peeking-ninja-wrapper__smoke-puff"></span>
                    <?php
                endfor;
                ?>
            </span>
        </div>
        <?php
    }

    /**
     * Renders the Method column as a logo+label pill when it resolves
     * against the method catalog, falling back to the bare string
     * otherwise. pay_method is only set once a payment confirms paid, so
     * most rows just show the em dash.
     */
    private function render_method_cell(string $pay_method): void
    {
        if ('' === $pay_method) {
            $this->render_value('');

            return;
        }

        if (self::AD_HOC_LABEL === $pay_method) {
            ?>
            <span class="iftp-nf-method-pill">
                <img src="<?php echo esc_url(IFTP_NF_URL . 'assets/img/cash.svg'); ?>" alt="" loading="lazy" />
                <?php echo esc_html(self::AD_HOC_LABEL); ?>
            </span>
            <?php

            return;
        }

        if (0 === strpos($pay_method, self::AD_HOC_LABEL_PREFIX)) {
            $method_label = substr($pay_method, strlen(self::AD_HOC_LABEL_PREFIX));
            $logo         = self::MANUAL_CASH_LABEL === $method_label
                ? (IFTP_NF_URL . 'assets/img/cash.svg')
                : (string) ($this->method_catalog()[$method_label] ?? '');
            ?>
            <span class="iftp-nf-method-pill">
                <?php if ('' !== $logo) : ?>
                    <img src="<?php echo esc_url($logo); ?>" alt="" loading="lazy" />
                <?php endif; ?>
                <?php echo esc_html($pay_method); ?>
            </span>
            <?php

            return;
        }

        // A bare (unprefixed) MANUAL_CASH_METHOD value only exists on rows
        // created before AD_HOC_LABEL_PREFIX landed — kept so those don't
        // regress to a plain unstyled string.
        if (self::MANUAL_CASH_METHOD === $pay_method) {
            ?>
            <span class="iftp-nf-method-pill">
                <img src="<?php echo esc_url(IFTP_NF_URL . 'assets/img/cash.svg'); ?>" alt="" loading="lazy" />
                <?php esc_html_e('Dinheiro', 'ifthenpay-payments-for-ninja-forms'); ?>
            </span>
            <?php

            return;
        }

        $logo = $this->method_catalog()[$pay_method] ?? null;

        if (null === $logo) {
            echo esc_html($pay_method);

            return;
        }
        ?>
        <span class="iftp-nf-method-pill">
            <?php if ('' !== $logo) : ?>
                <img src="<?php echo esc_url($logo); ?>" alt="" loading="lazy" />
            <?php endif; ?>
            <?php echo esc_html($pay_method); ?>
        </span>
        <?php
    }

    /**
     * @var array<string, string>|null
     */
    private ?array $method_catalog_cache = null;

    /**
     * Entity code (e.g. `MB`) => logo URL, for every connected method — the
     * entity is what actually identifies a method everywhere outside the
     * Settings screen's own connected-methods table (`GatewaySettingsField.php`,
     * the one place `Method`/alias, e.g. `MULTIBANCO`, is meaningful — it's
     * what that table uses to let the admin enable/disable each one). Here
     * and in `render_create_entry_modal()`, `$pay_method` (an entity code)
     * is both the stored value and the label, at Victor's request.
     *
     * @return array<string, string>
     */
    private function method_catalog(): array
    {
        if (null !== $this->method_catalog_cache) {
            return $this->method_catalog_cache;
        }

        $catalog = [];

        foreach ($this->settings->get_methods() as $method) {
            if ('' === $method['entity']) {
                continue;
            }

            $catalog[$method['entity']] = $method['logo'];
        }

        return $this->method_catalog_cache = $catalog;
    }

    /**
     * I memoize this per request — without it, a 10k-row install repeats
     * the same form lookup thousands of times over for a handful of
     * distinct forms.
     *
     * @var array<int, string>
     */
    private array $form_title_cache = [];

    private function form_title(int $form_id): string
    {
        if (array_key_exists($form_id, $this->form_title_cache)) {
            return $this->form_title_cache[$form_id];
        }

        if (! function_exists('Ninja_Forms')) {
            return $this->form_title_cache[$form_id] = (string) $form_id;
        }

        $form = Ninja_Forms()->form($form_id)->get();

        if (null === $form) {
            return $this->form_title_cache[$form_id] = (string) $form_id;
        }

        $settings = $form->get_settings();

        return $this->form_title_cache[$form_id] = (string) ($settings['title'] ?? $form_id);
    }

    /**
     * Rebuilds the current URL with the given params overridden — passing
     * null for a param removes it.
     *
     * @param array<string, mixed> $overrides
     */
    /**
     * @param array<string, mixed>|null $base Filter/sort state to build
     *     the URL from instead of $_GET — pass this when called outside a
     *     normal page GET request (e.g. from an AJAX handler).
     * @param array<string, mixed> $overrides
     */
    private function filtered_url(array $overrides, bool $reset = false, ?array $base = null): string
    {
        if ($reset) {
            return add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php'));
        }

        if (null === $base) {
            $base            = $this->current_filters();
            $base['form_id'] = $base['form_id'] > 0 ? (string) $base['form_id'] : '';
        }

        $args = ['page' => self::PAGE_SLUG] + $base;

        foreach ($overrides as $key => $value) {
            if (null === $value) {
                unset($args[$key]);
            } else {
                $args[$key] = $value;
            }
        }

        $args = array_filter($args, static fn ($v): bool => '' !== $v && null !== $v);

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * @return array<int, string>
     */
    private function available_forms(): array
    {
        $forms = [];

        foreach ($this->submissions->distinct_form_ids() as $form_id) {
            $forms[$form_id] = $this->form_title($form_id);
        }

        asort($forms);

        return $forms;
    }

    /**
     * The filter/search/status/pagination/sort state from the current
     * request. Localized into JS too, so a bulk AJAX action can recompute
     * counts against this exact view instead of a stale or default one.
     *
     * @return array{s: string, status: string, form_id: int, date_from: string, date_to: string, per_page: int, paged: int, orderby: string, order: string}
     */
    private function current_filters(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters for this screen; nothing is saved.
        $orderby = $this->sanitize_orderby(sanitize_text_field(wp_unslash($_GET['orderby'] ?? '')));

        return [
            's'         => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'status'    => sanitize_text_field(wp_unslash($_GET['status'] ?? '')),
            'form_id'   => absint(wp_unslash($_GET['form_id'] ?? 0)),
            'date_from' => $this->sanitize_date(sanitize_text_field(wp_unslash($_GET['date_from'] ?? ''))),
            'date_to'   => $this->sanitize_date(sanitize_text_field(wp_unslash($_GET['date_to'] ?? ''))),
            'per_page'  => $this->sanitize_per_page(sanitize_text_field(wp_unslash($_GET['per_page'] ?? ''))),
            'paged'     => max(1, absint(wp_unslash($_GET['paged'] ?? 1))),
            'orderby'   => $orderby,
            'order'     => '' !== $orderby ? $this->sanitize_order(sanitize_text_field(wp_unslash($_GET['order'] ?? ''))) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Validates a Y-m-d date value, discarding anything malformed instead
     * of passing it through as-is.
     */
    private function sanitize_date(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * Validates orderby against the sortable columns; anything else falls
     * back to the default reverse-chronological order.
     */
    private function sanitize_orderby(string $value): string
    {
        return in_array($value, ['id', 'amount', 'status', 'created', 'updated'], true) ? $value : '';
    }

    private function sanitize_order(string $value): string
    {
        return 'asc' === strtolower($value) ? 'asc' : 'desc';
    }

    /**
     * Accepts a preset option or a custom admin-typed value, clamped to
     * [1, PER_PAGE_MAX] rather than rejected outright. Only a blank or
     * non-numeric value falls back to the default.
     */
    private function sanitize_per_page(string $raw): int
    {
        if ('' === $raw || ! is_numeric($raw)) {
            return self::DEFAULT_PER_PAGE;
        }

        return max(1, min(self::PER_PAGE_MAX, (int) $raw));
    }

    /**
     * A sortable column header: a link with a small up/down arrow.
     * Clicking toggles direction if already sorted by this column,
     * otherwise starts ascending.
     */
    private function render_sort_link(string $key, string $label, string $current_orderby, string $current_order): void
    {
        $is_sorted  = $key === $current_orderby;
        $next_order = ($is_sorted && 'asc' === $current_order) ? 'desc' : 'asc';

        $classes = 'iftp-nf-sortable';

        if ($is_sorted) {
            $classes .= ' is-sorted iftp-nf-sortable--' . $current_order;
        }

        echo '<a class="' . esc_attr($classes) . '" href="' . esc_url($this->filtered_url(['orderby' => $key, 'order' => $next_order, 'paged' => null])) . '">'
            . esc_html($label)
            . ' <span class="iftp-nf-sort-icon" aria-hidden="true"></span></a>';
    }

    /**
     * @return array<string, string>
     */
    private function status_filters(): array
    {
        return [
            ''                              => __('All', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_PAID    => __('Paid', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_PENDING => __('Pending', 'ifthenpay-payments-for-ninja-forms'),
            // Cancelled entries show under "Failed" instead of their own tab
            // — see `SubmissionStore::build_where()`/`status_counts()`.
            SubmissionStore::STATUS_FAILED  => __('Failed', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_EXPIRED => __('Expired', 'ifthenpay-payments-for-ninja-forms'),
        ];
    }

    /**
     * Every column the "Columns" control lets the admin reorder/hide, in
     * default order. The checkbox column isn't included — it's
     * structural, not a data column.
     *
     * positionLocked pins ID/Customer first; visibilityLocked (ID,
     * Customer, Amount, Status) keeps the columns an admin needs at a
     * glance always shown.
     *
     * @return array<int, array{key: string, label: string, positionLocked: bool, visibilityLocked: bool}>
     */
    private function organizable_columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('ID', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => true, 'visibilityLocked' => true],
            ['key' => 'customer', 'label' => __('Customer', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => true, 'visibilityLocked' => true],
            ['key' => 'form', 'label' => __('Form', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'method', 'label' => __('Method', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'submission_id', 'label' => __('Submission ID', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'amount', 'label' => __('Amount', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => true],
            ['key' => 'status', 'label' => __('Status', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => true],
            ['key' => 'payment_link', 'label' => __('Payment Link', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'date', 'label' => __('Date', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
        ];
    }
}

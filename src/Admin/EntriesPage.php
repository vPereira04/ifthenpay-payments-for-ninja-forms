<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;
use Ifthenpay\NinjaForms\Repository\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A dedicated "ifthenpay Entries" screen listing every payment attempt —
 * pending, paid, failed, cancelled, expired — straight from `SubmissionStore`.
 *
 * The primary place to check payment status at a glance: it shows every
 * attempt, not just the ones that ended up paid. Its "ID" column is the
 * submission's WP post ID (`sub_id`) — reserved up front for every attempt
 * (see `Gateway\IfthenpayGateway::reserve_submission()`) and the same value
 * `Gateway\IfthenpayGateway::generate_reference()` builds the payment
 * reference from, at Victor's request. Ninja Forms' own per-form
 * "Submission ID" (`seq_num`) and the form ID are shown alongside the
 * reference in each row's details instead (`render_details()`).
 */
class EntriesPage
{
    private const PAGE_SLUG        = 'ifthenpay-nf-entries';
    private const DEFAULT_PER_PAGE = 20;
    private const PER_PAGE_OPTIONS = [10, 20, 30];
    private const NONCE_ACTION     = 'iftp_nf_entries_delete';

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
        add_filter('admin_footer_text', [$this, 'inject_ninja_toggle']);
        add_action('admin_head', [$this, 'print_menu_color_style']);
    }

    /**
     * Colors this screen's own sidebar submenu link — under "Ninja Forms" —
     * green, to make it stand out from the rest of Ninja Forms' own (plain)
     * submenu items. `#adminmenu` renders on every wp-admin screen, not just
     * this one, so this has to run unconditionally on `admin_head` rather
     * than being folded into `enqueue_assets()` (which only fires here).
     * `!important` guards against WP core's own admin-menu selectors, which
     * are otherwise easily specific enough to win.
     */
    public function print_menu_color_style(): void
    {
        ?>
        <style>
            #adminmenu a[href*="page=<?php echo esc_attr(self::PAGE_SLUG); ?>"] {
                color: #84cc1e !important;
            }

            #adminmenu a[href*="page=<?php echo esc_attr(self::PAGE_SLUG); ?>"]:hover,
            #adminmenu a[href*="page=<?php echo esc_attr(self::PAGE_SLUG); ?>"]:focus,
            #adminmenu li.current a[href*="page=<?php echo esc_attr(self::PAGE_SLUG); ?>"] {
                color: #9ee62a !important;
            }
        </style>
        <?php
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

        wp_enqueue_style('iftp-nf-admin', IFTP_NF_URL . $admin_css, [], (string) filemtime(IFTP_NF_PATH . $admin_css));
        wp_enqueue_script('iftp-nf-entries', IFTP_NF_URL . $entries_js, [], (string) filemtime(IFTP_NF_PATH . $entries_js), true);

        wp_localize_script('iftp-nf-entries', 'iftpNfEntries', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
            'currentFilters' => $this->current_filters(),
            'columns'        => $this->organizable_columns(),
            'bulkActions'    => [
                ['value' => 'paid', 'label' => __('Mark as paid', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#007017'],
                ['value' => 'pending', 'label' => __('Mark as pending', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#8a6100'],
                ['value' => 'failed', 'label' => __('Mark as failed', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#a72b2b'],
                ['value' => 'cancelled', 'label' => __('Mark as cancelled', 'ifthenpay-payments-for-ninja-forms'), 'dot' => '#a72b2b'],
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
                'selectedOne'          => __('selected', 'ifthenpay-payments-for-ninja-forms'),
                'selectedMany'         => __('selected', 'ifthenpay-payments-for-ninja-forms'),
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
            ],
        ]);
    }

    /**
     * Easter egg: slips an almost-invisible dot button into the WP admin
     * footer text, right before "Thank you for creating with WordPress." —
     * sitting exactly where the peeking ninja (see `render_peeking_ninja()`)
     * rests, between the admin sidebar and that footer line. Clicking it
     * reveals the ninja, who otherwise stays hidden by default (see the
     * `nf-shy-ninja-hidden` class in `render_peeking_ninja()`) — a fresh
     * page load always resets him back to hidden; nothing is persisted.
     *
     * Deliberately unlabelled beyond an aria-label for screen readers —
     * finding it is the point.
     */
    public function inject_ninja_toggle(string $footer_text): string
    {
        $screen = get_current_screen();

        if (! $screen instanceof \WP_Screen || false === strpos($screen->id, self::PAGE_SLUG)) {
            return $footer_text;
        }

        $button = sprintf(
            '<button type="button" class="iftp-nf-ninja-toggle" id="iftp-nf-ninja-toggle" aria-label="%s" aria-pressed="false"></button>',
            esc_attr__('Toggle ninja', 'ifthenpay-payments-for-ninja-forms')
        );

        return $button . $footer_text;
    }

    /**
     * AJAX: deletes the payment-tracking records for the given `refs` —
     * see `SubmissionStore::delete()` for what this does and doesn't touch —
     * then re-queries the exact same filtered/searched/sorted/paginated view
     * the browser currently has open (posted alongside `refs`, see
     * `iftpNfEntries.currentFilters`) and hands back a freshly rendered
     * tbody and pagination for it. Deleting entries off a page shouldn't
     * just leave it short — the next page's rows (or, if the current page
     * no longer exists, the new last page's — `SubmissionStore::query_index()`
     * clamps `paged` for us) need to shift up to refill it, exactly like a
     * plain page reload would show.
     */
    public function ajax_delete_entries(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'ifthenpay-payments-for-ninja-forms')], 403);
        }

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
        $form_filter = (int) ($_POST['form_id'] ?? 0);
        $date_from   = $this->sanitize_date($_POST['date_from'] ?? '');
        $date_to     = $this->sanitize_date($_POST['date_to'] ?? '');
        $orderby     = $this->sanitize_orderby($_POST['orderby'] ?? '');
        $order       = '' !== $orderby ? $this->sanitize_order($_POST['order'] ?? '') : '';
        $per_page    = $this->sanitize_per_page($_POST['per_page'] ?? '');
        $paged       = max(1, (int) ($_POST['paged'] ?? 1));

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

        ob_start();
        $this->render_pagination($result['paged'], $result['pages']);
        $pagination_html = (string) ob_get_clean();

        wp_send_json_success([
            'deleted'        => $deleted,
            'rowsHtml'       => $this->render_rows_html($result['items']),
            'paginationHtml' => $pagination_html,
            'paged'          => $result['paged'],
            'counts'         => $counts,
            'totalLabel'     => sprintf(
                /* translators: %d: number of entries */
                _n('%d entry', '%d entries', $result['total'], 'ifthenpay-payments-for-ninja-forms'),
                $result['total']
            ),
        ]);
    }

    /**
     * AJAX: bulk-corrects the status of the given `refs` from the Entries
     * screen — an explicit admin override, unlike the webhook/lifecycle path
     * (`SubmissionStore::mark_paid()` etc.), so it's allowed to move a record
     * off "paid" too. See `SubmissionStore::admin_set_status()`.
     */
    public function ajax_update_status(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'ifthenpay-payments-for-ninja-forms')], 403);
        }

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

        // Recomputed against the exact same filtered/searched view the
        // browser currently has open (posted alongside `refs`/`status`, see
        // `iftpNfEntries.currentFilters`), so the status-tab counts and
        // footer total the browser patches in afterwards stay accurate
        // without a full page reload.
        $search      = sanitize_text_field(wp_unslash($_POST['s'] ?? ''));
        $view_status = sanitize_text_field(wp_unslash($_POST['view_status'] ?? ''));
        $form_filter = (int) ($_POST['form_id'] ?? 0);
        $date_from   = $this->sanitize_date($_POST['date_from'] ?? '');
        $date_to     = $this->sanitize_date($_POST['date_to'] ?? '');

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
            'totalLabel'  => sprintf(
                /* translators: %d: number of entries */
                _n('%d entry', '%d entries', $total_result['total'], 'ifthenpay-payments-for-ninja-forms'),
                $total_result['total']
            ),
            'matchesView' => '' === $view_status || $view_status === $status,
        ]);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Builds/rebuilds the index table from `wp_options` the first time
        // only (guarded by a version flag) — see `SubmissionStore::maybe_build_index()`.
        // Everything below runs against that index instead of loading every
        // record into PHP on every single page/filter click.
        SubmissionStore::maybe_build_index();

        $filters       = $this->current_filters();
        $search        = $filters['s'];
        $status_filter = $filters['status'];
        $form_filter   = $filters['form_id'];
        $date_from     = $filters['date_from'];
        $date_to       = $filters['date_to'];
        $orderby       = $this->sanitize_orderby($_GET['orderby'] ?? '');
        $order         = '' !== $orderby ? $this->sanitize_order($_GET['order'] ?? '') : '';
        $per_page      = $this->sanitize_per_page($_GET['per_page'] ?? '');

        $available_forms = $this->available_forms();

        // Form titles aren't stored in the index, so a search has to be
        // resolved against them here first, then folded into the SQL query
        // as a `form_id IN (...)` clause alongside the ref/method/status/amount
        // matching `query_index()` already does directly in SQL.
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
            'paged'     => (int) ($_GET['paged'] ?? 1),
        ]);

        $slice  = $result['items'];
        $total  = $result['total'];
        $pages  = $result['pages'];
        $paged  = $result['paged'];
        $counts = $this->submissions->status_counts($shared_filters);
        ?>

        <div class="wrap iftp-nf-entries">
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
                            <input style="padding-left: 30px !important;"
                                type="search"
                                name="s"
                                value="<?php echo esc_attr($search); ?>"
                                placeholder="<?php esc_attr_e('Search…', 'ifthenpay-payments-for-ninja-forms'); ?>"
                            />
                        </div>

                        <label class="screen-reader-text" for="iftp-nf-per-page"><?php esc_html_e('Entries per page', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                        <select id="iftp-nf-per-page" name="per_page" data-iftp-enhance-select>
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
                        </select>

                        <button type="submit" class="iftp-nf-entries-filters-buttons button button-primary"><?php esc_html_e('Filter', 'ifthenpay-payments-for-ninja-forms'); ?></button>
                        <a href="<?php echo esc_url($this->filtered_url([], true)); ?>" class="iftp-nf-reset-filters"><?php esc_html_e('Reset filters', 'ifthenpay-payments-for-ninja-forms'); ?></a>
                    </form>

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
                                <th class="iftp-nf-col-narrow" data-col="id"><?php echo $this->sort_link('id', __('ID', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                                <th data-col="customer"><?php esc_html_e('Customer', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th data-col="request_id"><?php esc_html_e('Request ID', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th data-col="form"><?php esc_html_e('Form', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th data-col="method"><?php esc_html_e('Method', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th class="iftp-nf-col-amount" data-col="amount"><?php echo $this->sort_link('amount', __('Amount', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                                <th data-col="status"><?php echo $this->sort_link('status', __('Status', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                                <th data-col="payment_link"><?php esc_html_e('Payment Link', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                                <th data-col="date"><?php echo $this->sort_link('created', __('Date', 'ifthenpay-payments-for-ninja-forms'), $orderby, $order); ?></th>
                            </tr>
                        </thead>
                        <tbody data-iftp-entries-body>
                            <?php echo $this->render_rows_html($slice); ?>
                        </tbody>
                    </table>
                </div>

                <div class="iftp-nf-entries-bulk-bar" data-iftp-bulk-bar hidden>
                    <div class="iftp-nf-bulk-bar-left">
                        <span class="iftp-nf-bulk-count" data-iftp-bulk-count></span>
                        <button type="button" class="iftp-nf-bulk-cancel" data-iftp-bulk-cancel>
                            <?php esc_html_e('Cancel', 'ifthenpay-payments-for-ninja-forms'); ?>
                        </button>
                    </div>

                    <div class="iftp-nf-bulk-actions" data-iftp-bulk-actions>
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
                </div>

                <div class="iftp-nf-entries-card-footer">
                    <span class="iftp-nf-entries-count" data-iftp-total-count>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: number of entries */
                                _n('%d entry', '%d entries', $total, 'ifthenpay-payments-for-ninja-forms'),
                                $total
                            )
                        );
                        ?>
                    </span>

                    <div data-iftp-pagination><?php $this->render_pagination($paged, $pages); ?></div>
                </div>
            </div>

            <?php $this->render_confirm_modal(); ?>
            <?php $this->render_toast(); ?>
        </div>
        <?php
    }

    /**
     * A single reusable confirm dialog for the bulk-actions bar (delete,
     * change status) — replaces the browser's native `window.confirm()`,
     * which otherwise breaks the flow of an in-place, no-reload bulk action
     * with a jarring OS-level popup. Its title/message/confirm-button label
     * are filled in by JS per action (`entries.js`, `openConfirmModal()`)
     * right before it opens; only one of these exists on the page.
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
     * A single reusable toast for bulk-action success/error feedback —
     * replaces `window.alert()` and gives the "entries updated in place,
     * no reload" flow a lightweight confirmation instead of no feedback at
     * all. Filled in and shown/hidden entirely by JS (`entries.js`,
     * `showToast()`).
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
     * The `<tbody>` contents for a page of entries — the empty-state row,
     * or one `render_row()` per record. Shared between the initial
     * server-render (`render()`) and `ajax_delete_entries()`, which uses it
     * to hand the browser back a freshly re-paginated tbody (see that
     * method) so deleting entries pulls the next page's rows up to refill
     * the gap instead of just leaving fewer rows on screen until the next
     * navigation.
     *
     * @param array<int, array<string, mixed>> $slice
     */
    private function render_rows_html(array $slice): string
    {
        ob_start();

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
            foreach ($slice as $index => $record) {
                $this->render_row($record, $index);
            }
        }

        return (string) ob_get_clean();
    }

    private function render_pagination(int $paged, int $pages): void
    {
        if ($pages <= 1) {
            return;
        }
        ?>
        <nav class="iftp-nf-pagination" aria-label="<?php esc_attr_e('Entries pagination', 'ifthenpay-payments-for-ninja-forms'); ?>">
            <?php echo $this->pagination_link(1, $paged > 1, __('First page', 'ifthenpay-payments-for-ninja-forms'), '&laquo;', 'iftp-nf-page-btn--edge'); ?>
            <?php echo $this->pagination_link($paged - 1, $paged > 1, __('Previous page', 'ifthenpay-payments-for-ninja-forms'), '&lsaquo;'); ?>

            <span class="iftp-nf-page-label"><?php esc_html_e('Pag.', 'ifthenpay-payments-for-ninja-forms'); ?></span>

            <span class="iftp-nf-page-numbers">
                <?php foreach ($this->page_number_sequence($paged, $pages) as $item) : ?>
                    <?php if ('...' === $item) : ?>
                        <span class="iftp-nf-page-ellipsis">&hellip;</span>
                    <?php elseif ($item === $paged) : ?>
                        <span class="iftp-nf-page-number is-current" aria-current="page"><?php echo esc_html((string) $item); ?></span>
                    <?php else : ?>
                        <a class="iftp-nf-page-number" href="<?php echo esc_url($this->filtered_url(['paged' => $item])); ?>"><?php echo esc_html((string) $item); ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>

            <form method="get" class="iftp-nf-page-jump">
                <?php foreach ($this->hidden_filter_inputs() as $name => $value) : ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" />
                <?php endforeach; ?>
                <label class="screen-reader-text" for="iftp-nf-page-jump-input"><?php esc_html_e('Go to page', 'ifthenpay-payments-for-ninja-forms'); ?></label>
                <input
                    type="number"
                    id="iftp-nf-page-jump-input"
                    name="paged"
                    min="1"
                    max="<?php echo esc_attr((string) $pages); ?>"
                    value="<?php echo esc_attr((string) $paged); ?>"
                />
                <button type="submit" class="iftp-nf-entries-filters-buttons button"><?php esc_html_e('Go', 'ifthenpay-payments-for-ninja-forms'); ?></button>
            </form>

            <?php echo $this->pagination_link($paged + 1, $paged < $pages, __('Next page', 'ifthenpay-payments-for-ninja-forms'), '&rsaquo;'); ?>
            <?php echo $this->pagination_link($pages, $paged < $pages, __('Last page', 'ifthenpay-payments-for-ninja-forms'), '&raquo;', 'iftp-nf-page-btn--edge'); ?>
        </nav>
        <?php
    }

    private function pagination_link(int $target_page, bool $enabled, string $label, string $glyph, string $extra_class = ''): string
    {
        $class = trim('iftp-nf-page-btn ' . $extra_class . ($enabled ? '' : ' is-disabled'));

        if (! $enabled) {
            return sprintf(
                '<span class="%1$s" aria-hidden="true">%2$s</span>',
                esc_attr($class),
                $glyph
            );
        }

        return sprintf(
            '<a class="%1$s" href="%2$s" aria-label="%3$s">%4$s</a>',
            esc_attr($class),
            esc_url($this->filtered_url(['paged' => $target_page])),
            esc_attr($label),
            $glyph
        );
    }

    /**
     * Builds the page-number sequence for pagination, collapsing distant
     * pages behind a single '...' marker — first page, last page, and a
     * window of one neighbour on each side of the current page are always
     * kept.
     *
     * @return array<int, int|string>
     */
    private function page_number_sequence(int $current, int $total): array
    {
        $neighbours = 1;
        $sequence   = [];
        $previous   = 0;

        for ($page = 1; $page <= $total; $page++) {
            $keep = 1 === $page || $total === $page || abs($page - $current) <= $neighbours;

            if (! $keep) {
                continue;
            }

            if ($previous > 0 && $page - $previous > 1) {
                $sequence[] = '...';
            }

            $sequence[] = $page;
            $previous   = $page;
        }

        return $sequence;
    }

    /**
     * The hidden inputs the page-jump form needs so jumping to a page
     * number preserves every other active filter/sort/per-page choice
     * instead of resetting them.
     *
     * @return array<string, string>
     */
    private function hidden_filter_inputs(): array
    {
        $inputs = ['page' => self::PAGE_SLUG];

        $filters = $this->current_filters();

        if ('' !== $filters['s']) {
            $inputs['s'] = $filters['s'];
        }

        if ('' !== $filters['status']) {
            $inputs['status'] = $filters['status'];
        }

        if ($filters['form_id'] > 0) {
            $inputs['form_id'] = (string) $filters['form_id'];
        }

        if ('' !== $filters['date_from']) {
            $inputs['date_from'] = $filters['date_from'];
        }

        if ('' !== $filters['date_to']) {
            $inputs['date_to'] = $filters['date_to'];
        }

        $orderby = $this->sanitize_orderby($_GET['orderby'] ?? '');

        if ('' !== $orderby) {
            $inputs['orderby'] = $orderby;
            $inputs['order']   = $this->sanitize_order($_GET['order'] ?? '');
        }

        $per_page = $this->sanitize_per_page($_GET['per_page'] ?? '');

        if ($per_page !== self::DEFAULT_PER_PAGE) {
            $inputs['per_page'] = (string) $per_page;
        }

        return $inputs;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function render_row(array $record, int $index = 0): void
    {
        // Reserved up front for every attempt (see
        // `Gateway\IfthenpayGateway::reserve_submission()`). `sub_id` (the
        // WP post ID) is shown here, at Victor's request — the same value
        // `Gateway\IfthenpayGateway::generate_reference()` builds the
        // payment reference from, and it's also what links to the real
        // edit screen (`render_details()`). `seq_num` — Ninja Forms' own
        // "Submission ID" (its Submissions screen's "#" column), a per-form
        // sequence — is kept only to show as an extra detail
        // (`render_details()`). Both are only missing for the rare form
        // with no "save" action attached at all.
        $sub_id  = $record['data']['actions']['save']['sub_id'] ?? null;
        // `Gateway\IfthenpayGateway::process()`'s test-mode path: no real
        // submission ever exists for these (Ninja Forms' own `is_preview`
        // flag stopped one from being created), so there's no `sub_id` to
        // show — the `TEST_n` reference itself is the only identifier, and
        // is shown in its place, badged so it's never mistaken for a real
        // customer's payment.
        $is_test = ! empty($record['is_test']);
        $row_id  = 'iftp-nf-details-' . sanitize_html_class($record['ref']);
        // Staggered entrance: each row's fade/slide-in is delayed a little
        // past the previous one's, capped so a long page doesn't leave the
        // last rows waiting a visibly long time for their turn.
        $delay_ms    = min($index * 18, 300);
        $customer    = $this->customer_info($record);
        $payment_url = (string) ($record['payment_url'] ?? '');
        ?>
        <tr class="iftp-nf-entry-row" data-iftp-ref="<?php echo esc_attr($record['ref']); ?>" style="animation-delay: <?php echo esc_attr((string) $delay_ms); ?>ms">
            <td class="iftp-nf-col-check">
                <input type="checkbox" class="iftp-nf-row-check" data-iftp-row-check value="<?php echo esc_attr($record['ref']); ?>" aria-label="<?php esc_attr_e('Select entry', 'ifthenpay-payments-for-ninja-forms'); ?>" />
            </td>
            <td class="iftp-nf-col-narrow" data-col="id">
                <button type="button" class="button-link iftp-nf-details-toggle" data-details-target="<?php echo esc_attr($row_id); ?>">
                    <?php if ($is_test) : ?>
                        <span class="iftp-nf-test-badge"><?php esc_html_e('TEST', 'ifthenpay-payments-for-ninja-forms'); ?></span>
                        <?php echo esc_html($record['ref']); ?>
                    <?php else : ?>
                        <?php echo esc_html(null !== $sub_id ? (string) $sub_id : '—'); ?>
                    <?php endif; ?>
                </button>
                <?php $this->render_id_row_actions($record, $sub_id); ?>
            </td>
            <td class="iftp-nf-col-customer" data-col="customer">
                <?php if ('' !== $customer['name']) : ?>
                    <div class="iftp-nf-customer-name"><?php echo esc_html($customer['name']); ?></div>
                <?php endif; ?>
                <?php if ('' !== $customer['email']) : ?>
                    <div class="iftp-nf-customer-email"><?php echo esc_html($customer['email']); ?></div>
                <?php elseif ('' === $customer['name']) : ?>
                    &mdash;
                <?php endif; ?>
            </td>
            <td data-col="request_id"><?php echo esc_html('' !== $record['request_id'] ? $record['request_id'] : '—'); ?></td>
            <td data-col="form"><?php echo esc_html($this->form_title((int) $record['form_id'])); ?></td>
            <td data-col="method"><?php $this->render_method_cell((string) $record['pay_method']); ?></td>
            <td class="iftp-nf-col-amount" data-col="amount">
                <button type="button" class="button-link iftp-nf-details-toggle" data-details-target="<?php echo esc_attr($row_id); ?>">
                    <?php echo esc_html(number_format((float) $record['amount'], 2)); ?>
                </button>
            </td>
            <td data-col="status">
                <span class="iftp-nf-status-badge iftp-nf-status-badge--<?php echo esc_attr($record['status']); ?>">
                    <?php echo esc_html(ucfirst($record['status'])); ?>
                </span>
            </td>
            <td data-col="payment_link">
                <?php if ('' !== $payment_url) : ?>
                    <a class="iftp-nf-payment-link-btn" href="<?php echo esc_url($payment_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Open', 'ifthenpay-payments-for-ninja-forms'); ?>
                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    </a>
                <?php else : ?>
                    <span class="iftp-nf-payment-link-empty">&mdash;</span>
                <?php endif; ?>
            </td>
            <td data-col="date"><?php echo esc_html(wp_date('Y-m-d H:i', (int) $record['created_at'])); ?></td>
        </tr>
        <tr id="<?php echo esc_attr($row_id); ?>" class="iftp-nf-entry-details" hidden>
            <td colspan="10">
                <?php $this->render_details($record, $sub_id); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Hover-revealed text row actions right under the ID — WP core's own
     * list-table pattern (`WP_List_Table::row_actions()`) rather than a
     * dedicated actions column: reopen the hosted payment link and jump
     * straight to the real Ninja Forms submission — each only shown once
     * there's actually something behind it (no payment link before
     * `Gateway\IfthenpayGateway::process()` reserves one; no submission for
     * a `TEST_n` preview attempt, see `render_row()`). Sits flush under the
     * ID button and fades/slides in on row hover (`admin.css`) rather than
     * a dedicated "Copy Ref" control, at Victor's request.
     *
     * @param array<string, mixed> $record
     */
    private function render_id_row_actions(array $record, $sub_id): void
    {
        $payment_url = (string) ($record['payment_url'] ?? '');
        $has_link    = '' !== $payment_url;
        $has_sub     = null !== $sub_id;

        if (! $has_link && ! $has_sub) {
            return;
        }
        ?>
        <div class="iftp-nf-row-actions">
            <?php if ($has_link) : ?>
                <a href="<?php echo esc_url($payment_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Open Link', 'ifthenpay-payments-for-ninja-forms'); ?>
                </a>
            <?php endif; ?>
            <?php if ($has_link && $has_sub) : ?>
                <span class="iftp-nf-row-actions-sep" aria-hidden="true">|</span>
            <?php endif; ?>
            <?php if ($has_sub) : ?>
                <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $sub_id . '&action=edit')); ?>">
                    <?php esc_html_e('View', 'ifthenpay-payments-for-ninja-forms'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Best-effort customer name/email for the Customer column. Ninja Forms
     * submissions have no fixed "customer" concept — this reads the same
     * submitted-field snapshot as `submitted_fields()`, looking for Ninja
     * Forms' own dedicated First Name / Last Name / Email field types
     * (`ninja-forms/includes/Fields/{FirstName,LastName,Email}.php`), and
     * falling back to any field merely labelled "name" for forms that use a
     * single combined name field instead.
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
    private function render_details(array $record, $sub_id): void
    {
        $seq_num = $record['data']['actions']['save']['seq_num'] ?? null;
        ?>
        <div class="iftp-nf-details-grid">
            <div>
                <strong><?php esc_html_e('Reference', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <code><?php echo esc_html($record['ref']); ?></code>
            </div>
            <div>
                <strong><?php esc_html_e('Request ID', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <?php echo esc_html('' !== $record['request_id'] ? $record['request_id'] : '—'); ?>
            </div>
            <div>
                <strong><?php esc_html_e('Transaction ID', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <?php
                // Captured as soon as the customer's browser returns from a
                // genuine success redirect (see
                // `Ajax\FrontendController::verify_payment()`), independent
                // of "Request ID" above — that one is only ever set once the
                // payment is actually confirmed paid (`SubmissionStore::mark_paid()`),
                // so this stays visible even for a transaction id that never
                // confirmed (e.g. the transaction-status API was transiently
                // unreachable, or the payment was still a Multibanco/Payshop
                // reference at that point).
                $transaction_id = (string) ($record['transaction_id'] ?? '');
                echo esc_html('' !== $transaction_id ? $transaction_id : '—');
                ?>
            </div>
            <div>
                <strong><?php esc_html_e('Payment Link', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <?php $payment_url = (string) ($record['payment_url'] ?? ''); ?>
                <?php if ('' !== $payment_url) : ?>
                    <a href="<?php echo esc_url($payment_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($payment_url); ?>
                    </a>
                <?php else : ?>
                    &mdash;
                <?php endif; ?>
            </div>
            <?php if (null !== $sub_id) : ?>
                <div>
                    <strong><?php esc_html_e('Submission', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $sub_id . '&action=edit')); ?>">
                        <?php esc_html_e('View entry', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </a>
                </div>
            <?php endif; ?>
            <div>
                <strong><?php esc_html_e('Submission ID', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <?php echo esc_html(null !== $seq_num ? (string) $seq_num : '—'); ?>
            </div>
            <div>
                <strong><?php esc_html_e('Form ID', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <?php echo esc_html((string) (int) $record['form_id']); ?>
            </div>
            <div>
                <strong><?php esc_html_e('Last Updated', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <?php echo esc_html(wp_date('Y-m-d H:i', (int) $record['updated_at'])); ?>
            </div>
        </div>
        <?php
        $fields = $this->submissions->submitted_fields($record);

        if ([] !== $fields) :
            ?>
            <table class="widefat">
                <tbody>
                    <?php foreach ($fields as $label => $value) : ?>
                        <tr>
                            <th style="width: 25%;"><?php echo esc_html($label); ?></th>
                            <td><?php echo esc_html($value); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        endif;
    }


    /**
     * The Ninja Forms "peeking ninja" mascot, purely decorative. Rests
     * bottom-left (`nf-shy-ninja-home`, tucked slightly under the page edge
     * so only his head/face shows), wearing the ifthenpay mark on his
     * forehead (`assets/img/icon-white.svg`).
     *
     * Each hover advances one step of a 4-step cycle — right, home, right,
     * then a full exit off-screen to the left — shaking in place before
     * every step. Once he exits left, he automatically (no hover needed)
     * pops a CSS smoke-bomb puff, then a slower smoke cloud blooms over his
     * starting spot while he gets back up into view as it clears, and the
     * hover cycle resets. Left alone for a few seconds, he either trembles
     * gently in place (if at home) or walks himself back home first (if
     * left mid-cycle), then trembles.
     *
     * See `assets/js/entries.js` (`bindPeekingNinja()`) for the state
     * machine and `assets/css/admin.css` for the movement/smoke/idle-tremble
     * rules.
     *
     * Hidden by default (`nf-shy-ninja-hidden`) — only the footer easter-egg
     * dot (`inject_ninja_toggle()`) reveals him, and nothing persists that
     * across reloads, so every fresh page load starts hidden again.
     */
    private function render_peeking_ninja(): void
    {
        ?>
        <div class="nf-spark-peeking-ninja-wrapper nf-shy-ninja-home nf-shy-ninja-hidden" id="nf-peeking-ninja-wrapper">
            <div class="nf-spark-peeking-ninja" id="nf-peeking-ninja">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200.29 89.86">
                    <defs>
                        <style>
                            .peek-face { fill:#fff; }
                            .peek-head { fill:currentColor; }
                        </style>
                    </defs>
                    <g>
                        <!-- Cabeça do Ninja -->
                        <path class="peek-head" d="M171.62,89.86c.02-.17.04-1.65.04-2.48,0-12.97-2.89-25.2-8.05-36.23-.81-2.1-1.92-4.23-3.18-6.37,10.27-3.45,20.32-5.38,27.76-6.9h.01c9.06-1.86,14.26-3.05,11.22-5.54-6.62-5.41-15.03-11.27-15.03-11.27,0,0,33.81-27.94,0-19.48-6.37,1.59-11.96,3.98-16.87,6.79-9.44,5.4-16.29,12.39-20.97,18.48C131.01,11.32,109.55,1.69,85.83,1.69,38.43,1.69,0,39.99,0,87.39c0,.84.04,2.31.04,2.48h171.58Z"></path>

                        <!-- Face/Máscara Branca do Ninja -->
                        <path class="peek-face" d="M53.4,77.76c4.69,0,8.51,5.4,8.62,12.11h47.65c.11-6.71,3.92-12.11,8.62-12.11s8.51,5.4,8.62,12.11h20.31c-.35-10.72-6.21-21.09-16.36-26.7-26.69-14.74-61.81-14.79-89.48-.24-10.46,5.5-16.57,15.14-16.93,26.94h20.33c.11-6.71,3.91-12.11,8.62-12.11Z"></path>

                        <!-- Ícone Ifthenpay (icon-white.svg) - Maior e mais acima -->
                        <image href="/wp-content/plugins/ifthenpay-payments-for-ninja-forms/assets/img/icon-white.svg"
                            x="71.5"
                            y="16"
                            width="30"
                            height="30" />
                    </g>
                </svg>
            </div>
            <span class="nf-spark-peeking-ninja-wrapper__smoke" id="nf-peeking-ninja-smoke" aria-hidden="true"></span>
        </div>
        <?php
    }

    /**
     * Renders the Method column as a small logo+label pill (matching the
     * reference design) when the value resolves against the connected
     * account's method catalog (`SettingsRepository::get_methods()` — the
     * same list `Admin\GatewaySettingsField` renders, already synced with
     * ifthenpay's logos by `Sync\GatewaySync`), falling back to the bare
     * string for anything that doesn't resolve (e.g. `pay_method` is only
     * ever set once a payment actually confirms paid, see
     * `SubmissionStore::mark_paid()`, so most rows show the em dash instead).
     */
    private function render_method_cell(string $pay_method): void
    {
        if ('' === $pay_method) {
            echo '&mdash;';

            return;
        }

        $method = $this->method_catalog()[$pay_method] ?? null;

        if (null === $method) {
            echo esc_html($pay_method);

            return;
        }
        ?>
        <span class="iftp-nf-method-pill">
            <?php if ('' !== $method['logo']) : ?>
                <img src="<?php echo esc_url($method['logo']); ?>" alt="" loading="lazy" />
            <?php endif; ?>
            <?php echo esc_html($method['alias']); ?>
        </span>
        <?php
    }

    /**
     * @var array<string, array{alias: string, logo: string}>|null
     */
    private ?array $method_catalog_cache = null;

    /**
     * @return array<string, array{alias: string, logo: string}>
     */
    private function method_catalog(): array
    {
        if (null !== $this->method_catalog_cache) {
            return $this->method_catalog_cache;
        }

        $catalog = [];

        foreach ($this->settings->get_methods() as $method) {
            $entity = (string) ($method['entity'] ?? '');

            if ('' === $entity) {
                continue;
            }

            $catalog[$entity] = [
                'alias' => (string) ($method['alias'] ?? $entity),
                'logo'  => (string) ($method['logo'] ?? ''),
            ];
        }

        return $this->method_catalog_cache = $catalog;
    }

    /**
     * Memoized per request — called once per row rendered plus once per
     * record whenever a search term is active (`render()`), so on a 10k-row
     * install this otherwise repeats the same `Ninja_Forms()->form()` lookup
     * thousands of times over for what's usually a handful of distinct forms.
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
     * `null` for a param removes it (used to drop `paged` when switching
     * status/search, and to drop nothing when just paging).
     *
     * @param array<string, mixed> $overrides
     */
    private function filtered_url(array $overrides, bool $reset = false): string
    {
        if ($reset) {
            return add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php'));
        }

        $form_id_filter = (int) ($_GET['form_id'] ?? 0);
        $orderby        = $this->sanitize_orderby($_GET['orderby'] ?? '');

        $args = [
            'page'      => self::PAGE_SLUG,
            's'         => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'status'    => sanitize_text_field(wp_unslash($_GET['status'] ?? '')),
            'form_id'   => $form_id_filter > 0 ? (string) $form_id_filter : '',
            'date_from' => $this->sanitize_date($_GET['date_from'] ?? ''),
            'date_to'   => $this->sanitize_date($_GET['date_to'] ?? ''),
            'orderby'   => $orderby,
            'order'     => '' !== $orderby ? $this->sanitize_order($_GET['order'] ?? '') : '',
            'per_page'  => $this->sanitize_per_page($_GET['per_page'] ?? ''),
            'paged'     => (int) ($_GET['paged'] ?? 1),
        ];

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
     * @return array<string, string>
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
     * The filter/search/status-tab/pagination/sort state from the current
     * request — read from `$_GET` on the initial page render, and localized
     * into JS (`enqueue_assets()`) so a bulk AJAX action
     * (`ajax_update_status()`, `ajax_delete_entries()`) can recompute
     * status-tab counts and the footer total — and, for delete, the current
     * page's rows themselves — against the exact same view instead of a
     * stale or default one.
     *
     * @return array{s: string, status: string, form_id: int, date_from: string, date_to: string, per_page: int, paged: int, orderby: string, order: string}
     */
    private function current_filters(): array
    {
        $orderby = $this->sanitize_orderby($_GET['orderby'] ?? '');

        return [
            's'         => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'status'    => sanitize_text_field(wp_unslash($_GET['status'] ?? '')),
            'form_id'   => (int) ($_GET['form_id'] ?? 0),
            'date_from' => $this->sanitize_date($_GET['date_from'] ?? ''),
            'date_to'   => $this->sanitize_date($_GET['date_to'] ?? ''),
            'per_page'  => $this->sanitize_per_page($_GET['per_page'] ?? ''),
            'paged'     => max(1, (int) ($_GET['paged'] ?? 1)),
            'orderby'   => $orderby,
            'order'     => '' !== $orderby ? $this->sanitize_order($_GET['order'] ?? '') : '',
        ];
    }

    /**
     * Validates a `Y-m-d` date filter value, discarding anything malformed
     * rather than passing it through to `strtotime()`/query args as-is.
     */
    private function sanitize_date($raw): string
    {
        $value = sanitize_text_field(wp_unslash((string) $raw));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * Validates an `orderby` GET value against the columns that are
     * actually sortable — anything else (including no value at all) falls
     * back to the table's default reverse-chronological order.
     */
    private function sanitize_orderby($raw): string
    {
        $value = sanitize_text_field(wp_unslash((string) $raw));

        return in_array($value, ['id', 'amount', 'status', 'created', 'updated'], true) ? $value : '';
    }

    private function sanitize_order($raw): string
    {
        $value = strtolower(sanitize_text_field(wp_unslash((string) $raw)));

        return 'asc' === $value ? 'asc' : 'desc';
    }

    private function sanitize_per_page($raw): int
    {
        $value = (int) $raw;

        return in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : self::DEFAULT_PER_PAGE;
    }

    /**
     * A sortable column header: a link wrapping the label plus a small
     * up/down arrow indicator. Clicking toggles direction if this column is
     * already the active sort, otherwise starts it ascending.
     */
    private function sort_link(string $key, string $label, string $current_orderby, string $current_order): string
    {
        $is_sorted  = $key === $current_orderby;
        $next_order = ($is_sorted && 'asc' === $current_order) ? 'desc' : 'asc';

        $classes = 'iftp-nf-sortable';

        if ($is_sorted) {
            $classes .= ' is-sorted iftp-nf-sortable--' . $current_order;
        }

        return sprintf(
            '<a class="%1$s" href="%2$s">%3$s <span class="iftp-nf-sort-icon" aria-hidden="true"></span></a>',
            esc_attr($classes),
            esc_url($this->filtered_url(['orderby' => $key, 'order' => $next_order, 'paged' => null])),
            esc_html($label)
        );
    }

    /**
     * @return array<string, string>
     */
    private function status_filters(): array
    {
        return [
            ''                                => __('All', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_PAID      => __('Paid', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_PENDING   => __('Pending', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_FAILED    => __('Failed', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_CANCELLED => __('Cancelled', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_EXPIRED   => __('Expired', 'ifthenpay-payments-for-ninja-forms'),
        ];
    }

    /**
     * Every column the "Columns" control (`entries.js`, `bindColumnsControl()`)
     * lets the admin reorder/hide, in their default order — matches the
     * `data-col` attributes on the table's `<th>`/`<td>` elements one for
     * one. The leading checkbox column is deliberately not included here:
     * it's structural (bulk selection), not a data column, so it's always
     * shown first and isn't part of the saved layout.
     *
     * `positionLocked` (ID, Customer) keeps a column pinned first/second —
     * `entries.js` gives it no drag handle and refuses it as a drop target
     * either way, at Victor's request. `visibilityLocked` (ID, Customer,
     * Amount, Status) keeps a column always shown — its checkbox renders
     * checked and disabled instead of interactive — since between them ID
     * and the Amount button are the table's only two ways to open a row's
     * details (`render_row()`), and Customer/Status are the columns an
     * admin scanning payments needs to see at a glance.
     *
     * @return array<int, array{key: string, label: string, positionLocked: bool, visibilityLocked: bool}>
     */
    private function organizable_columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('ID', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => true, 'visibilityLocked' => true],
            ['key' => 'customer', 'label' => __('Customer', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => true, 'visibilityLocked' => true],
            ['key' => 'request_id', 'label' => __('Request ID', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'form', 'label' => __('Form', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'method', 'label' => __('Method', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'amount', 'label' => __('Amount', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => true],
            ['key' => 'status', 'label' => __('Status', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => true],
            ['key' => 'payment_link', 'label' => __('Payment Link', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
            ['key' => 'date', 'label' => __('Date', 'ifthenpay-payments-for-ninja-forms'), 'positionLocked' => false, 'visibilityLocked' => false],
        ];
    }
}

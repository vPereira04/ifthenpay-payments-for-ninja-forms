<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

use Ifthenpay\NinjaForms\NinjaForms\SubmissionStore;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A dedicated "ifthenpay Entries" screen listing every payment attempt —
 * pending, paid, failed, cancelled, expired — straight from `SubmissionStore`.
 *
 * This is the primary (and only) place to check payment status: Ninja Forms
 * only ever gets a real submission once a payment is confirmed (see
 * `Gateway\IfthenpayGateway`/`Api\Webhook\WebhookController`), so a
 * pending/failed/cancelled attempt has nowhere else to be seen.
 */
class EntriesPage
{
    private const PAGE_SLUG = 'ifthenpay-nf-entries';
    private const PER_PAGE  = 20;

    private SubmissionStore $submissions;

    public function __construct(?SubmissionStore $submissions = null)
    {
        $this->submissions = $submissions ?? new SubmissionStore();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
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

        wp_enqueue_style('iftp-nf-admin', IFTP_NF_URL . 'assets/css/admin.css', [], IFTP_NF_VERSION);
        wp_enqueue_script('iftp-nf-entries', IFTP_NF_URL . 'assets/js/entries.js', [], IFTP_NF_VERSION, true);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Sequence numbers ("#") are assigned over the full, unfiltered,
        // chronological (oldest first) list, so they stay stable regardless
        // of the current search/status filter or page.
        $all = $this->submissions->get_all();
        usort($all, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
        $seq_by_ref = [];
        foreach ($all as $index => $record) {
            $seq_by_ref[$record['ref']] = $index + 1;
        }

        $search        = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $status_filter = sanitize_text_field(wp_unslash($_GET['status'] ?? ''));

        $records = array_reverse($all);

        if ('' !== $status_filter) {
            $records = array_values(array_filter(
                $records,
                static fn (array $r): bool => $r['status'] === $status_filter
            ));
        }

        if ('' !== $search) {
            $records = array_values(array_filter(
                $records,
                function (array $r) use ($search): bool {
                    $haystack = $r['ref'] . ' ' . $this->form_title((int) $r['form_id']) . ' ' . $r['pay_method'] . ' ' . $r['amount'];

                    return false !== stripos($haystack, $search);
                }
            ));
        }

        $total = count($records);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged = min($pages, max(1, (int) ($_GET['paged'] ?? 1)));
        $slice = array_slice($records, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
        ?>
        <div class="wrap iftp-nf-entries">
            <?php $this->render_peeking_ninja(); ?>

            <h1><?php esc_html_e('ifthenpay Entries', 'ifthenpay-payments-for-ninja-forms'); ?></h1>
            <p class="description">
                <?php esc_html_e('Every ifthenpay payment attempt on this site, whether or not it was confirmed.', 'ifthenpay-payments-for-ninja-forms'); ?>
            </p>

            <div class="iftp-nf-entries-toolbar">
                <ul class="subsubsub">
                    <?php foreach ($this->status_filters() as $slug => $label) : ?>
                        <li>
                            <a
                                href="<?php echo esc_url($this->filtered_url(['status' => $slug, 'paged' => null])); ?>"
                                <?php echo $slug === $status_filter ? 'class="current"' : ''; ?>
                            ><?php echo esc_html($label); ?></a> |
                        </li>
                    <?php endforeach; ?>
                </ul>

                <form method="get" class="iftp-nf-entries-search">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                    <?php if ('' !== $status_filter) : ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>" />
                    <?php endif; ?>
                    <input
                        type="search"
                        name="s"
                        value="<?php echo esc_attr($search); ?>"
                        placeholder="<?php esc_attr_e('Search reference, form, method, amount…', 'ifthenpay-payments-for-ninja-forms'); ?>"
                    />
                    <button type="submit" class="button"><?php esc_html_e('Search', 'ifthenpay-payments-for-ninja-forms'); ?></button>
                </form>
            </div>

            <p class="iftp-nf-entries-count">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of entries */
                        _n('%d entry', '%d entries', $total, 'ifthenpay-payments-for-ninja-forms'),
                        $total
                    )
                );
                ?>
            </p>

            <table class="widefat striped iftp-nf-entries-table">
                <thead>
                    <tr>
                        <th class="iftp-nf-col-narrow">#</th>
                        <th><?php esc_html_e('Reference', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                        <th><?php esc_html_e('Form', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                        <th><?php esc_html_e('Amount', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                        <th><?php esc_html_e('Method', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                        <th><?php esc_html_e('Status', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                        <th><?php esc_html_e('Created', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                        <th><?php esc_html_e('Updated', 'ifthenpay-payments-for-ninja-forms'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ([] === $slice) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e('No ifthenpay payments match this view.', 'ifthenpay-payments-for-ninja-forms'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($slice as $record) : ?>
                            <?php $this->render_row($record, $seq_by_ref[$record['ref']] ?? 0); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php if ($paged > 1) : ?>
                            <a class="button" href="<?php echo esc_url($this->filtered_url(['paged' => $paged - 1])); ?>">&laquo; <?php esc_html_e('Previous', 'ifthenpay-payments-for-ninja-forms'); ?></a>
                        <?php endif; ?>
                        <span class="iftp-nf-entries-page-info">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: current page, 2: total pages */
                                    __('Page %1$d of %2$d', 'ifthenpay-payments-for-ninja-forms'),
                                    $paged,
                                    $pages
                                )
                            );
                            ?>
                        </span>
                        <?php if ($paged < $pages) : ?>
                            <a class="button" href="<?php echo esc_url($this->filtered_url(['paged' => $paged + 1])); ?>"><?php esc_html_e('Next', 'ifthenpay-payments-for-ninja-forms'); ?> &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $record
     */
    private function render_row(array $record, int $seq): void
    {
        $sub_id = $record['data']['actions']['save']['sub_id'] ?? null;
        $row_id = 'iftp-nf-details-' . sanitize_html_class($record['ref']);
        ?>
        <tr class="iftp-nf-entry-row">
            <td><?php echo esc_html((string) $seq); ?></td>
            <td>
                <button type="button" class="button-link iftp-nf-details-toggle" data-details-target="<?php echo esc_attr($row_id); ?>">
                    <code><?php echo esc_html($record['ref']); ?></code>
                </button>
            </td>
            <td><?php echo esc_html($this->form_title((int) $record['form_id'])); ?></td>
            <td>
                <button type="button" class="button-link iftp-nf-details-toggle" data-details-target="<?php echo esc_attr($row_id); ?>">
                    <?php echo esc_html(number_format((float) $record['amount'], 2)); ?>
                </button>
            </td>
            <td><?php echo esc_html('' !== $record['pay_method'] ? $record['pay_method'] : '—'); ?></td>
            <td>
                <span class="iftp-nf-status-badge iftp-nf-status-badge--<?php echo esc_attr($record['status']); ?>">
                    <?php echo esc_html(ucfirst($record['status'])); ?>
                </span>
            </td>
            <td><?php echo esc_html(wp_date('Y-m-d H:i', (int) $record['created_at'])); ?></td>
            <td><?php echo esc_html(wp_date('Y-m-d H:i', (int) $record['updated_at'])); ?></td>
        </tr>
        <tr id="<?php echo esc_attr($row_id); ?>" class="iftp-nf-entry-details" hidden>
            <td colspan="8">
                <?php $this->render_details($record, $sub_id); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<string, mixed> $record
     */
    private function render_details(array $record, $sub_id): void
    {
        ?>
        <div class="iftp-nf-details-grid">
            <div>
                <strong><?php esc_html_e('Gateway Key', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <code><?php echo esc_html($record['gateway_key']); ?></code>
            </div>
            <div>
                <strong><?php esc_html_e('Request ID', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                <?php echo esc_html('' !== $record['request_id'] ? $record['request_id'] : '—'); ?>
            </div>
            <?php if (null !== $sub_id) : ?>
                <div>
                    <strong><?php esc_html_e('Submission', 'ifthenpay-payments-for-ninja-forms'); ?>:</strong>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $sub_id . '&action=edit')); ?>">
                        <?php esc_html_e('View entry', 'ifthenpay-payments-for-ninja-forms'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $fields = $this->submitted_fields($record);

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
     * Best-effort label => value list from the captured submission snapshot,
     * skipping structural field types with nothing meaningful to show.
     *
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function submitted_fields(array $record): array
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
     * The Ninja Forms "peeking ninja" mascot, purely decorative. Starts
     * peeking bottom-left (`nf-shy-ninja-returning`); each hover advances it
     * through Ninja Forms' own real states — shake in place, then slide to
     * `nf-shy-ninja-scooted` (center) or back to `nf-shy-ninja-returning`
     * (left) — until the fourth hover sends it fully off-screen
     * (`nf-shy-ninja-fled`, not an official NF class — there's no fourth
     * state in the real one), where it stays for 5 seconds before sliding
     * back to its starting corner on its own. See `assets/js/entries.js`.
     */
    private function render_peeking_ninja(): void
    {
        ?>
        <div class="nf-spark-peeking-ninja-wrapper nf-shy-ninja-returning" id="nf-peeking-ninja-wrapper">
            <div class="nf-spark-peeking-ninja" id="nf-peeking-ninja">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200.29 89.86">
                    <defs>
                        <style>.peek-face{fill:#fff;}.peek-head{fill:currentColor;}</style>
                    </defs>
                    <g>
                        <path class="peek-head" d="M171.62,89.86c.02-.17.04-1.65.04-2.48,0-12.97-2.89-25.2-8.05-36.23-.81-2.1-1.92-4.23-3.18-6.37,10.27-3.45,20.32-5.38,27.76-6.9h.01c9.06-1.86,14.26-3.05,11.22-5.54-6.62-5.41-15.03-11.27-15.03-11.27,0,0,33.81-27.94,0-19.48-6.37,1.59-11.96,3.98-16.87,6.79-9.44,5.4-16.29,12.39-20.97,18.48C131.01,11.32,109.55,1.69,85.83,1.69,38.43,1.69,0,39.99,0,87.39c0,.84.04,2.31.04,2.48h171.58Z"></path>
                        <path class="peek-face" d="M53.4,77.76c4.69,0,8.51,5.4,8.62,12.11h47.65c.11-6.71,3.92-12.11,8.62-12.11s8.51,5.4,8.62,12.11h20.31c-.35-10.72-6.21-21.09-16.36-26.7-26.69-14.74-61.81-14.79-89.48-.24-10.46,5.5-16.57,15.14-16.93,26.94h20.33c.11-6.71,3.91-12.11,8.62-12.11Z"></path>
                    </g>
                </svg>
            </div>
        </div>
        <?php
    }

    private function form_title(int $form_id): string
    {
        if (! function_exists('Ninja_Forms')) {
            return (string) $form_id;
        }

        $form = Ninja_Forms()->form($form_id)->get();

        if (null === $form) {
            return (string) $form_id;
        }

        $settings = $form->get_settings();

        return (string) ($settings['title'] ?? $form_id);
    }

    /**
     * Rebuilds the current URL with the given params overridden — passing
     * `null` for a param removes it (used to drop `paged` when switching
     * status/search, and to drop nothing when just paging).
     *
     * @param array<string, mixed> $overrides
     */
    private function filtered_url(array $overrides): string
    {
        $args = [
            'page'   => self::PAGE_SLUG,
            's'      => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'status' => sanitize_text_field(wp_unslash($_GET['status'] ?? '')),
            'paged'  => (int) ($_GET['paged'] ?? 1),
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
    private function status_filters(): array
    {
        return [
            ''                                => __('All', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_PENDING   => __('Pending', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_PAID      => __('Paid', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_FAILED    => __('Failed', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_CANCELLED => __('Cancelled', 'ifthenpay-payments-for-ninja-forms'),
            SubmissionStore::STATUS_EXPIRED   => __('Expired', 'ifthenpay-payments-for-ninja-forms'),
        ];
    }
}

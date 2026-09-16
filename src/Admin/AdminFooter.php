<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * I customize the admin footer on this plugin's own screens: a rate-us
 * link on the left, a "Powered by" logo centered, and the version on the
 * right.
 *
 * I use all three WP footer hooks instead of just `admin_footer_text`
 * because I need the logo truly centered, not just CSS-centered.
 */
class AdminFooter
{
    private const SETTINGS_PAGE_SLUG = 'nf-settings';
    private const SETTINGS_TAB_SLUGS = ['payments', 'ifthenpay-confirmation'];
    private const ENTRIES_PAGE_SLUG  = 'ifthenpay-nf-entries';

    public function register(): void
    {
        // I run this at priority 5 so it fires before EntriesPage's footer
        // hook — otherwise replacing the string here would wipe out its button.
        add_filter('admin_footer_text', [$this, 'rate_us_link'], 5);
        add_action('in_admin_footer', [$this, 'powered_by']);
        add_filter('update_footer', [$this, 'version_text'], 20);
    }

    public function rate_us_link(string $text): string
    {
        if (! $this->is_our_screen()) {
            return $text;
        }

        $url = 'https://wordpress.org/support/plugin/ifthenpay-payments-for-ninja-forms/reviews/?filter=5#new-post';

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="iftp-nf-footer-rate-link">'
            . esc_html__('Please rate us ★★★★★ on WordPress.org!', 'ifthenpay-payments-for-ninja-forms')
            . '</a>';
    }

    public function powered_by(): void
    {
        if (! $this->is_our_screen()) {
            return;
        }

        printf(
            '<div class="iftp-nf-footer-powered"><span class="iftp-nf-footer-powered-label">%1$s</span><img src="%2$s" alt="ifthenpay" class="iftp-nf-footer-logo" draggable="false" /></div>',
            esc_html__('Powered by', 'ifthenpay-payments-for-ninja-forms'),
            esc_url(IFTP_NF_URL . 'assets/img/logo-color.svg')
        );
    }

    public function version_text(string $content): string
    {
        if (! $this->is_our_screen()) {
            return $content;
        }

        return '<span class="iftp-nf-footer-version">'
            . sprintf(
                /* translators: %s: plugin version number, e.g. "1.0.0" */
                esc_html__('Version %s', 'ifthenpay-payments-for-ninja-forms'),
                esc_html(IFTP_NF_VERSION)
            )
            . '</span>';
    }

    private function is_our_screen(): bool
    {
        $page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));

        if (self::ENTRIES_PAGE_SLUG === $page) {
            return true;
        }

        $tab = sanitize_text_field(wp_unslash($_GET['tab'] ?? ''));

        return self::SETTINGS_PAGE_SLUG === $page && in_array($tab, self::SETTINGS_TAB_SLUGS, true);
    }
}

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

        echo '<div class="iftp-nf-footer-powered"><span class="iftp-nf-footer-powered-label">'
            . esc_html__('Powered by', 'ifthenpay-payments-for-ninja-forms')
            . '</span><img src="' . esc_url(IFTP_NF_URL . 'assets/img/logo-color.svg') . '" alt="ifthenpay" class="iftp-nf-footer-logo" draggable="false" /></div>';
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
        if (AdminScreen::is(self::ENTRIES_PAGE_SLUG)) {
            return true;
        }

        foreach (self::SETTINGS_TAB_SLUGS as $tab) {
            if (AdminScreen::is(self::SETTINGS_PAGE_SLUG, $tab)) {
                return true;
            }
        }

        return false;
    }
}

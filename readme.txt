=== ifthenpay | Payments for Ninja Forms ===
Contributors: ifthenpay
Tags: ninja forms, payments, multibanco, mb way, payshop
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds ifthenpay payment methods to Ninja Forms: cards, wallets, and local payment options; supports secure one-time payments via pay-by-link.

== Description ==

This plugin integrates the ifthenpay payment gateway with Ninja Forms to enable seamless payment collection directly from your forms. Payments are processed through a secure pay-by-link system, ensuring that no sensitive card or banking data is stored on your website. Customers can complete payments using their preferred method via a secure payment page. After submitting a form, users are redirected to ifthenpay's secure hosted payment page to complete the transaction; ifthenpay then sends a server-side callback to update the payment status automatically.

In plain terms you get:

* One-time payments directly from Ninja Forms' Collect Payment action
* Payment amount automatically follows your form's own total (e.g. Ninja Forms Calculations)
* Merchant backoffice (basic sales) on web + mobile
* Secure automatic payment confirmations (no card numbers stored)

All settings are made in Ninja Forms and in your ifthenpay Backoffice. The plugin is built so site owners can manage payments without needing deep technical knowledge.

== Key Features ==

1. Full integration with Ninja Forms' Collect Payment action
2. Secure transactions
3. Automatic payment confirmation
4. Support for multiple payment methods (Multibanco, MB WAY, Payshop, Pix, Credit Card and more — whatever's enabled on your Gateway Key)
5. Payment methods catalog synced live from your ifthenpay account, with in-admin "Request Activation" for methods not yet enabled
6. Secure full-page redirect to ifthenpay's hosted payment page
7. Real-time payment status in Ninja Forms entries
8. Multi-language support (EN, ES, FR, PT)
9. Security first (no card data stored)

== Requirements ==

* An active ifthenpay merchant account — [subscribe here](https://ifthenpay.com/aderir/) to obtain your credentials.
* A Ninja Forms Gateway Key (request this from ifthenpay support/helpdesk).
* The payment methods you want enabled on that Gateway Key (our helpdesk team will guide you).
* WordPress 6.4+ and PHP 7.4+, and Ninja Forms installed and activated.
* HTTPS (SSL) enabled on your site.

== Installation ==

1. **Install:** Upload the plugin zip via `Plugins → Add New → Upload`, or install from WordPress.org and Activate.
2. **Credentials:** Ensure your ifthenpay account has an active Ninja Forms Gateway Key with the desired payment methods enabled.
3. **Setup Part 1:** Go to `Ninja Forms → Settings → Payments` and enter your Backoffice Key.
4. **Setup Part 2:** Once the Backoffice Key is connected you'll see your gateway key configuration — select your Gateway Key of choice if you have more than one, enable your favorite methods, set a default payment method, a description for your payments, and an expiry (in days) for your pay-by-links. These settings apply globally, to every form.
5. **Form config:** Edit your form → `Emails & Actions` tab → add (or edit) a "Collect Payment" action → set its Payment Gateway to "ifthenpay | Payment Gateway". The payment methods, default method, description, and expiry were already configured in Step 4 — this step only wires this specific form's Collect Payment action to ifthenpay.

== Frequently Asked Questions ==

= Does this plugin require Ninja Forms? =

Yes. Ninja Forms must be installed and active to use this plugin.

= Does it support recurring payments? =

No. This version supports only one-time payments via pay-by-link.

= Are payment details stored? =

No. The plugin does not store card numbers or full bank details. Only minimal references required for payment matching are kept.

= Which payment methods are supported? =

Any ifthenpay method attached to your Gateway Key (e.g. Multibanco, MB WAY, Payshop, Pix, Credit Card, and more).

= How does the payment process work? =

After form submission, users are redirected to a secure ifthenpay-hosted payment page. Once payment is completed, the status is updated automatically via callback and the user will be shown a Thank you confirmation.

= What happens if a payment fails? =

The entry is marked as Failed.

= Is there a sandbox? =

ifthenpay may provide test entities; if unavailable, use a low-value live test.

= How secure is the integration? =

Requests are encrypted over HTTPS; no sensitive payment data is stored.

= Does ifthenpay's addon for Ninja Forms accept webhooks (callbacks)? =

Yes! ifthenpay's addon for Ninja Forms accepts webhooks (callbacks).

== External Services ==

This plugin integrates with the ifthenpay payment platform to process payments for Ninja Forms submissions. ifthenpay is a third-party service that provides secure payment processing for local payment methods such as Multibanco, MB WAY, Payshop, and Pix, plus Credit Card and more, depending on what's enabled on your account.

**Ninja Forms**

* What it is and what it is used for: A form builder plugin used to create payment forms. This plugin extends its payment capabilities.

**ifthenpay Backoffice & Integrations**

* What it is and what it is used for: The ifthenpay Backoffice is the merchant dashboard used to manage integrations and payment configurations. The plugin uses the ifthenpay API to generate payment links and validate transactions.
* What data is sent and when:
    * During setup: Backoffice Key and Gateway Key for authentication and configuration retrieval.
    * During payment processing: Order reference ID, amount, description, enabled payment method accounts, success/error/cancel return URLs, language, and optionally the selected payment method position. No customer name, email, or form field data is included in this request.
    * During webhook registration: the Gateway Key and this site's callback URL, so ifthenpay can notify the site directly when a payment resolves.
    * During payment method activation requests: when an admin requests activation of a new payment method from Ninja Forms → Settings → Payments, an email is sent to ifthenpay support (suporte@ifthenpay.com) containing the Backoffice Key, Gateway Key, the requested payment method, the admin's email address, site URL, site name, WordPress version, Ninja Forms version, and plugin version.
    * During callbacks: Payment status and payment method.
* End-User License Agreement (EULA): [https://ifthenpay.com/eula/](https://ifthenpay.com/eula/)
* Privacy Policy: [https://ifthenpay.com/politica-de-privacidade/](https://ifthenpay.com/politica-de-privacidade/)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored securely and are not publicly exposed. No raw card or bank details are stored.

== Screenshots ==

1. (Admin Only) Backoffice Synchronization under Ninja Forms Settings Payments
2. (Admin Only) Ninja Forms's Gateway Configuration
3. (Admin Only) ifthenpay Confirmation Method
4. (Admin Only) Edit a Form
5. (Admin Only) Form Advanced Calculations Setting
6. (Admin Only) Form Email & Actions settings, Collect Payment Settings
7. (Customers Experience) Payment Form
8. (Customers Experience) ifthenpay Payment Gateway Secure Payment Page
9. (Admin Only) Payment Entries

== Changelog ==

Releases that fix a security issue say so in their entry, prefixed with **Security:**.

= 1.0.0 =
* Initial public release: ifthenpay Pay by Link gateway for Ninja Forms' Collect Payment action, webhook-confirmed payment status, ifthenpay Entries admin screen, configurable per-outcome confirmation behavior, and automatic expiry labeling of stale pending payments.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.

== Security ==

Please report security issues privately to suporte@ifthenpay.com with "Security" in the subject line, not in the public support forum. See SECURITY.md in the plugin folder for what to include and what happens next.

== Support ==

For assistance use the WordPress.org support forum: https://wordpress.org/support/plugin/ifthenpay-payments-for-ninja-forms/

Pre-checks:

* Payment method enabled on Gateway Key AND mapped to Integration
* Running current recommended versions of WordPress, PHP, & Ninja Forms

Commercial helpdesk available (no direct email required): https://helpdesk.ifthenpay.com/

* ifthenpay support: suporte@ifthenpay.com
* Ninja Forms docs: https://ninjaforms.com/docs/

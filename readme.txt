=== ifthenpay Payments for Ninja Forms ===
Contributors: ifthenpay
Tags: ninja forms, payments, multibanco, mb way, payshop
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept ifthenpay payments in Ninja Forms via Pay by Link, with webhook-confirmed payment status.

== Description ==

Adds ifthenpay as a payment gateway option in Ninja Forms' built-in Collect Payment action. Supports every method available on your ifthenpay Gateway Key (Multibanco, MB WAY, Payshop, Pix, Credit Card, Apple Pay, Google Pay, Cofidis and more), with payment status confirmed by ifthenpay's server-to-server webhook so that offline methods (Multibanco, Payshop) work correctly even when the customer pays hours or days after leaving your site.

= Features =

* One-click connection to your ifthenpay Backoffice Key
* Live, fetched methods table — never a free-text accounts string
* Star-toggle default payment method
* Webhook-confirmed payment status (never trusts the browser return alone)
* Automatic expiry of unpaid payment sessions

== External Services ==

This plugin connects to the ifthenpay API (https://ifthenpay.com) to:

* Validate your Backoffice Key and retrieve your Gateway Key(s) (`GET https://api.ifthenpay.com/gateway/get`)
* Retrieve the catalog of available payment methods (`GET https://api.ifthenpay.com/gateway/methods/available`)
* Create a Pay by Link payment request when a form is submitted (`POST https://api.ifthenpay.com/gateway/pinpay/{gateway_key}`)
* Register the webhook URL that ifthenpay calls to confirm payment (`POST https://api.ifthenpay.com/endpoint/callback/activation/`)

No data is sent to ifthenpay until you connect a Backoffice Key and a form using the ifthenpay gateway is submitted. See ifthenpay's terms of service (https://ifthenpay.com/eula/) and privacy policy (https://ifthenpay.com/politica-de-privacidade/).

== Installation ==

1. Install and activate Ninja Forms.
2. Install and activate this plugin.
3. Go to Ninja Forms > ifthenpay and connect your Backoffice Key.
4. Enable the payment methods you want to accept and choose a default.
5. Add a Collect Payment action to your form and select "ifthenpay | Payment Gateway".

== Changelog ==

= 0.1.0 =
* Initial scaffolding.

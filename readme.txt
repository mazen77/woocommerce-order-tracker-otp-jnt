=== Order Tracker with OTP & J&T for WooCommerce ===
Contributors: mazen-bassiso
Tags: woocommerce, order tracking, order tracker, otp, j&t, jnt, saudi arabia, arabic, rtl, hpos, sms
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 3.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure Arabic-first WooCommerce order tracking by order number, email, or Saudi mobile number with OTP verification and J&T tracking support.

== Description ==

Order Tracker with OTP & J&T for WooCommerce provides a public order-tracking form protected by OTP verification.

Customers can search using an order number, billing email address, or Saudi mobile number. The plugin verifies ownership before displaying order details.

Features include:

* OTP-protected public order tracking.
* Email OTP through wp_mail().
* SMS integration hooks, including lwp_send_sms_taqnyat.
* Saudi mobile number normalization.
* J&T waybill/tracking detection.
* WooCommerce order status and payment information.
* My Account order-detail integration.
* WooCommerce admin meta box.
* HPOS-compatible order lookup.
* Rate limiting, OTP cooldown, and limited verification attempts.
* No bundled API credentials.

Shortcode:

`[mazen_order_tracker]`

Arabic documentation is included in README.md.

== Installation ==

1. Upload and activate the plugin.
2. Ensure WooCommerce is active.
3. Configure WordPress email delivery.
4. Configure an SMS action if phone-based OTP is required.
5. Add `[mazen_order_tracker]` to a WordPress page.

== Frequently Asked Questions ==

= Does the plugin include an SMS provider account? =

No. It exposes integration hooks and supports an existing Taqnyat action hook, but it does not ship credentials or an SMS account.

= Does it support HPOS? =

Yes. The plugin includes HPOS-aware phone lookups and declares WooCommerce custom order table compatibility.

= Is order data visible before OTP verification? =

No. The public tracker requires a valid OTP before order details are returned.

= Which phone numbers are supported? =

The built-in parser is focused on Saudi mobile formats: 05xxxxxxxx, 5xxxxxxxx, and 9665xxxxxxxx.

== Changelog ==

= 3.4.0 =
* Prepared the plugin for public distribution.
* Added Mazen Bassiso / fa7ma.com author metadata.
* Fixed undefined payment-method output in My Account and admin views.
* Fixed malformed inline markup in the My Account panel.
* Improved HPOS admin meta-box handling.
* Declared WooCommerce HPOS compatibility.
* Added source-level OTP request throttling.
* Prevented OTP sends to identifiers not associated with an order.
* Made verified fetch tokens single-use.
* Added a filter for custom tracking meta keys.
* Updated asset versioning.

= 3.3.1 =
* Original private release.

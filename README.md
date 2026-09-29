# Order Tracker with OTP & J&T for WooCommerce

A lightweight, Arabic-first WooCommerce order tracking plugin that lets customers securely find orders using an **order number, email address, or Saudi mobile number**. Before any order details are displayed, the customer must verify ownership with a one-time password (OTP).

The plugin also detects and displays common **J&T Express waybill/tracking numbers**, adds tracking/payment information to the WooCommerce order details screen, and supports **High-Performance Order Storage (HPOS)**.

**Author:** Mazen Bassiso  
**Website:** https://fa7ma.com

## Features

- Public order tracking through the shortcode `[mazen_order_tracker]`.
- Search by WooCommerce order number, billing email, or Saudi mobile number.
- OTP verification before customer/order information is revealed.
- Email OTP through WordPress `wp_mail()`.
- SMS OTP through the existing `lwp_send_sms_taqnyat` action or the `mazen_order_tracker_send_sms` integration hook.
- Saudi phone normalization for `05xxxxxxxx`, `5xxxxxxxx`, and `9665xxxxxxxx` formats.
- J&T tracking/waybill detection from several common WooCommerce order meta keys.
- Displays order status, payment status, payment method, total, customer details, products, and tracking number.
- Adds order/tracking information to **My Account → Order details**.
- Adds an order/tracking meta box in WooCommerce admin.
- HPOS-aware phone lookup and declared HPOS compatibility.
- OTP resend cooldown, verification-attempt limit, source request throttling, and one-time verified fetch tokens.
- No external JavaScript libraries beyond the jQuery already bundled with WordPress.
- No background cron jobs.

## Important SMS requirement

Email OTP works through WordPress mail. SMS delivery requires an SMS integration.

The plugin currently supports either of these WordPress actions:

```php
do_action( 'lwp_send_sms_taqnyat', $phone, $message );
do_action( 'mazen_order_tracker_send_sms', $phone, $message );
```

If neither hook has a listener, phone/order-number OTP cannot be sent. This repository intentionally contains **no SMS API credentials**.

## Installation

1. Download the release ZIP.
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and activate it.
4. Confirm WooCommerce is active and WordPress email delivery is configured.
5. Configure an SMS handler if you want mobile/order-number OTP.
6. Add this shortcode to a page:

```text
[mazen_order_tracker]
```

## J&T tracking detection

By default, the plugin checks these order meta keys:

```text
_jnt_waybill
jnt_waybill
_jnt_tracking_number
jnt_tracking_number
_waybill
waybill
_tracking_number
tracking_number
```

It also looks for values matching a J&T-style `JTE...` identifier in order metadata.

Developers can customize the checked keys:

```php
add_filter( 'mazen_order_tracker_tracking_meta_keys', function ( $keys, $order ) {
    $keys[] = '_my_shipping_tracking_number';
    return $keys;
}, 10, 2 );
```

## Security and privacy

The public tracker does not expose order details until OTP verification succeeds. Version 3.4.0 also avoids sending OTP messages to identifiers that are not associated with an order, rate-limits OTP/verification requests, hashes OTP values before storing them in temporary WordPress transients, limits verification attempts, and invalidates the verified fetch token after successful use.

No SMS passwords, API keys, or private credentials are bundled with the plugin.

## Compatibility

- WordPress 6.5+
- PHP 7.4+
- WooCommerce required
- WooCommerce HPOS supported
- Arabic/RTL-first interface
- Saudi mobile number formats are supported by the built-in phone parser

## Notes about payment status

The tracker uses WooCommerce payment state and paid-date information. Cash on Delivery (`cod`) is intentionally displayed as **unpaid** until another payment state is recorded. This preserves the original business behavior of the plugin.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

# العربية

إضافة خفيفة وآمنة لتتبع طلبات **WooCommerce** باللغة العربية. تتيح للعميل البحث عن طلباته باستخدام **رقم الطلب أو البريد الإلكتروني أو رقم الجوال السعودي**، ولا يتم عرض بيانات الطلب إلا بعد التحقق بواسطة رمز OTP.

كما تدعم الإضافة إظهار **رقم تتبع J&T Express** من بيانات الطلب، وتعرض حالة الطلب والدفع وطريقة الدفع والمنتجات ومعلومات التتبع في صفحة التتبع العامة وصفحة تفاصيل الطلب ولوحة تحكم WooCommerce.

**المطور:** Mazen Bassiso  
**الموقع:** https://fa7ma.com

## أهم المميزات

- شورت كود جاهز: `[mazen_order_tracker]`.
- البحث برقم الطلب أو البريد أو رقم الجوال.
- التحقق برمز OTP قبل عرض أي معلومات خاصة بالطلب.
- إرسال OTP بالبريد عبر `wp_mail()`.
- دعم تكامل SMS عبر Taqnyat hook الموجود أو hook مخصص.
- دعم صيغ أرقام الجوال السعودية الشائعة.
- اكتشاف رقم شحنة/Waybill الخاص بـ J&T من عدة مفاتيح شائعة.
- عرض حالة الطلب، حالة الدفع، طريقة الدفع، الإجمالي، بيانات العميل والمنتجات.
- عرض معلومات التتبع داخل حساب العميل ولوحة التحكم.
- دعم WooCommerce HPOS.
- حماية من كثرة إرسال الرموز والمحاولات المتكررة.
- لا توجد مهام Cron ثقيلة أو مكتبات خارجية إضافية.

## متطلبات الرسائل النصية

إرسال OTP عبر البريد يعمل من خلال إعداد البريد في WordPress. أما OTP عبر الجوال فيحتاج إلى تكامل SMS منفصل. الإضافة **لا تحتوي على أي مفاتيح API أو بيانات سرية**.

## الاستخدام

أنشئ صفحة لتتبع الطلب وأضف:

```text
[mazen_order_tracker]
```

ثم تأكد من إعداد البريد وتكامل SMS إذا كنت تريد التحقق برقم الجوال أو رقم الطلب.

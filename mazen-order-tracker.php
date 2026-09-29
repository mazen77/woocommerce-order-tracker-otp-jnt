<?php
/**
 * Plugin Name: Order Tracker with OTP & J&T for WooCommerce
 * Plugin URI: https://fa7ma.com
 * Description: Secure Arabic-first WooCommerce order tracking by order number, email, or Saudi mobile number with OTP verification and J&T tracking support.
 * Version: 3.4.0
 * Author: Mazen Bassiso
 * Author URI: https://fa7ma.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: mazen-order-tracker
 */

if (!defined('ABSPATH')) exit;

final class Mazen_Order_Tracker {
    const OTP_TTL_SECONDS = 10 * 60;         // 10 minutes
    const VERIFY_TTL_SECONDS = 30 * 60;      // 30 minutes
    const RESEND_COOLDOWN_SECONDS = 60;      // 60 seconds
    const MAX_VERIFY_ATTEMPTS = 5;
    const VERSION = '3.4.0';

    public static function init() {
        add_shortcode('mazen_order_tracker', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        add_action('wp_ajax_nopriv_mazen_ot_start', [__CLASS__, 'ajax_start']);
        add_action('wp_ajax_mazen_ot_start', [__CLASS__, 'ajax_start']);

        add_action('wp_ajax_nopriv_mazen_ot_verify', [__CLASS__, 'ajax_verify']);
        add_action('wp_ajax_mazen_ot_verify', [__CLASS__, 'ajax_verify']);

        add_action('wp_ajax_nopriv_mazen_ot_fetch', [__CLASS__, 'ajax_fetch']);
        add_action('wp_ajax_mazen_ot_fetch', [__CLASS__, 'ajax_fetch']);

        // My Account order page: Status + Paid/Unpaid + J&T
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'render_myaccount_extras'], 30);

        // Admin order edit: Meta box (J&T + status + payment)
        add_action('add_meta_boxes', [__CLASS__, 'admin_metabox']);
    }

    public static function register_assets() {
        wp_register_style('mazen-ot', plugins_url('assets/mazen-ot.css', __FILE__), [], self::VERSION);
        wp_register_script('mazen-ot', plugins_url('assets/mazen-ot.js', __FILE__), ['jquery'], self::VERSION, true);
    }

    private static function arabic_status_label($status_slug) {
        $map = [
            'pending'          => 'قيد الانتظار',
            'processing'       => 'قيد المعالجة',
            'on-hold'          => 'معلّق',
            'completed'        => 'مكتمل',
            'cancelled'        => 'ملغي',
            'refunded'         => 'مسترجع',
            'failed'           => 'فشل',
            'draft'            => 'مسودة',
            // common custom statuses
            'packaging-done'   => 'تم التجهيز',
            'shipped'          => 'تم الشحن',
            'out-for-delivery' => 'خرج للتسليم',
            'delivered'        => 'تم التسليم',
        ];
        return $map[$status_slug] ?? $status_slug;
    }

    /**
     * Fix for stores using custom order statuses:
     * WC_Order::is_paid() can be false even when the order has a paid date.
     */
    private static function order_is_paid_strict(WC_Order $order) {
		$method = (string) $order->get_payment_method();

		// COD should always display as UNPAID in tracking UI (per business rule)
		if ($method === 'cod') {
			return false;
		}

		// Default WooCommerce check
		if ($order->is_paid()) return true;

		// Strongest indicator: paid date
		$date_paid = $order->get_date_paid();
		if ($date_paid) return true;

		// Some gateways store payment date in meta
		$meta_keys = ['_paid_date', '_date_paid', '_payment_date', '_wc_paid_date'];
		foreach ($meta_keys as $k) {
			$v = $order->get_meta($k, true);
			if (!empty($v)) return true;
		}

		// Free orders count as paid (non-COD)
		if ((float) $order->get_total() <= 0.0) return true;

		return false;
	}

	private static function arabic_paid_label(WC_Order $order) {
		return self::order_is_paid_strict($order) ? 'مدفوع' : 'غير مدفوع';
	}

private static function normalize_phone_variants($raw) {
        $digits = preg_replace('/\D+/', '', (string)$raw);
        if ($digits === '') return [];
        $variants = [$digits];

        if (strpos($digits, '966') === 0 && strlen($digits) >= 12) {
            $last9 = substr($digits, -9);
            $variants[] = '0' . $last9;
            $variants[] = $last9;
            $variants[] = '966' . $last9;
        }
        if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
            $last9 = substr($digits, -9);
            $variants[] = $last9;
            $variants[] = '966' . $last9;
        }
        if (strlen($digits) === 9 && strpos($digits, '5') === 0) {
            $variants[] = '0' . $digits;
            $variants[] = '966' . $digits;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private static function to_e164_ksa($raw) {
        $digits = preg_replace('/\D+/', '', (string)$raw);
        if ($digits === '') return '';
        if (strpos($digits, '966') === 0) return '+' . $digits;
        if (strpos($digits, '0') === 0 && strlen($digits) === 10) return '+966' . substr($digits, -9);
        if (strlen($digits) === 9 && strpos($digits, '5') === 0) return '+966' . $digits;
        return '+' . $digits;
    }

    // IMPORTANT: phone detection first so 05xxxxxxxx isn't treated as order id.
    private static function looks_like_phone($input) {
        $digits = preg_replace('/\D+/', '', (string)$input);
        if ($digits === '') return false;
        return (bool) preg_match('/^(05\d{8}|5\d{8}|9665\d{8})$/', $digits);
    }

    private static function looks_like_order_id($input) {
        $digits = preg_replace('/\D+/', '', (string)$input);
        if ($digits === '') return false;
        return (strlen($digits) >= 3 && strlen($digits) <= 12);
    }

    private static function looks_like_email($input) {
        return is_email($input);
    }

    private static function otp_key($kind, $value) {
        return 'mazen_ot_otp_' . md5($kind . ':' . strtolower(trim((string)$value)));
    }

    private static function verify_key($token) {
        return 'mazen_ot_v_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$token);
    }

    private static function generate_otp() {
        return (string) random_int(100000, 999999);
    }

    private static function hash_otp($otp) {
        return hash('sha256', $otp . wp_salt('auth'));
    }

    private static function send_email_otp($email, $otp) {
        $subject = 'رمز التحقق لتتبع الطلب';
        $message = "رمز التحقق الخاص بك هو: {$otp}\n\nصلاحية الرمز 10 دقائق.";
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        add_filter('wp_mail_from_name', function(){ return get_bloginfo('name'); }, 20);
        return (bool) wp_mail($email, $subject, $message, $headers);
    }

    private static function send_sms_otp($phone_raw, $otp) {
        $to = self::to_e164_ksa($phone_raw);
        if (!$to) return false;

        $msg = "رمز التحقق الخاص بك: {$otp} (صالح لمدة 10 دقائق)";
        $sent = false;

        // Your existing SMS gateway hook
        if (has_action('lwp_send_sms_taqnyat')) {
            do_action('lwp_send_sms_taqnyat', $to, $msg);
            $sent = true;
        }
        // Optional custom hook
        if (has_action('mazen_order_tracker_send_sms')) {
            do_action('mazen_order_tracker_send_sms', $to, $msg);
            $sent = true;
        }
        return $sent;
    }

    private static function request_fingerprint() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $user_id = get_current_user_id();
        return hash('sha256', $ip . '|' . $user_id . '|' . wp_salt('nonce'));
    }

    private static function enforce_request_rate_limit($bucket, $limit = 20, $window = 600) {
        $key = 'mazen_ot_rl_' . md5($bucket . '|' . self::request_fingerprint());
        $data = get_transient($key);
        $count = is_array($data) && isset($data['count']) ? (int) $data['count'] : 0;

        if ($count >= (int) $limit) {
            self::error('طلبات كثيرة خلال فترة قصيرة. فضلاً حاول مرة أخرى لاحقاً.', 'rate_limited');
        }

        set_transient($key, ['count' => $count + 1], max(60, (int) $window));
    }

    private static function identifier_has_orders($kind, $target) {
        if ($kind === 'order') {
            $order = wc_get_order((int) $target);
            return ($order instanceof WC_Order) && (bool) $order->get_billing_phone();
        }

        if ($kind === 'email') {
            $ids = wc_get_orders([
                'limit' => 1,
                'return' => 'ids',
                'billing_email' => $target,
            ]);
            return !empty($ids);
        }

        if ($kind === 'phone') {
            $variants = self::normalize_phone_variants($target);
            return !empty($variants) && !empty(self::get_order_ids_by_phone($variants, 1));
        }

        return false;
    }

    private static function error($msg, $code = 'error') {
        wp_send_json(['ok' => false, 'code' => $code, 'message' => $msg]);
    }

    private static function ok($data = []) {
        wp_send_json(array_merge(['ok' => true], $data));
    }

    public static function shortcode($atts) {
        if (!class_exists('WooCommerce')) {
            return '<div class="mazen-ot-box">هذه الميزة تتطلب WooCommerce.</div>';
        }

        wp_enqueue_style('mazen-ot');
        wp_enqueue_script('mazen-ot');
        wp_localize_script('mazen-ot', 'MAZEN_OT', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mazen_ot_nonce'),
        ]);

        ob_start(); ?>
        <div class="mazen-ot-wrap" dir="rtl">
            <div class="mazen-ot-card">
                <div class="mazen-ot-head">
                    <div class="mazen-ot-title">تتبع الطلب</div>
                    <div class="mazen-ot-sub">اكتب رقم الطلب أو البريد الإلكتروني أو رقم الجوال</div>
                </div>

                <div class="mazen-ot-form">
                    <label class="mazen-ot-label" for="mazen_ot_input">المعرف</label>
                    <input id="mazen_ot_input" class="mazen-ot-input" type="text" placeholder="مثال: 12543 أو user@email.com أو 05xxxxxxxx" />
                    <button id="mazen_ot_btn" class="mazen-ot-btn" type="button">إرسال رمز التحقق</button>

                    <div id="mazen_ot_otp_block" style="display:none;">
                        <label class="mazen-ot-label" for="mazen_ot_otp">رمز التحقق</label>
                        <input id="mazen_ot_otp" class="mazen-ot-input" type="text" inputmode="numeric" maxlength="6" placeholder="أدخل الرمز المكون من 6 أرقام" />
                        <div class="mazen-ot-row">
                            <button id="mazen_ot_verify" class="mazen-ot-btn" type="button">تحقق</button>
                            <button id="mazen_ot_resend" class="mazen-ot-btn mazen-ot-btn--ghost" type="button">إعادة إرسال</button>
                        </div>
                    </div>

                    <div id="mazen_ot_msg" class="mazen-ot-msg" style="display:none;"></div>
                </div>

                <div id="mazen_ot_results" class="mazen-ot-results" style="display:none;"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_start() {
        check_ajax_referer('mazen_ot_nonce', 'nonce');
        if (!class_exists('WooCommerce')) self::error('WooCommerce غير متوفر.');
        self::enforce_request_rate_limit('start', 20, 10 * MINUTE_IN_SECONDS);

        $input = isset($_POST['input']) ? sanitize_text_field(wp_unslash($_POST['input'])) : '';
        if ($input === '') self::error('فضلاً أدخل رقم الطلب أو البريد أو الجوال.');

        $kind = '';
        $target = '';

        if (self::looks_like_email($input)) {
            $kind = 'email';
            $target = strtolower(trim($input));
        } elseif (self::looks_like_phone($input)) {
            $kind = 'phone';
            $target = preg_replace('/\s+/', '', $input);
        } elseif (self::looks_like_order_id($input)) {
            $kind = 'order';
            $target = preg_replace('/\D+/', '', $input);
        } else {
            self::error('صيغة المُدخل غير صحيحة. استخدم رقم طلب/بريد/جوال.');
        }

        // Do not send OTP messages to arbitrary addresses/numbers that are not tied to an order.
        // The generic success response also avoids exposing whether an order exists.
        if (!self::identifier_has_orders($kind, $target)) {
            self::ok([
                'kind' => $kind,
                'message' => 'إذا وجدنا طلباً مطابقاً، فسيتم إرسال رمز التحقق إلى جهة الاتصال المسجلة.',
            ]);
        }

        $otp_key = self::otp_key($kind, $target);
        $existing = get_transient($otp_key);
        if (is_array($existing) && !empty($existing['cooldown_until']) && time() < (int)$existing['cooldown_until']) {
            self::error('تم إرسال رمز قبل قليل. فضلاً انتظر دقيقة ثم أعد المحاولة.', 'cooldown');
        }

        $otp = self::generate_otp();
        $payload = [
            'hash' => self::hash_otp($otp),
            'cooldown_until' => time() + self::RESEND_COOLDOWN_SECONDS,
            'attempts' => 0,
            'kind' => $kind,
            'target' => $target,
        ];

        $sent = false;
        $mask = '';

        if ($kind === 'email') {
            $sent = self::send_email_otp($target, $otp);
            $mask = preg_replace('/(^.).*(@.*$)/', '$1***$2', $target);
        } elseif ($kind === 'phone') {
            $sent = self::send_sms_otp($target, $otp);
            $digits = preg_replace('/\D+/', '', $target);
            $mask = (strlen($digits) >= 4) ? ('***' . substr($digits, -4)) : '***';
        } else { // order -> OTP to billing mobile
            $order_id = (int) $target;
            $order = wc_get_order($order_id);
            if (!$order) self::error('رقم الطلب غير صحيح.');

            $phone = $order->get_billing_phone();
            if (!$phone) self::error('لا يوجد رقم جوال مسجل لهذا الطلب.');

            $sent = self::send_sms_otp($phone, $otp);
            $digits = preg_replace('/\D+/', '', $phone);
            $mask = (strlen($digits) >= 4) ? ('***' . substr($digits, -4)) : '***';
            $payload['order_id'] = $order_id;
        }

        if (!$sent) self::error('تعذّر إرسال الرمز. تأكد من إعدادات البريد/SMS.', 'send_failed');

        set_transient($otp_key, $payload, self::OTP_TTL_SECONDS);

        self::ok([
            'kind' => $kind,
            'message' => ($kind === 'email') ? "تم إرسال الرمز إلى البريد: {$mask}" : "تم إرسال الرمز إلى الجوال: {$mask}",
        ]);
    }

    public static function ajax_verify() {
        check_ajax_referer('mazen_ot_nonce', 'nonce');
        self::enforce_request_rate_limit('verify', 30, 10 * MINUTE_IN_SECONDS);

        $input = isset($_POST['input']) ? sanitize_text_field(wp_unslash($_POST['input'])) : '';
        $otp   = isset($_POST['otp']) ? sanitize_text_field(wp_unslash($_POST['otp'])) : '';

        if ($input === '' || $otp === '') self::error('أدخل المُعرف ورمز التحقق.');
        if (!preg_match('/^\d{6}$/', $otp)) self::error('رمز غير صحيح.');

        $kind = '';
        $target = '';

        if (self::looks_like_email($input)) {
            $kind = 'email';
            $target = strtolower(trim($input));
        } elseif (self::looks_like_phone($input)) {
            $kind = 'phone';
            $target = preg_replace('/\s+/', '', $input);
        } elseif (self::looks_like_order_id($input)) {
            $kind = 'order';
            $target = preg_replace('/\D+/', '', $input);
        } else {
            self::error('صيغة المُدخل غير صحيحة.');
        }

        $otp_key = self::otp_key($kind, $target);
        $payload = get_transient($otp_key);
        if (!is_array($payload) || empty($payload['hash'])) self::error('انتهت صلاحية الرمز. أعد الإرسال.');

        $attempts = (int)($payload['attempts'] ?? 0);
        if ($attempts >= self::MAX_VERIFY_ATTEMPTS) self::error('تم تجاوز عدد المحاولات. أعد الإرسال.');

        $payload['attempts'] = $attempts + 1;
        set_transient($otp_key, $payload, self::OTP_TTL_SECONDS);

        if (!hash_equals($payload['hash'], self::hash_otp($otp))) self::error('رمز غير صحيح.');

        $token = wp_generate_password(32, false, false);
        $vkey  = self::verify_key($token);

        set_transient($vkey, [
            'kind' => $kind,
            'target' => $target,
            'order_id' => isset($payload['order_id']) ? (int)$payload['order_id'] : 0,
        ], self::VERIFY_TTL_SECONDS);

        delete_transient($otp_key);
        self::ok(['token' => $token, 'message' => 'تم التحقق بنجاح. جارٍ جلب بيانات الطلب...']);
    }

    
	private static function is_hpos_enabled() {
		if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * HPOS-safe phone lookup:
	 * - If HPOS enabled: search wc_order_addresses (billing/shipping phone).
	 * - Else: search wp_postmeta (_billing_phone/_shipping_phone).
	 */
	private static function get_order_ids_by_phone(array $variants, $limit = 200) {
		global $wpdb;
		$variants = array_values(array_unique(array_filter($variants)));
		if (empty($variants)) return [];

		$variants_digits = array_values(array_unique(array_map(function($v){
			return preg_replace('/\D+/', '', (string)$v);
		}, $variants)));
		$variants_digits = array_values(array_filter($variants_digits));
		if (empty($variants_digits)) return [];

		$placeholders = implode(',', array_fill(0, count($variants_digits), '%s'));

		$clean_expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s,'+',''),' ',''),'-',''),'(',''),')','')";

		if (self::is_hpos_enabled()) {
			$addr_table = $wpdb->prefix . "wc_order_addresses";
			$orders_table = $wpdb->prefix . "wc_orders";

			$phone_clean = sprintf($clean_expr, "phone");
			$sql = "
				SELECT DISTINCT a.order_id
				FROM {$addr_table} a
				JOIN {$orders_table} o ON o.id = a.order_id
				WHERE a.address_type IN ('billing','shipping')
				  AND {$phone_clean} IN ($placeholders)
				ORDER BY o.date_created_gmt DESC
				LIMIT %d
			";
			$params = array_merge($variants_digits, [(int)$limit]);
			$query = $wpdb->prepare($sql, $params);
			$ids = $wpdb->get_col($query);
			return array_map('intval', $ids);
		}

		$meta_table = $wpdb->postmeta;
		$posts_table = $wpdb->posts;

		$meta_clean = sprintf($clean_expr, "pm.meta_value");
		$sql = "
			SELECT DISTINCT pm.post_id
			FROM {$meta_table} pm
			JOIN {$posts_table} p ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
			  AND pm.meta_key IN ('_billing_phone','_shipping_phone')
			  AND {$meta_clean} IN ($placeholders)
			ORDER BY p.post_date DESC
			LIMIT %d
		";
		$params = array_merge($variants_digits, [(int)$limit]);
		$query = $wpdb->prepare($sql, $params);
		$ids = $wpdb->get_col($query);
		return array_map('intval', $ids);
	}

	public static function ajax_fetch() {
        check_ajax_referer('mazen_ot_nonce', 'nonce');
        if (!class_exists('WooCommerce')) self::error('WooCommerce غير متوفر.');

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if ($token === '') self::error('تحقق غير صالح.');

        $verification = get_transient(self::verify_key($token));
        if (!is_array($verification) || empty($verification['kind'])) self::error('انتهت صلاحية التحقق. أعد المحاولة.');

        $kind = $verification['kind'];
        $target = $verification['target'];
        $order_id = (int)($verification['order_id'] ?? 0);

        $orders = [];

        if ($kind === 'order') {
            $o = wc_get_order($order_id);
            if ($o) $orders = [$o];
        } elseif ($kind === 'email') {
            $orders = wc_get_orders([
                'limit' => 10,
                'orderby' => 'date',
                'order' => 'DESC',
                'billing_email' => $target,
            ]);
        } else { // phone
			$variants = self::normalize_phone_variants($target);
			if (empty($variants)) self::error('رقم جوال غير صحيح.');

			// HPOS-safe lookup by exact normalized phone (digits only)
			$ids = self::get_order_ids_by_phone($variants, 300);

			$orders = [];
			$variant_digits = array_values(array_unique(array_map(function($v){
				return preg_replace('/\D+/', '', (string)$v);
			}, $variants)));
			$variant_set = array_flip(array_filter($variant_digits));

			foreach ($ids as $id) {
				$o = wc_get_order((int)$id);
				if (!($o instanceof WC_Order)) continue;

				$bp = preg_replace('/\D+/', '', (string)$o->get_billing_phone());
				$sp = preg_replace('/\D+/', '', (string)$o->get_shipping_phone());
				$bp_meta = preg_replace('/\D+/', '', (string)$o->get_meta('_billing_phone', true));
				$sp_meta = preg_replace('/\D+/', '', (string)$o->get_meta('_shipping_phone', true));

				if (($bp !== '' && isset($variant_set[$bp])) ||
					($sp !== '' && isset($variant_set[$sp])) ||
					($bp_meta !== '' && isset($variant_set[$bp_meta])) ||
					($sp_meta !== '' && isset($variant_set[$sp_meta]))
				) {
					$orders[] = $o;
				}

				if (count($orders) >= 100) break;
			}
		}

		if (empty($orders)) {
            self::ok(['html' => '<div class="mazen-ot-empty">لا توجد طلبات مطابقة.</div>']);
        }

        $html = '';
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) continue;

            $oid = $order->get_id();
            $status_ar = self::arabic_status_label($order->get_status());
            $paid_ar = self::arabic_paid_label($order);
            $payment_title = esc_html($order->get_payment_method_title());

            $date = $order->get_date_created();
            $date_str = $date ? esc_html($date->date_i18n('Y-m-d H:i')) : '';

            $total_num = wc_format_decimal($order->get_total(), 2);
            $total_str = esc_html($total_num . ' ' . $order->get_currency());

            $billing_email = esc_html($order->get_billing_email());
            $billing_phone = esc_html($order->get_billing_phone());

            $name = trim($order->get_formatted_billing_full_name());
            $name = $name !== '' ? esc_html($name) : '—';

            $waybill = self::get_jnt_waybill($order);
            $waybill_html = $waybill ? ('<div class="mazen-ot-kv mazen-ot-kv--waybill"><span>رقم تتبع J&amp;T</span><strong dir="ltr">' . esc_html($waybill) . '</strong></div>') : '';

            $items_html = '';
            foreach ($order->get_items() as $item) {
                $items_html .= '<li>' . esc_html(wp_strip_all_tags($item->get_name())) . ' <span class="mazen-ot-muted">× ' . esc_html($item->get_quantity()) . '</span></li>';
            }

            $html .= '<div class="mazen-ot-order">';
            $html .= '<div class="mazen-ot-order-head">';
            $html .= '<div class="mazen-ot-order-title">طلب #' . esc_html($oid) . '</div>';
            $html .= '<div class="mazen-ot-badges">';
            $html .= '<span class="mazen-ot-badge">' . esc_html($status_ar) . '</span>';
            $html .= '<span class="mazen-ot-badge mazen-ot-badge--paid">' . esc_html($paid_ar) . '</span>';
            $html .= '</div></div>';

            $html .= '<div class="mazen-ot-grid">';
            $html .= '<div class="mazen-ot-kv"><span>اسم العميل</span><strong>' . $name . '</strong></div>';
            $html .= '<div class="mazen-ot-kv"><span>التاريخ</span><strong>' . $date_str . '</strong></div>';
            $html .= '<div class="mazen-ot-kv"><span>الإجمالي</span><strong>' . $total_str . '</strong></div>';
            $html .= '<div class="mazen-ot-kv"><span>طريقة الدفع</span><strong>' . $payment_title . '</strong></div>';
            $html .= '<div class="mazen-ot-kv"><span>البريد</span><strong>' . $billing_email . '</strong></div>';
            $html .= '<div class="mazen-ot-kv"><span>الجوال</span><strong dir="ltr">' . $billing_phone . '</strong></div>';
            $html .= $waybill_html;
            $html .= '</div>';

            $html .= '<div class="mazen-ot-items"><div class="mazen-ot-items-title">المنتجات</div><ul>' . $items_html . '</ul></div>';
            $html .= '</div>';
        }

        // Verification tokens are single-use after a successful fetch.
        delete_transient(self::verify_key($token));
        self::ok(['html' => $html]);
    }

    private static function get_jnt_waybill(WC_Order $order) {
        $keys = apply_filters('mazen_order_tracker_tracking_meta_keys', ['_jnt_waybill','jnt_waybill','_jnt_tracking_number','jnt_tracking_number','_waybill','waybill','_tracking_number','tracking_number'], $order);
        foreach ($keys as $k) {
            $v = $order->get_meta($k);
            if (is_string($v) && $v !== '') return trim($v);
        }
        foreach ($order->get_meta_data() as $meta) {
            $val = $meta->value;
            if (is_string($val) && preg_match('/\bJTE\d{6,}\b/i', $val, $m)) return strtoupper($m[0]);
        }
        return '';
    }

    public static function render_myaccount_extras($order) {
        if (!$order instanceof WC_Order) return;

        $waybill = self::get_jnt_waybill($order);
        $status_ar = self::arabic_status_label($order->get_status());
        $paid_ar = self::arabic_paid_label($order);
        $payment_title = esc_html($order->get_payment_method_title());
        $name = trim($order->get_formatted_billing_full_name());
        $name = $name !== '' ? esc_html($name) : '—';

        echo '<div dir="rtl" style="margin-top:14px;padding:14px;border:1px solid #eee;border-radius:12px;background:#fafafa;">';
        echo '<h3 style="margin:0 0 10px;">معلومات العميل والتتبع</h3>';
        echo '<p style="margin:0 0 8px;"><strong>اسم العميل:</strong> ' . $name . '</p>';
        echo '<p style="margin:0 0 8px;"><strong>حالة الطلب:</strong> ' . esc_html($status_ar) . ' — <strong>الدفع:</strong> ' . esc_html($paid_ar) . '</p>';
        echo '<p style="margin:0 0 8px;"><strong>طريقة الدفع:</strong> ' . $payment_title . '</p>';
        if ($waybill) echo '<p style="margin:0;"><strong>رقم تتبع J&amp;T:</strong> <span dir="ltr">' . esc_html($waybill) . '</span></p>';
        echo '</div>';
    }

    public static function admin_metabox() {
        if (!function_exists('wc_get_page_screen_id')) return;
        add_meta_box('mazen_ot_admin_box','معلومات العميل والتتبع',[__CLASS__,'admin_metabox_render'], wc_get_page_screen_id('shop-order'),'side','default');
    }

    public static function admin_metabox_render($post_or_order) {
        if ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
        } else {
            $order_id = is_object($post_or_order) && isset($post_or_order->ID) ? (int) $post_or_order->ID : absint($post_or_order);
            $order = wc_get_order($order_id);
        }

        if (!$order instanceof WC_Order) { echo '<p>تعذّر تحميل الطلب.</p>'; return; }

        $waybill = self::get_jnt_waybill($order);
        $status_ar = self::arabic_status_label($order->get_status());
        $paid_ar = self::arabic_paid_label($order);
        $payment_title = esc_html($order->get_payment_method_title());
        $name = trim($order->get_formatted_billing_full_name());
        $name = $name !== '' ? esc_html($name) : '—';

        echo '<p><strong>اسم العميل:</strong><br>' . $name . '</p>';
        echo '<p><strong>حالة الطلب:</strong> ' . esc_html($status_ar) . '</p>';
        echo '<p><strong>الدفع:</strong> ' . esc_html($paid_ar) . '</p>';
		echo '<p><strong>طريقة الدفع:</strong><br>' . $payment_title . '</p>';
        if ($waybill) echo '<p><strong>J&amp;T Waybill:</strong><br><span dir="ltr">' . esc_html($waybill) . '</span></p>';
    }
}

add_action('before_woocommerce_init', static function () {
    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

Mazen_Order_Tracker::init();

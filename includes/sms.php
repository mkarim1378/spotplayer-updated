<?php
if (!defined('ABSPATH')) exit;

function spot_sms_is_enabled(): bool {
	$sp = get_option('spotplayer', []);
	return !empty($sp['sms_enabled'])
		&& !empty($sp['sms_username'])
		&& !empty($sp['sms_password'])
		&& !empty($sp['sms_from']);
}

function spot_sms_normalize_phone(string $phone): string {
	$fa    = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
	$en    = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
	$phone = str_replace($fa, $en, $phone);
	$phone = preg_replace('/[^0-9+]/', '', $phone);

	if (strpos($phone, '+98') === 0) $phone = '0' . substr($phone, 3);
	if (strpos($phone, '98')  === 0 && strlen($phone) === 12) $phone = '0' . substr($phone, 2);

	return preg_match('/^09[0-9]{9}$/', $phone) ? $phone : '';
}

/**
 * Substitute template variables for message-1 (explanation text).
 * {license_key} is intentionally NOT replaced here — it only goes in message-2.
 */
function spot_sms_build_message(string $template, $order): string {
	if ($order instanceof WC_Order) {
		$vars = [
			'{customer_name}' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
			'{order_id}'      => (string) $order->get_order_number(),
			'{course_names}'  => spot_sms_course_names_woo($order),
			'{site_name}'     => get_bloginfo('name'),
			'{site_url}'      => get_bloginfo('url'),
		];
	} elseif (class_exists('EDD_Payment') && $order instanceof EDD_Payment) {
		$info = $order->user_info;
		$vars = [
			'{customer_name}' => trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? '')),
			'{order_id}'      => (string) $order->number,
			'{course_names}'  => '',
			'{site_name}'     => get_bloginfo('name'),
			'{site_url}'      => get_bloginfo('url'),
		];
	} else {
		return $template;
	}

	return str_replace(array_keys($vars), array_values($vars), $template);
}

function spot_sms_course_names_woo(WC_Order $order): string {
	$by_id = array_column(get_option('spot_courses', []), 'name', 'id');
	$names = [];
	foreach ($order->get_items() as $item) {
		if (!($item instanceof WC_Order_Item_Product)) continue;
		$product = $item->get_product();
		if (!($product instanceof WC_Product)) continue;
		foreach (array_filter(explode(',', (string) $product->get_meta('_spotplayer_course'))) as $id)
			$names[] = $by_id[$id] ?? $id;
	}
	return implode('، ', array_unique($names));
}

/**
 * Send a single SMS via Payamito REST API.
 *
 * @return array{ok: bool, msg_id: int|null, code: int|null, error: string}
 */
function spot_sms_send_raw(string $phone, string $text): array {
	$sp   = get_option('spotplayer', []);
	$body = [
		'username' => $sp['sms_username'] ?? '',
		'password' => $sp['sms_password'] ?? '',
		'to'       => $phone,
		'text'     => $text,
		'from'     => $sp['sms_from'] ?? '',
	];
	if (!empty($sp['sms_from1'])) $body['fromSupportOne'] = $sp['sms_from1'];
	if (!empty($sp['sms_from2'])) $body['fromSupportTwo'] = $sp['sms_from2'];

	$response = wp_remote_post('https://rest.payamak-panel.com/api/SmartSMS/Send', [
		'timeout' => 15,
		'body'    => $body,
	]);

	if (is_wp_error($response))
		return ['ok' => false, 'msg_id' => null, 'code' => null, 'error' => $response->get_error_message()];

	$data = json_decode(wp_remote_retrieve_body($response), true);
	if (!is_array($data))
		return ['ok' => false, 'msg_id' => null, 'code' => null, 'error' => 'پاسخ نامفهوم از پیامیتو.'];

	$ret_status = intval($data['RetStatus'] ?? 0);
	$value      = trim((string) ($data['Value'] ?? ''));

	// RetStatus=1 → request accepted, Value = message ID
	if ($ret_status === 1)
		return ['ok' => true, 'msg_id' => is_numeric($value) && (int) $value > 0 ? (int) $value : null, 'code' => 0, 'error' => ''];

	// Value=7 → content filtered but still sent from the main line → treat as success
	if ($value === '7')
		return ['ok' => true, 'msg_id' => null, 'code' => 7, 'error' => ''];

	static $errors = [
		'0'  => 'نام کاربری یا رمز پیامیتو اشتباه است.',
		'2'  => 'اعتبار حساب پیامیتو کافی نیست.',
		'4'  => 'محدودیت حجم ارسال پیامیتو.',
		'5'  => 'شماره فرستنده پیامیتو معتبر نیست.',
		'9'  => 'ارسال از خطوط عمومی مجاز نیست.',
		'14' => 'متن پیامک حاوی لینک است.',
		'15' => 'عبارت «لغو۱۱» در انتهای متن وجود ندارد.',
	];

	return [
		'ok'     => false,
		'msg_id' => null,
		'code'   => (int) $value,
		'error'  => $errors[$value] ?? ('خطای ناشناخته پیامیتو: ' . $value),
	];
}

// ── AJAX: test SMS from settings page ────────────────────────────────────────

add_action('wp_ajax_spot_test_sms', 'spot_ajax_test_sms');
function spot_ajax_test_sms(): void {
	if (!current_user_can('manage_options')) wp_send_json_error('unauthorized');
	check_ajax_referer('spot_test_sms', 'nonce');

	$phone = spot_sms_normalize_phone(sanitize_text_field($_POST['phone'] ?? ''));
	if (!$phone) { wp_send_json_error('شماره موبایل وارد‌شده معتبر نیست.'); return; }

	// Use credentials from POST so admin can test before saving
	$saved = get_option('spotplayer', []);
	add_filter('pre_option_spotplayer', function () use ($saved) {
		return array_merge($saved, [
			'sms_username' => sanitize_text_field($_POST['sms_username'] ?? $saved['sms_username'] ?? ''),
			'sms_password' => sanitize_text_field($_POST['sms_password'] ?? $saved['sms_password'] ?? ''),
			'sms_from'     => sanitize_text_field($_POST['sms_from']     ?? $saved['sms_from']     ?? ''),
			'sms_from1'    => sanitize_text_field($_POST['sms_from1']    ?? $saved['sms_from1']    ?? ''),
			'sms_from2'    => sanitize_text_field($_POST['sms_from2']    ?? $saved['sms_from2']    ?? ''),
		]);
	});

	$result = spot_sms_send_raw($phone, 'پیامک آزمایشی از افزونه اسپات پلیر — ' . get_bloginfo('name'));

	if ($result['ok']) wp_send_json_success('پیامک آزمایشی با موفقیت ارسال شد.');
	else               wp_send_json_error($result['error'] ?: 'ارسال ناموفق بود.');
}

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
	if (strlen($phone) === 10 && strpos($phone, '9') === 0)   $phone = '0' . $phone;

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

// ── Async sending — WooCommerce ──────────────────────────────────────────────

function spot_sms_trigger_woo(WC_Order $order): void {
	if (!spot_sms_is_enabled()) return;

	$phone = spot_sms_normalize_phone($order->get_billing_phone());
	if (!$phone) {
		$order->add_order_note('⚠️ شماره تلفن در سفارش یافت نشد — پیامک ارسال نشد.');
		return;
	}

	spot_sms_init_order_meta($order, $phone);

	if (function_exists('as_schedule_single_action')) {
		as_schedule_single_action(time() + 5, 'spot_sms_msg1', [
			'order_id' => $order->get_id(), 'platform' => 'woo', 'attempt' => 1,
		]);
		return;
	}

	// Synchronous fallback (no AS) — no retry possible
	$sp = get_option('spotplayer', []);
	$r1 = spot_sms_send_raw($phone, spot_sms_build_message((string) ($sp['sms_template'] ?? ''), $order));
	if ($r1['ok']) {
		$key = (string) ((array) $order->get_meta('_spotplayer_data'))['key'] ?? '';
		if ($key) {
			$r2 = spot_sms_send_raw($phone, $key);
			if (!$r2['ok'])
				$order->add_order_note('⚠️ پیامک اول ارسال شد اما کد لایسنس ارسال نشد: ' . $r2['error']);
		} else {
			$order->add_order_note('⚠️ کد لایسنس یافت نشد — پیامک دوم ارسال نشد.');
		}
	} else {
		$order->add_order_note('⚠️ ارسال پیامک ناموفق بود: ' . $r1['error'] . ' — برای retry خودکار، Action Scheduler لازم است.');
	}
}

function spot_sms_init_order_meta(WC_Order $order, string $phone): void {
	foreach ([
		'_spot_sms_phone'         => $phone,
		'_spot_sms_msg1_status'   => 'pending',
		'_spot_sms_msg1_attempts' => 0,
		'_spot_sms_msg1_sent_at'  => null,
		'_spot_sms_msg1_id'       => null,
		'_spot_sms_msg2_status'   => 'blocked',
		'_spot_sms_msg2_attempts' => 0,
		'_spot_sms_msg2_sent_at'  => null,
		'_spot_sms_msg2_id'       => null,
	] as $key => $val) $order->update_meta_data($key, $val);
	$order->save_meta_data();
}

// ── Async sending — EDD ──────────────────────────────────────────────────────

function spot_sms_trigger_edd(EDD_Payment $pay): void {
	if (!spot_sms_is_enabled()) return;

	$phone = spot_sms_normalize_phone($pay->user_info['phone'] ?? '');
	if (!$phone) {
		edd_insert_payment_note($pay->ID, '⚠️ شماره تلفن در سفارش یافت نشد — پیامک ارسال نشد.');
		return;
	}

	spot_sms_init_order_meta_edd($pay, $phone);

	if (function_exists('as_schedule_single_action')) {
		as_schedule_single_action(time() + 5, 'spot_sms_msg1', [
			'order_id' => $pay->ID, 'platform' => 'edd', 'attempt' => 1,
		]);
		return;
	}

	// Synchronous fallback (no AS)
	$sp = get_option('spotplayer', []);
	$r1 = spot_sms_send_raw($phone, spot_sms_build_message((string) ($sp['sms_template'] ?? ''), $pay));
	if ($r1['ok']) {
		$key = (string) (($pay->get_meta('_spot_data') ?: [])['key'] ?? '');
		if ($key) {
			$r2 = spot_sms_send_raw($phone, $key);
			if (!$r2['ok'])
				edd_insert_payment_note($pay->ID, '⚠️ پیامک اول ارسال شد اما کد لایسنس ارسال نشد: ' . $r2['error']);
		} else {
			edd_insert_payment_note($pay->ID, '⚠️ کد لایسنس یافت نشد — پیامک دوم ارسال نشد.');
		}
	} else {
		edd_insert_payment_note($pay->ID, '⚠️ ارسال پیامک ناموفق بود: ' . $r1['error'] . ' — برای retry خودکار، Action Scheduler لازم است.');
	}
}

function spot_sms_init_order_meta_edd(EDD_Payment $pay, string $phone): void {
	foreach ([
		'_spot_sms_phone'         => $phone,
		'_spot_sms_msg1_status'   => 'pending',
		'_spot_sms_msg1_attempts' => 0,
		'_spot_sms_msg1_sent_at'  => null,
		'_spot_sms_msg1_id'       => null,
		'_spot_sms_msg2_status'   => 'blocked',
		'_spot_sms_msg2_attempts' => 0,
		'_spot_sms_msg2_sent_at'  => null,
		'_spot_sms_msg2_id'       => null,
	] as $key => $val) $pay->update_meta($key, $val);
}

// ── Shared schedule/retry helpers ────────────────────────────────────────────

function spot_sms_schedule_msg1(int $order_id, string $platform, int $attempt, int $delay): void {
	if (!function_exists('as_schedule_single_action')) return;
	as_schedule_single_action(time() + $delay, 'spot_sms_msg1', [
		'order_id' => $order_id, 'platform' => $platform, 'attempt' => $attempt,
	]);
}

function spot_sms_schedule_msg2(int $order_id, string $platform, int $attempt, int $delay): void {
	if (!function_exists('as_schedule_single_action')) return;
	as_schedule_single_action(time() + $delay, 'spot_sms_msg2', [
		'order_id' => $order_id, 'platform' => $platform, 'attempt' => $attempt,
	]);
}

function spot_sms_retry_delay(int $failed_attempt): int {
	return $failed_attempt <= 2 ? 120 : 300;
}

function spot_sms_cancel_pending_for_order(int $order_id): void {
	if (!function_exists('as_unschedule_all_actions')) return;
	foreach (['woo', 'edd'] as $p) {
		as_unschedule_all_actions('spot_sms_msg1', ['order_id' => $order_id, 'platform' => $p]);
		as_unschedule_all_actions('spot_sms_msg2', ['order_id' => $order_id, 'platform' => $p]);
	}
}

// ── Action Scheduler handlers ────────────────────────────────────────────────

add_action('spot_sms_msg1', 'spot_sms_handle_msg1', 10, 3);
function spot_sms_handle_msg1(int $order_id, string $platform, int $attempt): void {
	if ($platform === 'edd') {
		$pay = function_exists('edd_get_payment') ? edd_get_payment($order_id) : null;
		if ($pay instanceof EDD_Payment) spot_sms_do_msg1_edd($pay, $order_id, $platform, $attempt);
		return;
	}

	$order = wc_get_order($order_id);
	if (!($order instanceof WC_Order)) return;

	$phone = (string) $order->get_meta('_spot_sms_phone');
	$sp    = get_option('spotplayer', []);
	$text  = spot_sms_build_message((string) ($sp['sms_template'] ?? ''), $order);

	$order->update_meta_data('_spot_sms_msg1_attempts', $attempt);
	$order->save_meta_data();

	$result = spot_sms_send_raw($phone, $text);

	if ($result['ok']) {
		$order->update_meta_data('_spot_sms_msg1_status',  'sent');
		$order->update_meta_data('_spot_sms_msg1_sent_at', time());
		$order->update_meta_data('_spot_sms_msg1_id',      $result['msg_id']);
		$order->save_meta_data();
		$order->add_order_note('📲 پیامک توضیحات لایسنس به ' . $phone . ' ارسال شد.');
		spot_sms_schedule_msg2($order_id, $platform, 1, 0);
		return;
	}

	if ($attempt === 5)
		$order->add_order_note('⚠️ ارسال پیامک لایسنس پس از ۵ بار تلاش ناموفق بود. هر ۵ دقیقه مجدداً تلاش می‌شود. تنظیمات پیامک را بررسی کنید.');

	spot_sms_schedule_msg1($order_id, $platform, $attempt + 1, spot_sms_retry_delay($attempt));
}

function spot_sms_do_msg1_edd(EDD_Payment $pay, int $order_id, string $platform, int $attempt): void {
	$phone  = (string) $pay->get_meta('_spot_sms_phone');
	$sp     = get_option('spotplayer', []);
	$text   = spot_sms_build_message((string) ($sp['sms_template'] ?? ''), $pay);

	$pay->update_meta('_spot_sms_msg1_attempts', $attempt);

	$result = spot_sms_send_raw($phone, $text);

	if ($result['ok']) {
		$pay->update_meta('_spot_sms_msg1_status',  'sent');
		$pay->update_meta('_spot_sms_msg1_sent_at', time());
		$pay->update_meta('_spot_sms_msg1_id',      $result['msg_id']);
		edd_insert_payment_note($pay->ID, '📲 پیامک توضیحات لایسنس به ' . $phone . ' ارسال شد.');
		spot_sms_schedule_msg2($order_id, $platform, 1, 0);
		return;
	}

	if ($attempt === 5)
		edd_insert_payment_note($pay->ID, '⚠️ ارسال پیامک لایسنس پس از ۵ بار تلاش ناموفق بود. هر ۵ دقیقه مجدداً تلاش می‌شود. تنظیمات پیامک را بررسی کنید.');

	spot_sms_schedule_msg1($order_id, $platform, $attempt + 1, spot_sms_retry_delay($attempt));
}

add_action('spot_sms_msg2', 'spot_sms_handle_msg2', 10, 3);
function spot_sms_handle_msg2(int $order_id, string $platform, int $attempt): void {
	if ($platform === 'edd') {
		$pay = function_exists('edd_get_payment') ? edd_get_payment($order_id) : null;
		if ($pay instanceof EDD_Payment) spot_sms_do_msg2_edd($pay, $order_id, $platform, $attempt);
		return;
	}

	$order = wc_get_order($order_id);
	if (!($order instanceof WC_Order)) return;

	$phone = (string) $order->get_meta('_spot_sms_phone');
	$key   = (string) (((array) $order->get_meta('_spotplayer_data'))['key'] ?? '');

	$order->update_meta_data('_spot_sms_msg2_status',   'pending');
	$order->update_meta_data('_spot_sms_msg2_attempts', $attempt);
	$order->save_meta_data();

	if (!$key) {
		$order->update_meta_data('_spot_sms_msg2_status', 'failed');
		$order->save_meta_data();
		$order->add_order_note('⚠️ کد لایسنس در اطلاعات سفارش یافت نشد — پیامک دوم ارسال نشد.');
		return;
	}

	$result = spot_sms_send_raw($phone, $key);

	if ($result['ok']) {
		$order->update_meta_data('_spot_sms_msg2_status',  'sent');
		$order->update_meta_data('_spot_sms_msg2_sent_at', time());
		$order->update_meta_data('_spot_sms_msg2_id',      $result['msg_id']);
		$order->save_meta_data();
		$order->add_order_note('📲 کد لایسنس به ' . $phone . ' ارسال شد.');
		return;
	}

	if ($attempt === 5)
		$order->add_order_note('⚠️ ارسال کد لایسنس پس از ۵ بار تلاش ناموفق بود. هر ۵ دقیقه مجدداً تلاش می‌شود.');

	spot_sms_schedule_msg2($order_id, $platform, $attempt + 1, spot_sms_retry_delay($attempt));
}

function spot_sms_do_msg2_edd(EDD_Payment $pay, int $order_id, string $platform, int $attempt): void {
	$phone = (string) $pay->get_meta('_spot_sms_phone');
	$key   = (string) (($pay->get_meta('_spot_data') ?: [])['key'] ?? '');

	$pay->update_meta('_spot_sms_msg2_status',   'pending');
	$pay->update_meta('_spot_sms_msg2_attempts', $attempt);

	if (!$key) {
		$pay->update_meta('_spot_sms_msg2_status', 'failed');
		edd_insert_payment_note($pay->ID, '⚠️ کد لایسنس در اطلاعات سفارش یافت نشد — پیامک دوم ارسال نشد.');
		return;
	}

	$result = spot_sms_send_raw($phone, $key);

	if ($result['ok']) {
		$pay->update_meta('_spot_sms_msg2_status',  'sent');
		$pay->update_meta('_spot_sms_msg2_sent_at', time());
		$pay->update_meta('_spot_sms_msg2_id',      $result['msg_id']);
		edd_insert_payment_note($pay->ID, '📲 کد لایسنس به ' . $phone . ' ارسال شد.');
		return;
	}

	if ($attempt === 5)
		edd_insert_payment_note($pay->ID, '⚠️ ارسال کد لایسنس پس از ۵ بار تلاش ناموفق بود. هر ۵ دقیقه مجدداً تلاش می‌شود.');

	spot_sms_schedule_msg2($order_id, $platform, $attempt + 1, spot_sms_retry_delay($attempt));
}

// ── Admin UI: SMS status section (shared between WC and EDD) ─────────────────

function spot_sms_admin_section($order, int $order_id, string $platform): void {
	if (!spot_sms_is_enabled() || !method_exists($order, 'get_meta')) return;

	$m1s = (string) $order->get_meta('_spot_sms_msg1_status');
	$m1a = (int)    $order->get_meta('_spot_sms_msg1_attempts');
	$m1t = (int)    $order->get_meta('_spot_sms_msg1_sent_at');
	$m2s = (string) $order->get_meta('_spot_sms_msg2_status');
	$m2a = (int)    $order->get_meta('_spot_sms_msg2_attempts');
	$m2t = (int)    $order->get_meta('_spot_sms_msg2_sent_at');

	$fmt = function(string $s, int $a, int $t): string {
		if ($s === 'sent')    return '✅ ارسال شد' . ($t ? ' — ' . date_i18n('Y/m/d H:i', $t) : '');
		if ($s === 'failed')  return '⚠️ ارسال نشد (' . $a . ' بار تلاش)';
		if ($s === 'blocked') return '⏸ در انتظار پیامک اول';
		if ($a > 0)           return '🔄 در حال تلاش (' . $a . ' بار)';
		if ($s)               return '⏳ در صف ارسال';
		return '📵 ارسال نشده';
	};
	?>
	<hr style="margin:10px 0;border:none;border-top:1px solid #ddd">
	<p style="font-size:12px;font-weight:600;margin:0 0 6px">📲 پیامک لایسنس</p>
	<div style="font-size:12px">
		<div style="display:flex;justify-content:space-between;padding:2px 0">
			<span style="color:#666">پیامک ۱:</span>
			<span><?= esc_html($fmt($m1s, $m1a, $m1t)) ?></span>
		</div>
		<div style="display:flex;justify-content:space-between;padding:2px 0">
			<span style="color:#666">پیامک ۲:</span>
			<span><?= esc_html($fmt($m2s, $m2a, $m2t)) ?></span>
		</div>
	</div>
	<button type="button" class="button button-small" id="spot-sms-resend"
		data-order="<?= esc_attr($order_id) ?>"
		data-platform="<?= esc_attr($platform) ?>"
		data-nonce="<?= esc_attr(wp_create_nonce('spot_resend_sms_' . $order_id)) ?>"
		style="margin-top:6px;width:100%">ارسال مجدد پیامک</button>
	<p id="spot-sms-resend-msg" style="font-size:11px;margin:4px 0 0;display:none"></p>
	<script>(function(){
		var btn=document.getElementById('spot-sms-resend'),
			msg=document.getElementById('spot-sms-resend-msg'),
			ajax=<?= json_encode(admin_url('admin-ajax.php')) ?>;
		btn.addEventListener('click',function(){
			btn.disabled=true;btn.textContent='در حال ارسال…';msg.style.display='none';
			var fd=new FormData();
			fd.append('action','spot_resend_sms');
			fd.append('order_id',btn.dataset.order);
			fd.append('platform',btn.dataset.platform);
			fd.append('nonce',btn.dataset.nonce);
			fetch(ajax,{method:'POST',body:fd})
				.then(function(r){return r.json()})
				.then(function(res){
					msg.style.display='block';
					if(res.success){msg.style.color='green';msg.textContent='پیامک مجدداً ارسال می‌شود.';}
					else{msg.style.color='red';msg.textContent=typeof res.data==='string'?res.data:'خطا';}
					btn.disabled=false;btn.textContent='ارسال مجدد پیامک';
				})
				.catch(function(){
					msg.style.display='block';msg.style.color='red';msg.textContent='خطا در ارتباط با سرور';
					btn.disabled=false;btn.textContent='ارسال مجدد پیامک';
				});
		});
	})();</script>
	<?php
}

// ── AJAX: resend SMS from order box ──────────────────────────────────────────

add_action('wp_ajax_spot_resend_sms', 'spot_ajax_resend_sms');
function spot_ajax_resend_sms(): void {
	if (!current_user_can('manage_options')) wp_send_json_error('unauthorized');
	$order_id = (int) ($_POST['order_id'] ?? 0);
	$platform = sanitize_key($_POST['platform'] ?? 'woo');
	check_ajax_referer('spot_resend_sms_' . $order_id, 'nonce');

	if ($platform === 'edd') {
		$pay = function_exists('edd_get_payment') ? edd_get_payment($order_id) : null;
		if (!($pay instanceof EDD_Payment)) { wp_send_json_error('سفارش یافت نشد.'); return; }
		$phone = (string) $pay->get_meta('_spot_sms_phone');
		if (!$phone) {
			$phone = spot_sms_normalize_phone($pay->user_info['phone'] ?? '');
			if (!$phone) { wp_send_json_error('شماره تلفن در سفارش یافت نشد.'); return; }
		}
		spot_sms_cancel_pending_for_order($order_id);
		spot_sms_init_order_meta_edd($pay, $phone);
		spot_sms_schedule_msg1($order_id, 'edd', 1, 0);
		wp_send_json_success();
		return;
	}

	$order = wc_get_order($order_id);
	if (!($order instanceof WC_Order)) { wp_send_json_error('سفارش یافت نشد.'); return; }
	$phone = (string) $order->get_meta('_spot_sms_phone');
	if (!$phone) {
		$phone = spot_sms_normalize_phone($order->get_billing_phone());
		if (!$phone) { wp_send_json_error('شماره تلفن در سفارش یافت نشد.'); return; }
	}
	spot_sms_cancel_pending_for_order($order_id);
	spot_sms_init_order_meta($order, $phone);
	spot_sms_schedule_msg1($order_id, 'woo', 1, 0);
	wp_send_json_success();
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

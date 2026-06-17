<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'spot_sms_report_register_page');
function spot_sms_report_register_page(): void {
	add_submenu_page('spotplayer', 'گزارش پیامک', 'گزارش پیامک', 'manage_options', 'spot-sms-report', 'spot_sms_report_render');
}

function spot_sms_report_get_summary(): array {
	$cached = get_transient('spot_sms_summary');
	if ($cached !== false) return $cached;

	$base = ['limit' => -1, 'return' => 'ids', 'type' => 'shop_order'];

	$sent = count(wc_get_orders(array_merge($base, ['meta_query' => [
		'relation' => 'AND',
		['key' => '_spot_sms_msg1_status', 'value' => 'sent'],
		['key' => '_spot_sms_msg2_status', 'value' => 'sent'],
	]])));

	$pending = count(wc_get_orders(array_merge($base, ['meta_query' => [
		'relation' => 'OR',
		['key' => '_spot_sms_msg1_status', 'value' => 'pending'],
		['key' => '_spot_sms_msg2_status', 'value' => 'pending'],
	]])));

	$failed = count(wc_get_orders(array_merge($base, ['meta_query' => [
		'relation' => 'OR',
		['key' => '_spot_sms_msg1_status', 'value' => ['abandoned', 'failed'], 'compare' => 'IN'],
		['key' => '_spot_sms_msg2_status', 'value' => ['abandoned', 'failed'], 'compare' => 'IN'],
	]])));

	$no_phone = count(wc_get_orders(array_merge($base, ['meta_query' => [
		'relation' => 'AND',
		['key' => '_spotplayer_data', 'compare' => 'EXISTS'],
		['key' => '_spot_sms_phone', 'compare' => 'NOT EXISTS'],
	]])));

	$summary = compact('sent', 'pending', 'failed', 'no_phone');
	set_transient('spot_sms_summary', $summary, 10 * MINUTE_IN_SECONDS);
	return $summary;
}

function spot_sms_report_build_meta_query(string $status): array {
	switch ($status) {
		case 'sent':
			return ['relation' => 'AND',
				['key' => '_spot_sms_msg1_status', 'value' => 'sent'],
				['key' => '_spot_sms_msg2_status', 'value' => 'sent'],
			];
		case 'pending':
			return ['relation' => 'OR',
				['key' => '_spot_sms_msg1_status', 'value' => 'pending'],
				['key' => '_spot_sms_msg2_status', 'value' => 'pending'],
			];
		case 'failed':
			return ['relation' => 'OR',
				['key' => '_spot_sms_msg1_status', 'value' => ['abandoned', 'failed'], 'compare' => 'IN'],
				['key' => '_spot_sms_msg2_status', 'value' => ['abandoned', 'failed'], 'compare' => 'IN'],
			];
		case 'no_phone':
			return ['relation' => 'AND',
				['key' => '_spotplayer_data', 'compare' => 'EXISTS'],
				['key' => '_spot_sms_phone', 'compare' => 'NOT EXISTS'],
			];
		default:
			return [['key' => '_spotplayer_data', 'compare' => 'EXISTS']];
	}
}

function spot_sms_report_render(): void {
	if (!current_user_can('manage_options')) return;

	// ── پردازش فرم تنظیمات پیامک ─────────────────────────────────────────────
	$sms_notice = '';
	if (isset($_POST['spot_save_sms']) && check_admin_referer('spot_save_sms', 'spot_sms_nonce')) {
		$sp_opt = get_option('spotplayer', []);
		$old_report_time = $sp_opt['extra_report_time'] ?? '';

		$sp_opt['sms_enabled']                    = !empty($_POST['spotplayer']['sms_enabled']) ? 1 : 0;
		$sp_opt['sms_username']                   = sanitize_text_field($_POST['spotplayer']['sms_username'] ?? '');
		$sp_opt['sms_password']                   = sanitize_text_field($_POST['spotplayer']['sms_password'] ?? '');
		$sp_opt['sms_from']                       = sanitize_text_field($_POST['spotplayer']['sms_from'] ?? '');
		$sp_opt['sms_from1']                      = sanitize_text_field($_POST['spotplayer']['sms_from1'] ?? '');
		$sp_opt['sms_from2']                      = sanitize_text_field($_POST['spotplayer']['sms_from2'] ?? '');
		$sp_opt['sms_template']                   = sanitize_textarea_field($_POST['spotplayer']['sms_template'] ?? '');
		$sp_opt['sms_template_installment_mid']   = sanitize_textarea_field($_POST['spotplayer']['sms_template_installment_mid'] ?? '');
		$sp_opt['sms_template_installment_final'] = sanitize_textarea_field($_POST['spotplayer']['sms_template_installment_final'] ?? '');
		$sp_opt['sms_template_reminder']          = sanitize_textarea_field($_POST['spotplayer']['sms_template_reminder'] ?? '');
		$sp_opt['sms_template_extra']             = sanitize_textarea_field($_POST['spotplayer']['sms_template_extra'] ?? '');
		$sp_opt['extra_admin_phone']              = sanitize_text_field($_POST['spotplayer']['extra_admin_phone'] ?? '');
		$sp_opt['extra_report_time']              = sanitize_text_field($_POST['spotplayer']['extra_report_time'] ?? '08:00');

		update_option('spotplayer', $sp_opt);

		$new_report_time = $sp_opt['extra_report_time'];
		if ($old_report_time !== $new_report_time && function_exists('spot_extra_reschedule_report')) {
			spot_extra_reschedule_report($old_report_time, $new_report_time);
		}

		$sms_notice = '<div class="notice notice-success is-dismissible"><p>✅ تنظیمات پیامک ذخیره شد.</p></div>';
	}

	$sp = get_option('spotplayer', []);
	?>
	<style>
	.sp-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px 24px; margin-bottom:20px; }
	.sp-card h2 { margin:0 0 16px; padding:0 0 12px; border-bottom:1px solid #f0f0f1; font-size:15px; }
	.sp-field { margin-bottom:16px; }
	.sp-field:last-child { margin-bottom:0; }
	.sp-field > label { display:block; font-weight:600; margin-bottom:5px; }
	.sp-field input[type=text], .sp-field textarea { width:100%; max-width:460px; }
	.sp-field textarea { height:80px; font-family:monospace; direction:ltr; resize:vertical; }
	.sp-check { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-radius:3px; background:#f9f9f9; border:1px solid #f0f0f1; margin-bottom:8px; }
	.sp-check input[type=checkbox] { margin-top:3px; flex-shrink:0; }
	.sp-check-label { font-weight:600; display:block; margin-bottom:2px; cursor:pointer; }
	.sp-check-desc { color:#646970; font-size:12px; }
	.sp-warn { color:#9e2a2a; font-size:12px; margin-top:3px; }
	.sp-sms-counter { font-size:12px; color:#646970; margin-top:4px; }
	.sp-sms-template { direction:rtl !important; font-family:inherit !important; }
	</style>

	<div class="wrap" dir="rtl" style="max-width:900px">

	<?= $sms_notice ?>

	<div class="sp-card" style="margin-bottom:28px">
		<h2>📲 تنظیمات پیامک</h2>
		<form method="post">
			<?php wp_nonce_field('spot_save_sms', 'spot_sms_nonce') ?>
			<input type="hidden" name="spot_save_sms" value="1">

			<div class="sp-check" style="margin-bottom:16px">
				<input type="checkbox" id="sp-sms-enabled" name="spotplayer[sms_enabled]" value="1" <?= !empty($sp['sms_enabled']) ? 'checked' : '' ?>>
				<div>
					<label class="sp-check-label" for="sp-sms-enabled">ارسال خودکار پیامک پس از صدور لایسنس</label>
					<div class="sp-check-desc">پس از ایجاد موفق لایسنس، دو پیامک به شماره مشتری ارسال می‌شود: ابتدا متن توضیحات، سپس کد لایسنس به تنهایی.</div>
				</div>
			</div>
			<div class="sp-field">
				<label>نام کاربری پیامیتو</label>
				<input type="text" name="spotplayer[sms_username]" value="<?= esc_attr(@$sp['sms_username']) ?>" autocomplete="off">
			</div>
			<div class="sp-field">
				<label>کلید API وب‌سرویس پیامیتو</label>
				<input type="text" name="spotplayer[sms_password]" value="<?= esc_attr(@$sp['sms_password']) ?>" style="direction:ltr" autocomplete="off">
				<p class="description" style="margin-top:4px">از منوی <b>توسعه‌دهندگان</b> در پنل پیامیتو — رمز ورود معمولی حساب شما نیست.</p>
			</div>
			<div class="sp-field">
				<label>شماره فرستنده اصلی</label>
				<input type="text" name="spotplayer[sms_from]" value="<?= esc_attr(@$sp['sms_from']) ?>" style="direction:ltr" placeholder="10004...">
			</div>
			<div class="sp-field">
				<label>شماره بکاپ ۱ <span style="font-weight:normal;color:#646970">(اختیاری)</span></label>
				<input type="text" name="spotplayer[sms_from1]" value="<?= esc_attr(@$sp['sms_from1']) ?>" style="direction:ltr">
			</div>
			<div class="sp-field">
				<label>شماره بکاپ ۲ <span style="font-weight:normal;color:#646970">(اختیاری)</span></label>
				<input type="text" name="spotplayer[sms_from2]" value="<?= esc_attr(@$sp['sms_from2']) ?>" style="direction:ltr">
			</div>
			<div class="sp-field">
				<label>متن پیامک توضیحات</label>
				<textarea id="sp-sms-template" name="spotplayer[sms_template]" class="sp-sms-template" style="height:100px"><?= esc_textarea(@$sp['sms_template']) ?></textarea>
				<div id="sp-sms-counter" class="sp-sms-counter"></div>
				<p class="description" style="margin-top:6px">
					متغیرها: <code>{customer_name}</code> <code>{order_id}</code> <code>{course_names}</code> <code>{site_name}</code> <code>{site_url}</code><br>
					<span class="sp-warn">⚠ استفاده از <code>{site_url}</code> ممکن است باعث خطای «حاوی لینک» از پیامیتو شود.</span><br>
					<span class="sp-warn">⚠ پیامک دوم به‌صورت خودکار فقط کد لایسنس ارسال می‌شود. <code>{license_key}</code> در این متن جایگزین نخواهد شد.</span>
				</p>
			</div>
			<div class="sp-field">
				<label>متن پیامک قسط میانی <span style="font-weight:normal;color:#646970">(پس از پرداخت قسط ۲، ۳، ... — بدون ارسال کد لایسنس)</span></label>
				<textarea name="spotplayer[sms_template_installment_mid]" class="sp-sms-template" style="height:100px"><?= esc_textarea(@$sp['sms_template_installment_mid']) ?></textarea>
				<p class="description" style="margin-top:6px">
					متغیرها: <code>{customer_name}</code> <code>{course_names}</code> <code>{installment_number}</code> <code>{total_installments}</code> <code>{order_id}</code>
				</p>
			</div>
			<div class="sp-field">
				<label>متن پیامک قسط آخر <span style="font-weight:normal;color:#646970">(پس از پرداخت آخرین قسط)</span></label>
				<textarea name="spotplayer[sms_template_installment_final]" class="sp-sms-template" style="height:100px"><?= esc_textarea(@$sp['sms_template_installment_final']) ?></textarea>
				<p class="description" style="margin-top:6px">
					متغیرها: <code>{customer_name}</code> <code>{course_names}</code> <code>{total_installments}</code> <code>{order_id}</code>
				</p>
			</div>
			<div class="sp-field">
				<label>متن پیامک یادآور سررسید <span style="font-weight:normal;color:#646970">(ارسال دستی از داشبورد اقساط)</span></label>
				<textarea name="spotplayer[sms_template_reminder]" class="sp-sms-template" style="height:100px"><?= esc_textarea(@$sp['sms_template_reminder']) ?></textarea>
				<p class="description" style="margin-top:6px">
					متغیرها: <code>{customer_name}</code> <code>{course_names}</code> <code>{next_installment_number}</code> <code>{total_installments}</code> <code>{due_date}</code> <code>{order_id}</code>
				</p>
			</div>
			<hr style="margin:20px 0 16px;border:none;border-top:1px solid #f0f0f1">
			<p style="font-weight:600;font-size:13px;margin:0 0 12px;color:#1d2327">💳 پیامک دسترسی اضافه لایسنس</p>
			<div class="sp-field">
				<label>متن پیامک دسترسی اضافه <span style="font-weight:normal;color:#646970">(ارسال خودکار پس از پرداخت موفق)</span></label>
				<textarea name="spotplayer[sms_template_extra]" class="sp-sms-template" style="height:100px"><?= esc_textarea(@$sp['sms_template_extra']) ?></textarea>
				<p class="description" style="margin-top:6px">
					متغیرها: <code>{customer_name}</code> <code>{course_names}</code> <code>{order_id}</code> <code>{site_name}</code><br>
					<span class="sp-warn">⚠ پیامک دوم به‌صورت خودکار فقط کد لایسنس ارسال می‌شود.</span>
				</p>
			</div>

			<hr style="margin:20px 0 16px;border:none;border-top:1px solid #f0f0f1">
			<p style="font-weight:600;font-size:13px;margin:0 0 12px;color:#1d2327">📊 گزارش روزانه درخواست‌های دسترسی اضافه</p>
			<div class="sp-field">
				<label>شماره موبایل ادمین برای گزارش روزانه</label>
				<input type="text" name="spotplayer[extra_admin_phone]" value="<?= esc_attr(@$sp['extra_admin_phone']) ?>" placeholder="09XXXXXXXXX" style="direction:ltr;max-width:200px">
				<p class="description" style="margin-top:4px">اگر در ۲۴ ساعت گذشته درخواستی ثبت شده باشد، در ساعت تعیین‌شده یک پیامک خلاصه ارسال می‌شود. اگر درخواستی نبود، پیامکی ارسال نمی‌شود.</p>
			</div>
			<div class="sp-field">
				<label>ساعت ارسال گزارش روزانه</label>
				<input type="time" name="spotplayer[extra_report_time]" value="<?= esc_attr(@$sp['extra_report_time'] ?: '08:00') ?>" style="direction:ltr;max-width:120px">
				<p class="description" style="margin-top:4px">بر اساس تایم‌زون سایت. برای دقت ساعت، Action Scheduler باید نصب باشد.</p>
			</div>

			<hr style="margin:20px 0 16px;border:none;border-top:1px solid #f0f0f1">
			<div class="sp-field">
				<label>ارسال پیامک آزمایشی</label>
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
					<input type="text" id="sp-sms-test-phone" placeholder="09XXXXXXXXX" style="width:160px;direction:ltr;max-width:160px">
					<button type="button" id="sp-sms-test-btn" class="button">ارسال آزمایشی</button>
					<span id="sp-sms-test-result" style="font-size:13px"></span>
				</div>
				<p class="description" style="margin-top:4px">با مقادیر فعلی فرم تست می‌کند — نیازی به ذخیره قبلی ندارد.</p>
			</div>

			<p style="margin-top:20px">
				<?php submit_button('ذخیره تنظیمات پیامک', 'primary', 'spot_sms_submit', false) ?>
			</p>
		</form>
	</div>

	<script>
	(function(){
		// ── شمارنده کاراکتر پیامک ──────────────────────────────────────────
		var ta     = document.getElementById('sp-sms-template');
		var smsCtr = document.getElementById('sp-sms-counter');
		if (ta && smsCtr) {
			function updateCounter() {
				var len = ta.value.length, parts = len === 0 ? 0 : Math.ceil(len / 70);
				smsCtr.textContent = len > 0 ? len + ' کاراکتر — حدود ' + parts + ' پارت پیامک فارسی (هر پارت ۷۰ کاراکتر)' : '';
				smsCtr.style.color = len > 140 ? '#9e2a2a' : '#646970';
			}
			ta.addEventListener('input', updateCounter);
			updateCounter();
		}

		// ── ارسال پیامک آزمایشی ────────────────────────────────────────────
		var smsBtn = document.getElementById('sp-sms-test-btn');
		var smsRes = document.getElementById('sp-sms-test-result');
		if (smsBtn) {
			smsBtn.addEventListener('click', function () {
				var phone = document.getElementById('sp-sms-test-phone').value.trim();
				if (!phone) { smsRes.textContent = 'شماره موبایل را وارد کنید.'; smsRes.style.color = '#c00'; return; }
				smsBtn.disabled = true;
				smsRes.textContent = '⏳ در حال ارسال…'; smsRes.style.color = '#646970';
				var fd = new FormData();
				fd.append('action',       'spot_test_sms');
				fd.append('nonce',        <?= json_encode(wp_create_nonce('spot_test_sms')) ?>);
				fd.append('phone',        phone);
				fd.append('sms_username', document.querySelector('[name="spotplayer[sms_username]"]').value);
				fd.append('sms_password', document.querySelector('[name="spotplayer[sms_password]"]').value);
				fd.append('sms_from',     document.querySelector('[name="spotplayer[sms_from]"]').value);
				fd.append('sms_from1',    document.querySelector('[name="spotplayer[sms_from1]"]').value);
				fd.append('sms_from2',    document.querySelector('[name="spotplayer[sms_from2]"]').value);
				fetch(<?= json_encode(admin_url('admin-ajax.php')) ?>, {method: 'POST', body: fd})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						smsRes.textContent = res.success ? '✅ ' + res.data : '❌ ' + (res.data || 'خطای نامشخص');
						smsRes.style.color  = res.success ? '#1a7a1a' : '#c00';
					})
					.catch(function () { smsRes.textContent = '❌ خطا در ارتباط با سرور.'; smsRes.style.color = '#c00'; })
					.finally(function () { smsBtn.disabled = false; });
			});
		}
	})();
	</script>
	</div><!-- /wrap settings card -->

	<?php
	// Reset the wrap for the report section below
	$status    = sanitize_key($_GET['sms_status'] ?? 'all');
	$date_from = sanitize_text_field($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
	$date_to   = sanitize_text_field($_GET['date_to']   ?? date('Y-m-d'));
	$search    = sanitize_text_field($_GET['search']    ?? '');
	$paged     = max(1, (int) ($_GET['paged'] ?? 1));
	$per_page  = 20;

	$meta_query = spot_sms_report_build_meta_query($status);

	if ($search && !is_numeric($search)) {
		$meta_query[] = ['key' => '_spot_sms_phone', 'value' => $search, 'compare' => 'LIKE'];
	}

	$base_args = [
		'type'         => 'shop_order',
		'orderby'      => 'ID',
		'order'        => 'DESC',
		'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
		'meta_query'   => $meta_query,
	];
	if ($search && is_numeric($search)) {
		$base_args['post__in'] = [(int) $search];
	}

	$orders = wc_get_orders(array_merge($base_args, ['limit' => $per_page, 'paged' => $paged]));
	$total  = count(wc_get_orders(array_merge($base_args, ['limit' => -1, 'return' => 'ids'])));

	$total_pages = max(1, (int) ceil($total / $per_page));
	$summary     = spot_sms_report_get_summary();
	$base_url    = admin_url('admin.php?page=spot-sms-report');

	$cards = [
		['key' => 'sent',     'icon' => '✅', 'label' => 'ارسال کامل',  'count' => $summary['sent'],     'desc' => 'هر دو پیامک ارسال شد'],
		['key' => 'pending',  'icon' => '🔄', 'label' => 'در جریان',     'count' => $summary['pending'],  'desc' => 'در صف یا در حال تلاش'],
		['key' => 'failed',   'icon' => '❌', 'label' => 'ناموفق',       'count' => $summary['failed'],   'desc' => 'خطا یا متوقف‌شده'],
		['key' => 'no_phone', 'icon' => '📵', 'label' => 'بدون پیامک',  'count' => $summary['no_phone'], 'desc' => 'شماره تلفن ثبت نشده'],
	];
	?>
	<div class="wrap" dir="rtl">
		<h1>گزارش پیامک لایسنس</h1>

		<!-- Summary Cards -->
		<div style="display:flex;gap:16px;margin:20px 0 24px;flex-wrap:wrap">
			<?php foreach ($cards as $card):
				$is_active = $status === $card['key'];
				$card_url  = add_query_arg(['sms_status' => $card['key'], 'date_from' => $date_from, 'date_to' => $date_to], $base_url);
			?>
			<a href="<?= esc_url($card_url) ?>" style="text-decoration:none;flex:1;min-width:160px">
				<div style="background:#fff;border:2px solid <?= $is_active ? '#2271b1' : '#ddd' ?>;border-radius:6px;padding:16px 20px;text-align:center">
					<div style="font-size:28px"><?= $card['icon'] ?></div>
					<div style="font-size:28px;font-weight:700;margin:16px 0 8px 0;color:#1d2327"><?= number_format($card['count']) ?></div>
					<div style="font-size:13px;font-weight:600;color:#1d2327"><?= esc_html($card['label']) ?></div>
					<div style="font-size:11px;color:#666;margin-top:2px"><?= esc_html($card['desc']) ?></div>
				</div>
			</a>
			<?php endforeach; ?>
		</div>

		<!-- Filters -->
		<form method="get" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
			<input type="hidden" name="page" value="spot-sms-report">
			<div>
				<label style="display:block;font-size:12px;margin-bottom:4px">وضعیت</label>
				<select name="sms_status">
					<?php foreach (['all' => 'همه', 'sent' => 'ارسال کامل', 'pending' => 'در جریان', 'failed' => 'ناموفق', 'no_phone' => 'بدون پیامک'] as $v => $l): ?>
					<option value="<?= esc_attr($v) ?>" <?= selected($status, $v, false) ?>><?= esc_html($l) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label style="display:block;font-size:12px;margin-bottom:4px">از تاریخ</label>
				<input type="date" name="date_from" value="<?= esc_attr($date_from) ?>" style="direction:ltr">
			</div>
			<div>
				<label style="display:block;font-size:12px;margin-bottom:4px">تا تاریخ</label>
				<input type="date" name="date_to" value="<?= esc_attr($date_to) ?>" style="direction:ltr">
			</div>
			<div>
				<label style="display:block;font-size:12px;margin-bottom:4px">جستجو (شماره / شناسه)</label>
				<input type="text" name="search" value="<?= esc_attr($search) ?>" placeholder="09... یا #1234" style="direction:ltr;width:160px">
			</div>
			<div style="display:flex;gap:6px">
				<button type="submit" class="button">اعمال</button>
				<?php if ($status !== 'all' || $search || $date_from !== date('Y-m-d', strtotime('-30 days')) || $date_to !== date('Y-m-d')): ?>
				<a href="<?= esc_url($base_url) ?>" class="button">پاک کردن</a>
				<?php endif; ?>
			</div>
		</form>

		<!-- Bulk resend bar (all-filtered) -->
		<?php if (in_array($status, ['failed', 'no_phone', 'all'], true) && $total > 0): ?>
		<div style="margin-bottom:10px">
			<button type="button" class="button button-primary" id="spot-bulk-resend-all"
				data-nonce="<?= esc_attr(wp_create_nonce('spot_bulk_resend_sms')) ?>"
				data-status="<?= esc_attr($status) ?>"
				data-date-from="<?= esc_attr($date_from) ?>"
				data-date-to="<?= esc_attr($date_to) ?>">
				ارسال مجدد برای همه <?= number_format($total) ?> سفارش فیلترشده
			</button>
			<span id="spot-bulk-all-msg" style="margin-right:10px;font-size:13px"></span>
		</div>
		<?php endif; ?>

		<!-- Selected rows bar -->
		<div id="spot-sel-bar" style="display:none;margin-bottom:10px;padding:9px 14px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px">
			<span id="spot-sel-count" style="font-size:13px"></span>
			<button type="button" class="button button-primary" id="spot-sel-resend" style="margin-right:10px"
				data-nonce="<?= esc_attr(wp_create_nonce('spot_bulk_resend_sms')) ?>">
				ارسال مجدد برای موارد انتخاب‌شده
			</button>
			<span id="spot-sel-msg" style="font-size:13px"></span>
		</div>

		<!-- Table -->
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;overflow:auto">
			<table class="wp-list-table widefat fixed striped" style="font-size:13px">
				<thead>
					<tr>
						<th style="width:36px"><input type="checkbox" id="spot-check-all"></th>
						<th style="width:80px">سفارش</th>
						<th>مشتری</th>
						<th style="width:120px">موبایل</th>
						<th style="width:130px">پیامک ۱</th>
						<th style="width:130px">پیامک ۲</th>
						<th style="width:120px">تاریخ ارسال</th>
						<th style="width:60px">تلاش‌ها</th>
						<th style="width:100px">عملیات</th>
					</tr>
				</thead>
				<tbody>
				<?php if (empty($orders)): ?>
					<tr><td colspan="9" style="text-align:center;padding:30px;color:#666">سفارشی یافت نشد.</td></tr>
				<?php else: ?>
				<?php foreach ($orders as $order):
					if (!($order instanceof WC_Order)) continue;

					$oid  = $order->get_id();
					$phone = (string) $order->get_meta('_spot_sms_phone');
					$m1s  = (string) $order->get_meta('_spot_sms_msg1_status');
					$m1a  = (int)    $order->get_meta('_spot_sms_msg1_attempts');
					$m2s  = (string) $order->get_meta('_spot_sms_msg2_status');
					$m2a  = (int)    $order->get_meta('_spot_sms_msg2_attempts');
					$m1t  = (int)    $order->get_meta('_spot_sms_msg1_sent_at');
					$m2t  = (int)    $order->get_meta('_spot_sms_msg2_sent_at');
					$sent_ts = $m2t ?: $m1t;

					$is_resendable = ($m1s !== 'pending' && $m2s !== 'pending')
						&& !($m1s === 'sent' && $m2s === 'sent');

					$badge = function(string $s, int $a): string {
						$map = [
							'sent'      => ['✅ ارسال شد',   '#276100', '#d1e7dd'],
							'failed'    => ['⚠️ ناموفق',     '#842029', '#f8d7da'],
							'abandoned' => ['🛑 متوقف شد',   '#842029', '#f8d7da'],
							'blocked'   => ['⏸ در انتظار',   '#664d03', '#fff3cd'],
							'pending'   => ['🔄 در جریان',   '#0a3622', '#d1e7dd'],
						];
						if (!isset($map[$s])) return '<span style="color:#999">—</span>';
						[$lbl, $fg, $bg] = $map[$s];
						if (in_array($s, ['failed', 'abandoned', 'pending'], true) && $a > 0) $lbl .= ' (' . $a . ')';
						return '<span style="font-size:11px;padding:2px 6px;border-radius:3px;color:' . $fg . ';background:' . $bg . '">' . esc_html($lbl) . '</span>';
					};
				?>
					<tr>
						<td><?php if ($is_resendable): ?><input type="checkbox" class="spot-row-chk" value="<?= esc_attr($oid) ?>"><?php endif; ?></td>
						<td><a href="<?= esc_url($order->get_edit_order_url()) ?>">#<?= esc_html($order->get_order_number()) ?></a></td>
						<td><?= esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) ?></td>
						<td style="direction:ltr;text-align:right"><?= $phone ? esc_html($phone) : '<span style="color:#999">—</span>' ?></td>
						<td><?= $badge($m1s, $m1a) ?></td>
						<td><?= $badge($m2s, $m2a) ?></td>
						<td><?= $sent_ts ? esc_html(wp_date('Y/m/d H:i', $sent_ts)) : '<span style="color:#999">—</span>' ?></td>
						<td style="text-align:center"><?= max($m1a, $m2a) ?: '<span style="color:#999">—</span>' ?></td>
						<td>
							<?php if ($is_resendable): ?>
							<button type="button" class="button button-small spot-resend-btn"
								data-order="<?= esc_attr($oid) ?>"
								data-nonce="<?= esc_attr(wp_create_nonce('spot_resend_sms_' . $oid)) ?>">ارسال مجدد</button>
							<span class="spot-resend-res" style="font-size:11px;margin-right:4px"></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- Pagination -->
		<?php if ($total_pages > 1):
			// Build the set of page numbers to show: first 2, window around current, last 2
			$show = [];
			for ($p = 1; $p <= $total_pages; $p++) {
				if ($p <= 2 || $p >= $total_pages - 1 || abs($p - $paged) <= 2) $show[$p] = true;
			}
			ksort($show);
			$page_link = function(int $p) use ($status, $date_from, $date_to, $search): string {
				return add_query_arg(array_filter([
					'page'       => 'spot-sms-report',
					'sms_status' => $status !== 'all' ? $status : false,
					'date_from'  => $date_from,
					'date_to'    => $date_to,
					'search'     => $search ?: false,
					'paged'      => $p > 1 ? $p : false,
				]), admin_url('admin.php'));
			};
			$btn = 'padding:4px 10px;border-radius:3px;text-decoration:none;border:1px solid';
		?>
		<div style="margin-top:16px;display:flex;gap:4px;align-items:center;flex-wrap:wrap">
			<?php
			$prev = null;
			foreach (array_keys($show) as $p) {
				if ($prev !== null && $p - $prev > 1):
				?><span style="padding:4px 6px;color:#999">…</span><?php
				endif;
				$active = $p === $paged;
				?><a href="<?= esc_url($page_link($p)) ?>"
					style="<?= $btn ?> <?= $active ? '#2271b1;background:#2271b1;color:#fff' : '#ddd;background:#fff;color:#1d2327' ?>"><?= $p ?></a><?php
				$prev = $p;
			}
			?>
			<span style="color:#666;font-size:12px;margin-right:8px"><?= number_format($total) ?> سفارش — صفحه <?= $paged ?> از <?= $total_pages ?></span>
		</div>
		<?php endif; ?>
	</div>

	<script>
	(function(){
		var ajax = <?= json_encode(admin_url('admin-ajax.php')) ?>;

		// ── Single resend ──────────────────────────────────────────────────────
		document.querySelectorAll('.spot-resend-btn').forEach(function(btn){
			btn.addEventListener('click', function(){
				var res = btn.nextElementSibling;
				btn.disabled = true; btn.textContent = '…';
				var fd = new FormData();
				fd.append('action', 'spot_resend_sms');
				fd.append('order_id', btn.dataset.order);
				fd.append('platform', 'woo');
				fd.append('nonce', btn.dataset.nonce);
				fetch(ajax, {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(r){
					if (r.success) { res.style.color='green'; res.textContent='در صف ارسال'; btn.remove(); }
					else { res.style.color='red'; res.textContent=(typeof r.data==='string')?r.data:'خطا'; btn.disabled=false; btn.textContent='ارسال مجدد'; }
				}).catch(function(){ res.style.color='red'; res.textContent='خطا'; btn.disabled=false; btn.textContent='ارسال مجدد'; });
			});
		});

		// ── Checkbox logic ─────────────────────────────────────────────────────
		var checkAll = document.getElementById('spot-check-all');
		var selBar   = document.getElementById('spot-sel-bar');
		var selCount = document.getElementById('spot-sel-count');

		function updateSelBar(){
			var n = document.querySelectorAll('.spot-row-chk:checked').length;
			selBar.style.display = n > 0 ? 'block' : 'none';
			if (n > 0) selCount.textContent = n + ' سفارش انتخاب شده';
		}

		if (checkAll) {
			checkAll.addEventListener('change', function(){
				document.querySelectorAll('.spot-row-chk').forEach(function(c){ c.checked = checkAll.checked; });
				updateSelBar();
			});
		}
		document.querySelectorAll('.spot-row-chk').forEach(function(c){ c.addEventListener('change', updateSelBar); });

		// ── Bulk resend helper ─────────────────────────────────────────────────
		function doBulkResend(payload, nonce, msgEl, btn, label){
			if (!confirm('ارسال مجدد پیامک برای ' + label + '؟')) return;
			btn.disabled = true;
			msgEl.style.color = '#666'; msgEl.textContent = 'در حال ارسال…';
			var fd = new FormData();
			fd.append('action', 'spot_bulk_resend_sms');
			fd.append('nonce', nonce);
			if (Array.isArray(payload)) {
				payload.forEach(function(id){ fd.append('order_ids[]', id); });
			} else {
				fd.append('filter_status',    payload.status);
				fd.append('filter_date_from', payload.dateFrom);
				fd.append('filter_date_to',   payload.dateTo);
			}
			fetch(ajax, {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(r){
				btn.disabled = false;
				if (r.success) { msgEl.style.color='green'; msgEl.textContent='در صف ارسال قرار گرفت: ' + (r.data.queued||0) + ' سفارش'; }
				else { msgEl.style.color='red'; msgEl.textContent=(typeof r.data==='string')?r.data:'خطا'; }
			}).catch(function(){ btn.disabled=false; msgEl.style.color='red'; msgEl.textContent='خطا در ارتباط'; });
		}

		// ── Bulk-all button ────────────────────────────────────────────────────
		var bulkAllBtn = document.getElementById('spot-bulk-resend-all');
		if (bulkAllBtn) {
			bulkAllBtn.addEventListener('click', function(){
				doBulkResend(
					{status: bulkAllBtn.dataset.status, dateFrom: bulkAllBtn.dataset.dateFrom, dateTo: bulkAllBtn.dataset.dateTo},
					bulkAllBtn.dataset.nonce,
					document.getElementById('spot-bulk-all-msg'),
					bulkAllBtn,
					bulkAllBtn.textContent.trim()
				);
			});
		}

		// ── Selected-rows resend ───────────────────────────────────────────────
		var selResend = document.getElementById('spot-sel-resend');
		if (selResend) {
			selResend.addEventListener('click', function(){
				var ids = Array.from(document.querySelectorAll('.spot-row-chk:checked')).map(function(c){return c.value;});
				if (!ids.length) return;
				doBulkResend(ids, selResend.dataset.nonce, document.getElementById('spot-sel-msg'), selResend, ids.length + ' سفارش');
			});
		}
	})();
	</script>
	<?php
}

// ── AJAX: bulk resend ─────────────────────────────────────────────────────────

add_action('wp_ajax_spot_bulk_resend_sms', 'spot_ajax_bulk_resend_sms');
function spot_ajax_bulk_resend_sms(): void {
	if (!current_user_can('manage_options')) wp_send_json_error('unauthorized');
	check_ajax_referer('spot_bulk_resend_sms', 'nonce');

	$queued = 0;

	// Mode A: explicit list of order IDs
	if (!empty($_POST['order_ids'])) {
		$ids = array_filter(array_map('intval', (array) $_POST['order_ids']));
		foreach ($ids as $oid) {
			$order = wc_get_order($oid);
			if (!($order instanceof WC_Order)) continue;

			$phone = (string) $order->get_meta('_spot_sms_phone');
			if (!$phone) $phone = spot_sms_normalize_phone($order->get_billing_phone());
			if (!$phone) continue;

			spot_sms_cancel_pending_for_order($oid);
			spot_sms_init_order_meta($order, $phone);
			spot_sms_schedule_msg1($oid, 'woo', 1, 0);
			$queued++;
		}
		delete_transient('spot_sms_summary');
		wp_send_json_success(['queued' => $queued]);
		return;
	}

	// Mode B: filter-based (bulk-all button)
	$filter_status    = sanitize_key($_POST['filter_status']    ?? 'all');
	$filter_date_from = sanitize_text_field($_POST['filter_date_from'] ?? date('Y-m-d', strtotime('-30 days')));
	$filter_date_to   = sanitize_text_field($_POST['filter_date_to']   ?? date('Y-m-d'));

	$meta_query = spot_sms_report_build_meta_query($filter_status);

	$all_ids = wc_get_orders([
		'type'         => 'shop_order',
		'limit'        => -1,
		'return'       => 'ids',
		'date_created' => $filter_date_from . '...' . $filter_date_to . ' 23:59:59',
		'meta_query'   => $meta_query,
	]);

	foreach ($all_ids as $oid) {
		$order = wc_get_order((int) $oid);
		if (!($order instanceof WC_Order)) continue;

		// Skip if already fully sent or currently pending
		$m1s = (string) $order->get_meta('_spot_sms_msg1_status');
		$m2s = (string) $order->get_meta('_spot_sms_msg2_status');
		if ($m1s === 'pending' || $m2s === 'pending') continue;
		if ($m1s === 'sent' && $m2s === 'sent') continue;

		$phone = (string) $order->get_meta('_spot_sms_phone');
		if (!$phone) $phone = spot_sms_normalize_phone($order->get_billing_phone());
		if (!$phone) continue;

		spot_sms_cancel_pending_for_order((int) $oid);
		spot_sms_init_order_meta($order, $phone);
		spot_sms_schedule_msg1((int) $oid, 'woo', 1, 0);
		$queued++;
	}

	delete_transient('spot_sms_summary');
	wp_send_json_success(['queued' => $queued]);
}

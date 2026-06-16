<?php
if (!defined('ABSPATH')) exit;

function spot_plugin_action_links($links, $file) {
	if (strpos($file, 'spotplayer') !== false) array_unshift($links,
		'<a href="' . admin_url('admin.php?page=spotplayer') . '">تنظیمات</a>',
		'<a target="_blank" href="https://spotplayer.ir/help/api/wordpress">راهنما</a>');
	return $links;
}
add_filter('plugin_action_links', 'spot_plugin_action_links', 10, 2);

function spot_admin_menu() {
	register_setting('spotplayer', 'spotplayer');
	add_menu_page('تنظیمات اسپات پلیر', 'اسپات پلیر', 'manage_options', 'spotplayer', 'spot_admin_page', SPOTPLAYER_URL . 'assets/svg/icon.svg');
}
add_action('admin_menu', 'spot_admin_menu');

function spot_admin_page() {
	if (!current_user_can('manage_options')) return;

	$sp        = get_option('spotplayer');
	$use_async = function_exists('as_schedule_single_action');
	$force_tab = null;

	// ── پردازش فرم دوره‌ها ────────────────────────────────────────────────────
	$courses_notice = '';
	if (isset($_POST['spot_save_courses']) && check_admin_referer('spot_save_courses', 'spot_courses_nonce')) {
		$courses = [];
		foreach ((array)($_POST['spot_courses'] ?? []) as $row) {
			$id   = sanitize_text_field($row['id']   ?? '');
			$name = sanitize_text_field($row['name'] ?? '');
			if (!preg_match('/^[0-9a-f]{24}$/i', $id) || $name === '') continue;
			$installments = [];
			foreach ((array)($row['installments'] ?? []) as $inst) {
				$from   = max(0, intval($inst['from'] ?? 0));
				$to_raw = trim(sanitize_text_field($inst['to'] ?? ''));
				$days   = max(0, intval($inst['days'] ?? 0));
				if ($to_raw !== '' && !ctype_digit($to_raw)) continue;
				$installments[] = ['from' => $from, 'to' => ($to_raw === '' ? '' : intval($to_raw)), 'days' => $days];
			}
			$courses[] = ['id' => $id, 'name' => $name, 'installments' => $installments];
		}
		update_option('spot_courses', $courses);
		$courses_notice = '<div class="notice notice-success"><p>✅ لیست دوره‌های اسپات پلیر ذخیره شد.</p></div>';
		$force_tab = 'courses';
	}

	// ── پردازش فرم ایجاد سفارش دسته‌ای ──────────────────────────────────────
	$bulk_create_notice = '';
	if (isset($_POST['bulk_product_id']) && isset($_FILES['bulk_excel']) && check_admin_referer('spotplayer_bulk_license', 'spotplayer_bulk_license_nonce')) {
		$product_id = intval($_POST['bulk_product_id']);
		$limit_raw  = sanitize_text_field($_POST['bulk_chapters'] ?? '');
		ob_start();
		if (empty($_FILES['bulk_excel']['tmp_name']) || !is_uploaded_file($_FILES['bulk_excel']['tmp_name'])) {
			echo '<div class="notice notice-error"><p>فایل اکسل ارسال نشد.</p></div>';
		} elseif ($use_async) {
			$res = spot_bulk_schedule_create_orders($product_id, $limit_raw, $_FILES['bulk_excel']['tmp_name']);
			if ($res['queued'] > 0)
				echo '<div class="notice notice-success"><p>✅ تعداد ' . $res['queued'] . ' ردیف در صف پردازش قرار گرفت. سفارشات و لایسنس‌ها به زودی در پس‌زمینه ساخته می‌شوند.</p></div>';
			if (!empty($res['errors'])) {
				echo '<div class="notice notice-error"><p>❌ خطاها:</p><ul>';
				foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
				echo '</ul></div>';
			}
		} else {
			$res = spot_bulk_create_orders_3col($product_id, $limit_raw, $_FILES['bulk_excel']['tmp_name']);
			if (!empty($res['success'])) {
				echo '<div class="notice notice-success"><p>✅ تعداد ' . count($res['success']) . ' سفارش با موفقیت تکمیل شد.</p>';
				echo '<div style="max-height:200px;overflow-y:auto"><ul>';
				foreach ($res['success'] as $s)
					echo '<li>' . esc_html($s['name']) . ' (' . esc_html($s['phone']) . ') | سفارش #' . $s['order_id'] . ' | لایسنس: ' . $s['license_id'] . '</li>';
				echo '</ul></div></div>';
			}
			if (!empty($res['errors'])) {
				echo '<div class="notice notice-error"><p>❌ خطاها:</p><ul>';
				foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
				echo '</ul></div>';
			}
		}
		$bulk_create_notice = ob_get_clean();
		$force_tab = 'bulk';
	}

	// ── پردازش فرم غیرفعال‌سازی دسته‌ای ─────────────────────────────────────
	$bulk_disable_notice = '';
	if (isset($_POST['bulk_disable_submit']) && isset($_FILES['bulk_disable_excel']) && check_admin_referer('spotplayer_bulk_disable', 'spotplayer_bulk_disable_nonce')) {
		ob_start();
		if (empty($_FILES['bulk_disable_excel']['tmp_name']) || !is_uploaded_file($_FILES['bulk_disable_excel']['tmp_name'])) {
			echo '<div class="notice notice-error"><p>فایل اکسل/CSV لایسنس‌ها به درستی ارسال نشده است.</p></div>';
		} elseif ($use_async) {
			$res = spot_bulk_schedule_disable_licenses($_FILES['bulk_disable_excel']['tmp_name']);
			if ($res['queued'] > 0)
				echo '<div class="notice notice-success"><p>✅ تعداد ' . $res['queued'] . ' لایسنس در صف غیرفعال‌سازی قرار گرفت.</p></div>';
			if (!empty($res['errors'])) {
				echo '<div class="notice notice-error"><p>❌ خطاها:</p><ul>';
				foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
				echo '</ul></div>';
			}
		} else {
			try {
				$res = spot_bulk_disable_licenses_from_csv($_FILES['bulk_disable_excel']['tmp_name']);
				if (!empty($res['success'])) {
					echo '<div class="notice notice-success"><p>تعداد ' . count($res['success']) . ' لایسنس با موفقیت غیرفعال شد.</p><ul>';
					foreach ($res['success'] as $s)
						echo '<li>ردیف ' . intval($s['row']) . ' — شناسه: ' . esc_html($s['id']) . '</li>';
					echo '</ul></div>';
				}
				if (!empty($res['errors'])) {
					echo '<div class="notice notice-error"><p>برخی ردیف‌ها با خطا مواجه شدند:</p><ul>';
					foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
					echo '</ul></div>';
				}
			} catch (Throwable $e) {
				echo '<div class="notice notice-error"><p>خطای غیرمنتظره: ' . esc_html($e->getMessage()) . '</p></div>';
			}
		}
		$bulk_disable_notice = ob_get_clean();
		$force_tab = 'bulk';
	}

	// ── پردازش فرم دسترسی اضافه ─────────────────────────────────────────────
	$extra_notice = '';
	if (isset($_POST['spot_save_extra']) && check_admin_referer('spot_save_extra', 'spot_extra_nonce')) {
		$stages = [];
		foreach ((array)($_POST['spot_extra_stages'] ?? []) as $row) {
			$type  = sanitize_key($row['type'] ?? '');
			$value = floatval($row['value'] ?? 0);
			if (!in_array($type, ['fixed', 'percent'], true) || $value <= 0) continue;
			$stages[] = ['type' => $type, 'value' => $value];
		}
		$end_mode = sanitize_key($_POST['spot_extra_end_mode'] ?? 'max');
		if (!in_array($end_mode, ['max', 'repeat_last'], true)) $end_mode = 'max';

		$sp_opt = get_option('spotplayer', []);
		$sp_opt['extra_stages']   = $stages;
		$sp_opt['extra_end_mode'] = $end_mode;
		update_option('spotplayer', $sp_opt);
		$sp = $sp_opt;

		$extra_notice = '<div class="notice notice-success"><p>✅ تنظیمات دسترسی اضافه ذخیره شد.</p></div>';
		$force_tab = 'extra';
	}

	$saved_courses = get_option('spot_courses', []);
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
	.sp-check:last-child { margin-bottom:0; }
	.sp-check input[type=checkbox] { margin-top:3px; flex-shrink:0; }
	.sp-check-label { font-weight:600; display:block; margin-bottom:2px; cursor:pointer; }
	.sp-check-desc { color:#646970; font-size:12px; }
	.sp-warn { color:#9e2a2a; font-size:12px; margin-top:3px; }
	.sp-courses-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
	.sp-courses-table th { text-align:right; padding:6px 8px; background:#f6f7f7; border-bottom:1px solid #dcdcde; font-size:12px; font-weight:600; }
	.sp-courses-table td { padding:5px 8px; border-bottom:1px solid #f0f0f1; vertical-align:middle; }
	.sp-courses-table td input[type=text] { width:100%; margin:0; }
	.sp-courses-table .sp-id-col input { font-family:monospace; direction:ltr; font-size:12px; }
	.sp-courses-table .spot-remove-row { color:#c00; border-color:#c00; padding:2px 8px; min-height:0; height:26px; line-height:24px; }
	.sp-td-actions { display:flex; gap:4px; align-items:center; justify-content:flex-end; }
	.sp-inst-toggle { color:#2271b1; border-color:#2271b1; padding:2px 8px; min-height:0; height:26px; line-height:24px; font-size:11px; white-space:nowrap; }
	.sp-inst-toggle.open { background:#2271b1; color:#fff; }
	.sp-inst-wrap { background:#f6f7f7; border-top:2px solid #2271b1; padding:10px 12px; }
	.sp-inst-table { width:100%; border-collapse:collapse; margin-bottom:8px; }
	.sp-inst-table th { text-align:right; padding:4px 8px; background:#ececec; font-size:11px; font-weight:600; border-bottom:1px solid #dcdcde; }
	.sp-inst-table td { padding:3px 6px; border-bottom:1px solid #f0f0f1; vertical-align:middle; }
	.sp-inst-table td input { width:100%; margin:0; }
	.sp-inst-remove { color:#c00; border-color:#c00; padding:1px 6px; min-height:0; height:22px; line-height:20px; font-size:11px; }
	.sp-sms-counter { font-size:12px; color:#646970; margin-top:4px; }
	.sp-sms-template { direction:rtl !important; font-family:inherit !important; }
	/* ── Stages table (extra access) ── */
	.sp-stages-table { width:100%; border-collapse:collapse; margin-bottom:12px; max-width:560px; }
	.sp-stages-table th { text-align:right; padding:6px 8px; background:#f6f7f7; border-bottom:1px solid #dcdcde; font-size:12px; font-weight:600; }
	.sp-stages-table td { padding:5px 8px; border-bottom:1px solid #f0f0f1; vertical-align:middle; }
	.sp-stages-table td select, .sp-stages-table td input[type=number] { width:100%; margin:0; }
	.sp-stage-remove { color:#c00; border-color:#c00; padding:2px 8px; min-height:0; height:26px; line-height:24px; }
	.sp-end-mode-opt { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-radius:3px; background:#f9f9f9; border:1px solid #f0f0f1; margin-bottom:8px; cursor:pointer; }
	.sp-end-mode-opt:last-child { margin-bottom:0; }
	.sp-end-mode-opt input[type=radio] { margin-top:3px; flex-shrink:0; }
	/* ── Tabs ── */
	.sp-tabs-nav { display:flex; border-bottom:2px solid #c3c4c7; margin-bottom:24px; gap:0; }
	.sp-tab-btn { padding:9px 20px; border:1px solid transparent; border-bottom:none; background:none; cursor:pointer; font-size:13px; border-radius:4px 4px 0 0; margin-bottom:-2px; color:#646970; font-family:inherit; }
	.sp-tab-btn:hover { background:#f6f7f7; color:#1d2327; }
	.sp-tab-btn.sp-tab-active { background:#fff; border-color:#c3c4c7; border-bottom-color:#fff; color:#1d2327; font-weight:600; }
	.sp-tab-panel { display:none; }
	.sp-tab-panel.sp-tab-active { display:block; }
	#sp-settings .sp-page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
	#sp-settings .sp-page-header h1 { margin:0; padding:0; line-height:1.3; }
	</style>

	<div id="sp-settings" class="wrap">

	<?php if (isset($_GET['settings-updated'])) { ?>
		<div class="notice notice-success is-dismissible"><p>✅ تنظیمات اسپات پلیر ذخیره شد.</p></div>
	<?php } ?>

	<div class="sp-page-header">
		<h1>تنظیمات اسپات پلیر <a href="https://spotplayer.ir/help/api/wordpress" target="_blank" style="font-size:13px;font-weight:normal;vertical-align:middle;margin-right:8px">(راهنما ↗)</a></h1>
		<button type="submit" form="sp-main-form" class="button button-primary" style="height:34px;line-height:32px">ذخیره تنظیمات</button>
	</div>

	<nav class="sp-tabs-nav">
		<button type="button" class="sp-tab-btn" data-tab="general">⚙️ تنظیمات اصلی</button>
		<button type="button" class="sp-tab-btn" data-tab="sms">📲 پیامک</button>
		<button type="button" class="sp-tab-btn" data-tab="courses">📚 دوره‌ها</button>
		<button type="button" class="sp-tab-btn" data-tab="bulk">📦 عملیات دسته‌ای</button>
		<button type="button" class="sp-tab-btn" data-tab="extra">💳 دسترسی اضافه</button>
	</nav>

	<!-- ═══════════ فرم اصلی: تب‌های ۱ و ۲ ═══════════ -->
	<form id="sp-main-form" action="options.php" method="post">
	<?php settings_fields('spotplayer') ?>

	<!-- ── تب ۱: تنظیمات اصلی ── -->
	<div class="sp-tab-panel" id="sp-tab-general">

		<div class="sp-card">
			<h2>🔑 اتصال به اسپات پلیر</h2>
			<div class="sp-field">
				<label>کلید API</label>
				<input type="text" name="spotplayer[api]" id="spot-api-key" value="<?= esc_attr(@$sp['api']) ?>" required pattern="^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$">
				<p class="description" style="margin-top:4px">کلید API از داشبورد اسپات پلیر. <span class="sp-warn" style="display:inline">تغییر رمز عبور اسپات پلیر، کلید API را هم تغییر می‌دهد.</span></p>
				<p style="margin-top:8px">
					<button type="button" id="spot-test-api-btn" class="button">تست اتصال</button>
					<span id="spot-test-api-result" style="margin-right:10px;font-size:13px"></span>
				</p>
			</div>
			<div class="sp-field">
				<label>دامنه ریبرندینگ <span style="font-weight:normal;color:#646970">(اختیاری)</span></label>
				<input type="text" name="spotplayer[domain]" value="<?= esc_attr(@$sp['domain']) ?>" pattern="^app[0-9]?(\.[a-z0-9\-]+){2,}$" placeholder="app.example.com">
				<p class="description" style="margin-top:4px">فقط در صورت فعال بودن سرویس ریبرندینگ وارد کنید.</p>
			</div>
		</div>

		<div class="sp-card">
			<h2>⚙️ ساخت لایسنس</h2>
			<div class="sp-check">
				<input type="checkbox" id="sp-time" name="spotplayer[time]" value="<?= $sp['time'] ?: time() ?>" <?= $sp['time'] ? 'checked' : '' ?>>
				<div>
					<label class="sp-check-label" for="sp-time">عدم ایجاد لایسنس برای سفارشات قدیمی</label>
					<div class="sp-check-desc">سفارشاتی که قبل از فعال‌کردن این گزینه ثبت شده‌اند لایسنس دریافت نمی‌کنند.</div>
				</div>
			</div>
			<div class="sp-check">
				<input type="checkbox" id="sp-completed" name="spotplayer[completed]" value="1" <?= $sp['completed'] ? 'checked' : '' ?>>
				<div>
					<label class="sp-check-label" for="sp-completed">ایجاد لایسنس فقط پس از تکمیل دستی سفارش</label>
					<div class="sp-check-desc">سفارش پس از پرداخت به وضعیت «در حال انجام» می‌رود و تا تأیید ادمین لایسنس ساخته نمی‌شود.</div>
					<div class="sp-warn">برای محصولات دانلودی، ووکامرس سفارش را خودکار تکمیل می‌کند.</div>
				</div>
			</div>
		</div>

		<div class="sp-card">
			<h2>🎨 نمایش</h2>
			<div class="sp-field">
				<label>رنگ اصلی</label>
				<input type="color" name="spotplayer[color]" value="<?= esc_attr(@$sp['color'] ?: '#6611DD') ?>">
			</div>
			<div class="sp-check">
				<input type="checkbox" id="sp-web" name="spotplayer[web]" value="1" <?= $sp['web'] ? 'checked' : '' ?>
				       onchange="const w=document.getElementById('sp-webonly');(w.disabled=!this.checked)?w.checked=false:null;w.onchange(null)">
				<div>
					<label class="sp-check-label" for="sp-web">نمایش پلیر وب در سایت</label>
					<div class="sp-check-desc">پلیر آنلاین اسپات پلیر را در صفحه دوره‌های کاربر نمایش می‌دهد.</div>
				</div>
			</div>
			<div class="sp-check">
				<input type="checkbox" id="sp-webonly" name="spotplayer[webonly]" value="1" <?= $sp['webonly'] ? 'checked' : '' ?> <?= $sp['web'] ? '' : 'disabled' ?>
				       onchange="const d=document.getElementById('sp-download');(d.disabled=this.checked)?d.checked=false:null">
				<div>
					<label class="sp-check-label" for="sp-webonly">فقط نمایش پلیر وب</label>
					<div class="sp-check-desc">نسخه‌های نیتیو و لیست دانلود پنهان می‌شوند.</div>
				</div>
			</div>
			<div class="sp-check">
				<input type="checkbox" id="sp-download" name="spotplayer[download]" value="1" <?= $sp['download'] ? 'checked' : '' ?> <?= $sp['webonly'] ? 'disabled' : '' ?>>
				<div>
					<label class="sp-check-label" for="sp-download">نمایش لیست دانلود</label>
					<div class="sp-check-desc">پلیر فایل‌ها را خودکار دانلود می‌کند؛ این گزینه پیش‌فرضاً غیرفعال است.</div>
				</div>
			</div>
			<?php if (spot_woo_or_edd() == 1) { ?>
			<div class="sp-check">
				<input type="checkbox" id="sp-wccrs" name="spotplayer[wccrs]" value="1" <?= $sp['wccrs'] ? 'checked' : '' ?>>
				<div>
					<label class="sp-check-label" for="sp-wccrs">نمایش «لایسنس‌های من» در منوی حساب ووکامرس</label>
					<div class="sp-check-desc">لینک به صفحه شورت‌کد دوره‌ها در منوی My Account اضافه می‌شود.</div>
				</div>
			</div>
			<?php } if (class_exists('Studiare_Core')) { ?>
			<div class="sp-check">
				<input type="checkbox" id="sp-wcspc" name="spotplayer[wcspc]" value="1" <?= $sp['wcspc'] ? 'checked' : '' ?>>
				<div>
					<label class="sp-check-label" for="sp-wcspc">حذف لینک دوره‌های قالب استادیار از منوی ووکامرس</label>
				</div>
			</div>
			<?php } ?>
		</div>

	</div><!-- /sp-tab-general -->

	<!-- ── تب ۲: پیامک ── -->
	<div class="sp-tab-panel" id="sp-tab-sms">

		<div class="sp-card">
			<h2>📲 ارسال پیامک</h2>
			<div class="sp-check">
				<input type="checkbox" id="sp-sms-enabled" name="spotplayer[sms_enabled]" value="1" <?= !empty($sp['sms_enabled']) ? 'checked' : '' ?>>
				<div>
					<label class="sp-check-label" for="sp-sms-enabled">ارسال خودکار پیامک پس از صدور لایسنس</label>
					<div class="sp-check-desc">پس از ایجاد موفق لایسنس، دو پیامک به شماره مشتری ارسال می‌شود: ابتدا متن توضیحات، سپس کد لایسنس به تنهایی.</div>
				</div>
			</div>
			<div class="sp-field" style="margin-top:12px">
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
				<label>متن پیامک توضیحات دسترسی اضافه <span style="font-weight:normal;color:#646970">(مرحله ۱ — پس از تأیید ادمین)</span></label>
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
		</div>

	</div><!-- /sp-tab-sms -->

	</form><!-- /sp-main-form -->

	<!-- ═══════════ تب ۳: دوره‌ها ═══════════ -->
	<div class="sp-tab-panel" id="sp-tab-courses">

		<?= $courses_notice ?>

		<div style="background:#f0f6fc;border:1px solid #c3defa;border-radius:4px;padding:10px 16px;margin-bottom:20px;font-size:13px">
			شورت‌کد <code>[spotplayer_courses]</code> — دوره‌های لایسنس‌دار کاربر را با امکان مشاهده آنلاین و دریافت لایسنس نمایش می‌دهد.
		</div>

		<div class="sp-card">
			<h2>📚 دوره‌های اسپات پلیر</h2>
			<p style="margin-top:0;color:#646970;font-size:13px">دوره‌هایی که می‌خواهید به محصولات متصل کنید را اینجا تعریف کنید. پس از ذخیره، این دوره‌ها در صفحه ویرایش محصول به صورت dropdown قابل انتخاب خواهند بود.</p>
			<form method="post">
				<?php wp_nonce_field('spot_save_courses', 'spot_courses_nonce') ?>
				<input type="hidden" name="spot_save_courses" value="1">
				<table class="sp-courses-table">
					<thead><tr>
						<th style="width:40%">نام دوره</th>
						<th class="sp-id-col" style="width:42%">شناسه دوره (۲۴ کاراکتر هگز از پنل اسپات)</th>
						<th style="width:18%"></th>
					</tr></thead>
					<tbody id="spot-courses-tbody">
					<?php foreach ($saved_courses as $idx => $course) {
						$insts = $course['installments'] ?? [];
						$inst_count = count($insts); ?>
						<tr class="sp-course-row" data-idx="<?= $idx ?>">
							<td><input type="text" name="spot_courses[<?= $idx ?>][name]" value="<?= esc_attr($course['name']) ?>" placeholder="مثلاً: دوره پایتون" required></td>
							<td class="sp-id-col"><input type="text" name="spot_courses[<?= $idx ?>][id]" value="<?= esc_attr($course['id']) ?>" placeholder="5d2ee35bcddc092a304ae5eb" pattern="[0-9a-fA-F]{24}" required></td>
							<td>
								<div class="sp-td-actions">
									<button type="button" class="button sp-inst-toggle<?= $inst_count ? ' open' : '' ?>">📋 اقساط<?= $inst_count ? ' (' . $inst_count . ')' : '' ?></button>
									<button type="button" class="button spot-remove-row">✕</button>
								</div>
							</td>
						</tr>
						<tr class="sp-inst-wrap-row"<?= $inst_count ? '' : ' style="display:none"' ?>>
							<td colspan="3" style="padding:0 0 6px">
								<div class="sp-inst-wrap">
									<table class="sp-inst-table">
										<thead><tr>
											<th style="width:22%">سرفصل از</th>
											<th style="width:28%">تا (خالی = دسترسی کامل)</th>
											<th style="width:35%">روز تا سررسید قسط بعدی</th>
											<th style="width:15%"></th>
										</tr></thead>
										<tbody class="sp-inst-tbody">
										<?php foreach ($insts as $iidx => $inst) { ?>
											<tr>
												<td><input type="number" name="spot_courses[<?= $idx ?>][installments][<?= $iidx ?>][from]" value="<?= esc_attr($inst['from']) ?>" min="0" placeholder="0"></td>
												<td><input type="text" name="spot_courses[<?= $idx ?>][installments][<?= $iidx ?>][to]" value="<?= esc_attr($inst['to']) ?>" placeholder="مثلاً 4 — یا خالی"></td>
												<td><input type="number" name="spot_courses[<?= $idx ?>][installments][<?= $iidx ?>][days]" value="<?= esc_attr($inst['days'] ?? '') ?>" min="0" placeholder="مثلاً 30 — آخرین قسط خالی"></td>
												<td><button type="button" class="button sp-inst-remove">✕</button></td>
											</tr>
										<?php } ?>
										</tbody>
									</table>
									<button type="button" class="button sp-add-inst">+ افزودن قسط</button>
								</div>
							</td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
				<button type="button" id="spot-add-course" class="button">+ افزودن دوره</button>
				&nbsp;&nbsp;
				<?php submit_button('ذخیره لیست دوره‌ها', 'primary', 'spot_courses_submit', false) ?>
			</form>
		</div>

	</div><!-- /sp-tab-courses -->

	<!-- ═══════════ تب ۴: عملیات دسته‌ای ═══════════ -->
	<div class="sp-tab-panel" id="sp-tab-bulk">

		<?= $bulk_create_notice ?>
		<?= $bulk_disable_notice ?>

		<?php if ($use_async) {
			$qs           = spot_bulk_queue_status();
			$as_url       = admin_url('admin.php?page=action-scheduler&s=spot_bulk&status=pending');
			$active_count = $qs['create']['pending'] + $qs['create']['running'] + $qs['disable']['pending'] + $qs['disable']['running'];
			$has_any      = $active_count + $qs['create']['failed'] + $qs['create']['complete'] + $qs['disable']['failed'] + $qs['disable']['complete'] > 0;
			$queue_nonce  = wp_create_nonce('spot_queue_status'); ?>
			<div class="sp-card">
				<h2>⏳ وضعیت صف پردازش ناهمزمان</h2>
				<table id="spot-qs-table" class="widefat" style="margin-bottom:12px">
					<thead><tr><th>عملیات</th><th>در انتظار</th><th>در حال اجرا</th><th>خطا</th><th>تکمیل</th></tr></thead>
					<tbody>
						<tr>
							<td>ایجاد سفارش</td>
							<td id="spot-qs-create-pending"><?= $qs['create']['pending'] ?></td>
							<td id="spot-qs-create-running"><?= $qs['create']['running'] ?></td>
							<td id="spot-qs-create-failed" style="color:<?= $qs['create']['failed'] ? '#900' : 'inherit' ?>"><?= $qs['create']['failed'] ?></td>
							<td id="spot-qs-create-complete"><?= $qs['create']['complete'] ?></td>
						</tr>
						<tr>
							<td>غیرفعال‌سازی لایسنس</td>
							<td id="spot-qs-disable-pending"><?= $qs['disable']['pending'] ?></td>
							<td id="spot-qs-disable-running"><?= $qs['disable']['running'] ?></td>
							<td id="spot-qs-disable-failed" style="color:<?= $qs['disable']['failed'] ? '#900' : 'inherit' ?>"><?= $qs['disable']['failed'] ?></td>
							<td id="spot-qs-disable-complete"><?= $qs['disable']['complete'] ?></td>
						</tr>
					</tbody>
				</table>
				<p id="spot-qs-status" style="margin:0">
					<?php if ($active_count > 0) { ?>
						<span>⏳ در حال پردازش — صفحه به‌روز می‌شود…</span>
					<?php } elseif ($has_any) { ?>
						<a href="<?= esc_url($as_url) ?>" target="_blank">مشاهده جزئیات در Action Scheduler ↗</a>
					<?php } ?>
				</p>
			</div>
		<?php } ?>

		<div class="sp-card">
			<h2>📋 ایجاد سفارش دسته‌ای (از طریق محصول ووکامرس)</h2>
			<?php if (!$use_async) { ?>
			<div style="background:#fff8e5;border-left:4px solid #ffb900;padding:10px 15px;margin-bottom:16px;border-radius:3px">
				<strong>⚠️ نکته مهم:</strong> برای جلوگیری از خطای Time-out سرور، پیشنهاد می‌شود فایل‌های حجیم را به دسته‌های ۵۰ تایی تقسیم کنید.
			</div>
			<?php } ?>
			<p style="margin-top:0;color:#646970;font-size:13px">فایل CSV باید دارای ۳ ستون با هدرهای زیر باشد: نام (name)، نام خانوادگی (family)، موبایل (phone)</p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field('spotplayer_bulk_license', 'spotplayer_bulk_license_nonce'); ?>
				<div class="sp-field">
					<label for="bulk_product_id">انتخاب محصول ووکامرس</label>
					<select name="bulk_product_id" id="bulk_product_id" style="width:100%;max-width:460px" required>
						<option value="">-- محصول یا متغیر را انتخاب کنید --</option>
						<?php
						$all_products = wc_get_products(['status' => 'publish', 'limit' => -1]);
						foreach ($all_products as $prod):
							if ($prod->is_type('variable')) {
								foreach ($prod->get_children() as $child_id) {
									$variation = wc_get_product($child_id);
									if (!$variation) continue;
									if ($variation->get_meta('_spotplayer_course')) { ?>
										<option value="<?= $child_id ?>"><?= $variation->get_formatted_name() ?> (ID: <?= $child_id ?>)</option>
									<?php }
								}
							} else {
								if ($prod->get_meta('_spotplayer_course')) { ?>
									<option value="<?= $prod->get_id() ?>"><?= $prod->get_name() ?> (ID: <?= $prod->get_id() ?>)</option>
								<?php }
							}
						endforeach; ?>
					</select>
				</div>
				<div class="sp-field">
					<label for="bulk_chapters">محدودیت سرفصل (Limit)</label>
					<input type="text" name="bulk_chapters" id="bulk_chapters" style="direction:ltr" placeholder="مثال: 0- یا 1,4-6">
					<p class="description" style="margin-top:4px">اختیاری. این محدودیت روی لایسنس‌ها اعمال خواهد شد.</p>
				</div>
				<div class="sp-field">
					<label for="bulk_excel">فایل اکسل (CSV)</label>
					<input type="file" name="bulk_excel" id="bulk_excel" accept=".csv" required>
				</div>
				<?php submit_button('ایجاد سفارشات و لایسنس‌ها'); ?>
			</form>
		</div>

		<div class="sp-card">
			<h2>🗂 غیرفعال‌سازی دسته‌ای لایسنس‌ها از CSV</h2>
			<p style="margin-top:0;color:#646970;font-size:13px">شناسه هر لایسنس باید در ستونی با نام <code>id</code> قرار گیرد (۲۴ کاراکتر هگز).</p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field('spotplayer_bulk_disable', 'spotplayer_bulk_disable_nonce'); ?>
				<div class="sp-field">
					<label for="bulk_disable_excel">فایل اکسل (CSV) لایسنس‌های موجود</label>
					<input type="file" name="bulk_disable_excel" id="bulk_disable_excel" accept=".csv" required>
					<p class="description" style="margin-top:4px">فایل CSV که در ردیف اول آن ستونی با نام <code>id</code> و در ردیف‌های بعدی شناسه لایسنس‌ها قرار دارد.</p>
				</div>
				<input type="hidden" name="bulk_disable_submit" value="1">
				<?php submit_button('غیرفعال‌سازی لایسنس‌ها'); ?>
			</form>
		</div>

	</div><!-- /sp-tab-bulk -->

	<!-- ═══════════ تب ۵: دسترسی اضافه ═══════════ -->
	<div class="sp-tab-panel" id="sp-tab-extra">

		<?= $extra_notice ?>

		<div class="sp-card">
			<h2>💳 هزینه دسترسی اضافه لایسنس</h2>
			<p style="margin-top:0;color:#646970;font-size:13px">وقتی کاربری برای اولین بار درخواست دسترسی اضافه می‌دهد هزینه‌ی پله اول دریافت می‌شود؛ دفعه دوم پله دوم، و به همین ترتیب.</p>
			<form method="post">
				<?php wp_nonce_field('spot_save_extra', 'spot_extra_nonce') ?>
				<input type="hidden" name="spot_save_extra" value="1">

				<table class="sp-stages-table">
					<thead><tr>
						<th style="width:12%">پله</th>
						<th style="width:40%">نوع</th>
						<th style="width:33%">مقدار</th>
						<th style="width:15%"></th>
					</tr></thead>
					<tbody id="sp-stages-tbody">
					<?php
					$saved_stages = $sp['extra_stages'] ?? [];
					foreach ($saved_stages as $si => $stage) { ?>
						<tr>
							<td style="text-align:center;font-weight:600;color:#646970"><?= $si + 1 ?></td>
							<td>
								<select name="spot_extra_stages[<?= $si ?>][type]">
									<option value="fixed"   <?= ($stage['type'] ?? '') === 'fixed'   ? 'selected' : '' ?>>مبلغ ثابت (تومان)</option>
									<option value="percent" <?= ($stage['type'] ?? '') === 'percent' ? 'selected' : '' ?>>درصد از مجموع سفارش</option>
								</select>
							</td>
							<td><input type="number" name="spot_extra_stages[<?= $si ?>][value]" value="<?= esc_attr($stage['value'] ?? '') ?>" min="0" step="any" placeholder="مثلاً 500000"></td>
							<td><button type="button" class="button sp-stage-remove">✕</button></td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
				<button type="button" id="sp-add-stage" class="button">+ افزودن پله</button>

				<hr style="margin:20px 0;border:none;border-top:1px solid #f0f0f1">

				<p style="font-weight:600;margin:0 0 8px">رفتار بعد از آخرین پله</p>
				<?php $end_mode = $sp['extra_end_mode'] ?? 'max'; ?>
				<label class="sp-end-mode-opt">
					<input type="radio" name="spot_extra_end_mode" value="max" <?= $end_mode === 'max' ? 'checked' : '' ?>>
					<div>
						<span style="font-weight:600;display:block;margin-bottom:2px">حداکثر N بار</span>
						<span style="color:#646970;font-size:12px">بعد از پرداخت آخرین پله، ثبت درخواست جدید ممکن نخواهد بود. N برابر تعداد پله‌های تعریف‌شده است.</span>
					</div>
				</label>
				<label class="sp-end-mode-opt">
					<input type="radio" name="spot_extra_end_mode" value="repeat_last" <?= $end_mode === 'repeat_last' ? 'checked' : '' ?>>
					<div>
						<span style="font-weight:600;display:block;margin-bottom:2px">تکرار قیمت پله آخر</span>
						<span style="color:#646970;font-size:12px">از پله آخر به بعد، همان مبلغ برای همه‌ی درخواست‌های بعدی اعمال می‌شود و محدودیتی در تعداد دفعات وجود ندارد.</span>
					</div>
				</label>

				<p style="margin-top:20px">
					<?php submit_button('ذخیره تنظیمات دسترسی اضافه', 'primary', 'spot_extra_submit', false) ?>
				</p>
			</form>
		</div>

	</div><!-- /sp-tab-extra -->

	</div><!-- /sp-settings -->

	<script>
	(function () {

		// ── جابجایی تب‌ها ────────────────────────────────────────────────────
		var btns   = document.querySelectorAll('.sp-tab-btn');
		var panels = document.querySelectorAll('.sp-tab-panel');

		function activate(id) {
			btns.forEach(function (b) { b.classList.toggle('sp-tab-active', b.dataset.tab === id); });
			panels.forEach(function (p) { p.classList.toggle('sp-tab-active', p.id === 'sp-tab-' + id); });
			try { localStorage.setItem('sp_active_tab', id); } catch (e) {}
		}

		btns.forEach(function (btn) {
			btn.addEventListener('click', function () { activate(btn.dataset.tab); });
		});

		var initial = <?= $force_tab ? json_encode($force_tab) : 'null' ?>;
		if (!initial) { try { initial = localStorage.getItem('sp_active_tab'); } catch (e) {} }
		activate(initial && document.getElementById('sp-tab-' + initial) ? initial : 'general');

		// ── تست API ──────────────────────────────────────────────────────────
		var apiBtn = document.getElementById('spot-test-api-btn');
		var apiRes = document.getElementById('spot-test-api-result');
		apiBtn.addEventListener('click', function () {
			var api = document.getElementById('spot-api-key').value.trim();
			if (!api) { apiRes.style.color = '#c00'; apiRes.textContent = 'ابتدا کلید API را وارد کنید.'; return; }
			apiBtn.disabled = true;
			apiRes.style.color = '#646970'; apiRes.textContent = '…';
			var fd = new FormData();
			fd.append('action', 'spot_test_api');
			fd.append('nonce',  <?= json_encode(wp_create_nonce('spot_test_api')) ?>);
			fd.append('api',    api);
			fetch(<?= json_encode(admin_url('admin-ajax.php')) ?>, {method: 'POST', body: fd})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					apiRes.style.color = res.success ? '#2a7a2a' : '#c00';
					apiRes.textContent = (res.success ? '✅ ' : '❌ ') + (typeof res.data === 'string' ? res.data : '');
				})
				.catch(function () { apiRes.style.color = '#c00'; apiRes.textContent = '❌ خطا در ارتباط با سرور'; })
				.finally(function () { apiBtn.disabled = false; });
		});

		// ── جدول دوره‌ها ─────────────────────────────────────────────────────
		var courseTbody  = document.getElementById('spot-courses-tbody');
		var courseCounter = <?= count($saved_courses) ?>;

		function makeInstRow(cidx, iidx) {
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td><input type="number" name="spot_courses[' + cidx + '][installments][' + iidx + '][from]" min="0" placeholder="0"></td>'
				+ '<td><input type="text" name="spot_courses[' + cidx + '][installments][' + iidx + '][to]" placeholder="مثلاً 4 — یا خالی"></td>'
				+ '<td><input type="number" name="spot_courses[' + cidx + '][installments][' + iidx + '][days]" min="0" placeholder="مثلاً 30 — آخرین قسط خالی"></td>'
				+ '<td><button type="button" class="button sp-inst-remove">✕</button></td>';
			bindInstRemove(tr.querySelector('.sp-inst-remove'));
			return tr;
		}

		function updateToggleLabel(courseRow) {
			var wrapRow = courseRow.nextElementSibling;
			var count   = wrapRow ? wrapRow.querySelectorAll('.sp-inst-tbody tr').length : 0;
			var btn     = courseRow.querySelector('.sp-inst-toggle');
			btn.textContent = '📋 اقساط' + (count > 0 ? ' (' + count + ')' : '');
			btn.classList.toggle('open', count > 0);
		}

		function bindInstRemove(btn) {
			btn.addEventListener('click', function () {
				var wrapRow = btn.closest('.sp-inst-wrap-row');
				btn.closest('tr').remove();
				updateToggleLabel(wrapRow.previousElementSibling);
			});
		}

		function bindCourseRow(courseRow) {
			var wrapRow  = courseRow.nextElementSibling;
			var instTbody = wrapRow.querySelector('.sp-inst-tbody');

			courseRow.querySelector('.spot-remove-row').addEventListener('click', function () {
				wrapRow.remove();
				courseRow.remove();
			});

			courseRow.querySelector('.sp-inst-toggle').addEventListener('click', function () {
				var open = wrapRow.style.display !== 'none';
				wrapRow.style.display = open ? 'none' : '';
				this.classList.toggle('open', !open);
			});

			wrapRow.querySelector('.sp-add-inst').addEventListener('click', function () {
				var cidx = courseRow.dataset.idx;
				var iidx = instTbody.querySelectorAll('tr').length;
				instTbody.appendChild(makeInstRow(cidx, iidx));
				wrapRow.style.display = '';
				updateToggleLabel(courseRow);
			});

			instTbody.querySelectorAll('.sp-inst-remove').forEach(bindInstRemove);
		}

		courseTbody.querySelectorAll('.sp-course-row').forEach(bindCourseRow);

		document.getElementById('spot-add-course').addEventListener('click', function () {
			var cidx      = courseCounter;
			var courseRow = document.createElement('tr');
			courseRow.className  = 'sp-course-row';
			courseRow.dataset.idx = cidx;
			courseRow.innerHTML  =
				'<td><input type="text" name="spot_courses[' + cidx + '][name]" placeholder="مثلاً: دوره پایتون" required></td>'
				+ '<td class="sp-id-col"><input type="text" name="spot_courses[' + cidx + '][id]" placeholder="5d2ee35bcddc092a304ae5eb" pattern="[0-9a-fA-F]{24}" required></td>'
				+ '<td><div class="sp-td-actions"><button type="button" class="button sp-inst-toggle">📋 اقساط</button><button type="button" class="button spot-remove-row">✕</button></div></td>';

			var wrapRow = document.createElement('tr');
			wrapRow.className   = 'sp-inst-wrap-row';
			wrapRow.style.display = 'none';
			wrapRow.innerHTML   =
				'<td colspan="3" style="padding:0 0 6px"><div class="sp-inst-wrap">'
				+ '<table class="sp-inst-table"><thead><tr>'
				+ '<th style="width:22%">سرفصل از</th><th style="width:28%">تا (خالی = دسترسی کامل)</th>'
				+ '<th style="width:35%">روز تا سررسید قسط بعدی</th><th style="width:15%"></th>'
				+ '</tr></thead><tbody class="sp-inst-tbody"></tbody></table>'
				+ '<button type="button" class="button sp-add-inst">+ افزودن قسط</button>'
				+ '</div></td>';

			courseTbody.appendChild(courseRow);
			courseTbody.appendChild(wrapRow);
			bindCourseRow(courseRow);
			courseRow.querySelector('input').focus();
			courseCounter++;
		});

		// ── شمارنده کاراکتر پیامک ────────────────────────────────────────────
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

		// ── ارسال پیامک آزمایشی ──────────────────────────────────────────────
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

		// ── پولینگ صف پردازش ─────────────────────────────────────────────────
		<?php if ($use_async) { ?>
		(function () {
			var nonce  = <?= json_encode($queue_nonce) ?>;
			var ajax   = <?= json_encode(admin_url('admin-ajax.php')) ?>;
			var asUrl  = <?= json_encode(esc_url($as_url)) ?>;
			var active = <?= (int) $active_count ?>;
			var timer  = null;

			function cell(id) { return document.getElementById(id); }
			function setCell(id, val) {
				var el = cell(id); if (!el) return;
				el.textContent = val;
				el.style.color = (id.indexOf('failed') !== -1 && val > 0) ? '#900' : '';
			}
			function updateStatus(data) {
				var c = data.create, d = data.disable;
				setCell('spot-qs-create-pending',  c.pending);
				setCell('spot-qs-create-running',  c.running);
				setCell('spot-qs-create-failed',   c.failed);
				setCell('spot-qs-create-complete', c.complete);
				setCell('spot-qs-disable-pending', d.pending);
				setCell('spot-qs-disable-running', d.running);
				setCell('spot-qs-disable-failed',  d.failed);
				setCell('spot-qs-disable-complete',d.complete);
				var nowActive = c.pending + c.running + d.pending + d.running;
				var status = cell('spot-qs-status');
				if (!status) return;
				if (nowActive > 0) {
					status.innerHTML = '<span>⏳ در حال پردازش — صفحه به‌روز می‌شود…</span>';
				} else if (active > 0) {
					var hasFailed = (c.failed + d.failed) > 0;
					status.innerHTML = (hasFailed ? '⚠️ پردازش با برخی خطاها تکمیل شد. ' : '✅ پردازش با موفقیت تکمیل شد. ')
						+ '<a href="' + asUrl + '" target="_blank">جزئیات در Action Scheduler ↗</a>';
					clearInterval(timer);
				} else {
					status.innerHTML = '<a href="' + asUrl + '" target="_blank">مشاهده جزئیات در Action Scheduler ↗</a>';
					clearInterval(timer);
				}
				active = nowActive;
			}
			function poll() {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajax);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function () {
					try { var r = JSON.parse(xhr.responseText); if (r.success) updateStatus(r.data); } catch (e) {}
				};
				xhr.send('action=spot_queue_status&nonce=' + encodeURIComponent(nonce));
			}
			if (active > 0) timer = setInterval(poll, 5000);
		})();
		<?php } ?>

		// ── جدول پله‌های دسترسی اضافه ──────────────────────────────────────
		(function () {
			var tbody  = document.getElementById('sp-stages-tbody');
			var addBtn = document.getElementById('sp-add-stage');
			if (!tbody || !addBtn) return;

			function reindex() {
				tbody.querySelectorAll('tr').forEach(function (tr, i) {
					tr.querySelector('td:first-child').textContent = i + 1;
					tr.querySelector('select').name = 'spot_extra_stages[' + i + '][type]';
					tr.querySelector('input[type=number]').name = 'spot_extra_stages[' + i + '][value]';
				});
			}

			function bindRemove(btn) {
				btn.addEventListener('click', function () { btn.closest('tr').remove(); reindex(); });
			}

			function makeRow() {
				var i  = tbody.querySelectorAll('tr').length;
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td style="text-align:center;font-weight:600;color:#646970">' + (i + 1) + '</td>'
					+ '<td><select name="spot_extra_stages[' + i + '][type]">'
					+ '<option value="fixed">مبلغ ثابت (تومان)</option>'
					+ '<option value="percent">درصد از مجموع سفارش</option>'
					+ '</select></td>'
					+ '<td><input type="number" name="spot_extra_stages[' + i + '][value]" min="0" step="any" placeholder="مثلاً 500000"></td>'
					+ '<td><button type="button" class="button sp-stage-remove">✕</button></td>';
				bindRemove(tr.querySelector('.sp-stage-remove'));
				return tr;
			}

			tbody.querySelectorAll('.sp-stage-remove').forEach(bindRemove);
			addBtn.addEventListener('click', function () { tbody.appendChild(makeRow()); });
		})();

	})();
	</script>
	<?php
}

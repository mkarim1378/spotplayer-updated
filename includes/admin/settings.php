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
	add_menu_page('', 'اسپات پلیر', 'manage_options', 'spotplayer', 'spot_admin_page', SPOTPLAYER_URL . 'assets/svg/icon.svg');
}
add_action('admin_menu', 'spot_admin_menu');

function spot_admin_page() {
	if (!current_user_can('manage_options')) return;

	if (isset($_GET['settings-updated'])) add_settings_error('spot_msgs', 'spot_msg', 'تنظیمات اسپات پلیر ذخیره شد.', 'updated');
	settings_errors('spot_msgs');

	$p  = spot_woo_or_edd();
	$sp = get_option('spotplayer');

	$use_async = function_exists('as_schedule_single_action');

	// پردازش فرم ایجاد سفارش دسته‌ای (۳ ستونه)
	if (isset($_POST['bulk_product_id']) && isset($_FILES['bulk_excel']) && check_admin_referer('spotplayer_bulk_license', 'spotplayer_bulk_license_nonce')) {
		$product_id = intval($_POST['bulk_product_id']);
		$limit_raw  = sanitize_text_field($_POST['bulk_chapters'] ?? '');

		if (empty($_FILES['bulk_excel']['tmp_name']) || !is_uploaded_file($_FILES['bulk_excel']['tmp_name'])) {
			echo '<div class="error"><p>فایل اکسل ارسال نشد.</p></div>';
		} elseif ($use_async) {
			$res = spot_bulk_schedule_create_orders($product_id, $limit_raw, $_FILES['bulk_excel']['tmp_name']);
			if ($res['queued'] > 0)
				echo '<div class="updated"><p>✅ تعداد ' . $res['queued'] . ' ردیف در صف پردازش قرار گرفت. سفارشات و لایسنس‌ها به زودی در پس‌زمینه ساخته می‌شوند.</p></div>';
			if (!empty($res['errors'])) {
				echo '<div class="error"><p>❌ خطاها:</p><ul>';
				foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
				echo '</ul></div>';
			}
		} else {
			$res = spot_bulk_create_orders_3col($product_id, $limit_raw, $_FILES['bulk_excel']['tmp_name']);
			if (!empty($res['success'])) {
				echo '<div class="updated"><p>✅ تعداد ' . count($res['success']) . ' سفارش با موفقیت تکمیل شد.</p>';
				echo '<div style="max-height: 200px; overflow-y: auto;"><ul>';
				foreach ($res['success'] as $s)
					echo '<li>' . esc_html($s['name']) . ' (' . esc_html($s['phone']) . ') | سفارش #' . $s['order_id'] . ' | لایسنس: ' . $s['license_id'] . '</li>';
				echo '</ul></div></div>';
			}
			if (!empty($res['errors'])) {
				echo '<div class="error"><p>❌ خطاها:</p><ul>';
				foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
				echo '</ul></div>';
			}
		}
	}

	// پردازش فرم غیرفعال‌سازی دسته‌ای لایسنس‌ها از CSV
	if (isset($_POST['bulk_disable_submit']) && isset($_FILES['bulk_disable_excel']) && check_admin_referer('spotplayer_bulk_disable', 'spotplayer_bulk_disable_nonce')) {
		if (empty($_FILES['bulk_disable_excel']['tmp_name']) || !is_uploaded_file($_FILES['bulk_disable_excel']['tmp_name'])) {
			echo '<div class="error"><p>فایل اکسل/CSV لایسنس‌ها به درستی ارسال نشده است.</p></div>';
		} elseif ($use_async) {
			$res = spot_bulk_schedule_disable_licenses($_FILES['bulk_disable_excel']['tmp_name']);
			if ($res['queued'] > 0)
				echo '<div class="updated"><p>✅ تعداد ' . $res['queued'] . ' لایسنس در صف غیرفعال‌سازی قرار گرفت و به زودی در پس‌زمینه پردازش می‌شود.</p></div>';
			if (!empty($res['errors'])) {
				echo '<div class="error"><p>❌ خطاها:</p><ul>';
				foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
				echo '</ul></div>';
			}
		} else {
			try {
				$res = spot_bulk_disable_licenses_from_csv($_FILES['bulk_disable_excel']['tmp_name']);
				if (!empty($res['success'])) {
					echo '<div class="updated"><p>تعداد ' . count($res['success']) . ' لایسنس با موفقیت غیرفعال شد.</p><ul>';
					foreach ($res['success'] as $s)
						echo '<li>ردیف ' . intval($s['row']) . ' - شناسه لایسنس: ' . esc_html($s['id']) . '</li>';
					echo '</ul></div>';
				}
				if (!empty($res['errors'])) {
					echo '<div class="error"><p>برخی ردیف‌ها با خطا مواجه شدند:</p><ul>';
					foreach ($res['errors'] as $e) echo '<li>' . esc_html($e) . '</li>';
					echo '</ul></div>';
				}
			} catch (Throwable $e) {
				echo '<div class="error"><p>خطای غیرمنتظره: ' . esc_html($e->getMessage()) . '</p></div>';
			}
		}
	} ?>

	<div id="sp-settings" class="wrap">
	<h1>
		تنظیمات اسپات پلیر
		<a href="https://spotplayer.ir/help/api/wordpress" target="_blank">(راهنما)</a>
	</h1>
	<!--suppress HtmlUnknownTarget -->
	<form action="options.php" method="post">
	<?php settings_fields('spotplayer') ?>
	<table class="form-table" role="presentation">
	<tbody>
	<tr>
		<th scope="row">کلید API</th>
		<td>
			<input type="text" name="spotplayer[api]" value="<?= @$sp['api'] ?>" required pattern="^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$">
			<div class="description">
				<div>کلید API که در داشبورد اسپات پلیر در دسترس است.</div>
				<div><b style="color: #900">توجه داشته باشید تغییر کلمه عبور اسپات پلیر باعث تغییر کلید API خواهد شد.</b></div>
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row">دامنه ریبرندینگ</th>
		<td>
			<input type="text" name="spotplayer[domain]" value="<?= @$sp['domain'] ?>" pattern="^app[0-9]?(\.[a-z0-9\-]+){2,}$">
			<div class="description">
				<div><b style="color: #900">تنها در صورتی که سرویس ریبرندینگ را فعال کرده اید، دامنه تنظیم شده را به صورت app.example.com وارد نمایید.</b></div>
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row">رنگ اصلی</th>
		<td>
			<input type="color" name="spotplayer[color]" value="<?= @$sp['color'] ?: '#6611DD' ?>">
		</td>
	</tr>
	<tr>
		<th scope="row">کد ساخت لایسنس</th>
		<td>
			<textarea name="spotplayer[code]"><?= spot_license_code() ?></textarea>
			<div style="background: rgba(0,0,0,0.07); padding: 10px; border-radius: 5px; margin-bottom: 15px">
				<div style="color: green;">خروجی کد برای آخرین سفارش ثبت شده:</div>
				<div style="direction: ltr">
					<?php
					try {
						$j = spot_woo_or_edd() == 1 ? spot_woo_license_data_eval(wc_get_orders(['limit' => 1])[0]) : spot_edd_license_data_eval(edd_get_payments(['number' => 1])[0]);
						if (!$j) echo '<div style="color: red; direction: rtl">هیچ سفارش فعالی وجود ندارد. برای تست لطفا یک سفارش ایجاد کنید.</div>';
						else {
							echo '<pre>' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</pre>';
							if (!$j['name'] || !$j['watermark']['texts'][0]['text']) {
								$id = spot_woo_or_edd() == 1 ? wc_get_orders(['limit' => 1])[0]->get_id() : edd_get_payments(['number' => 1])[0]->ID;
								$a  = '<div style="direction: rtl"><a target="_blank" href="' . parse_url(get_home_url(), PHP_URL_PATH) . '/spdeb?id=' . $id . '">اطلاعات دیباگ</a></div>';
								if (!$j['name']) echo '<div style="color: red; direction: rtl">مقدار نام خالی است. لطفا از یک فیلد دیگر برای تعیین مقدار نام استفاده کنید.</div>' . $a;
								if (!$j['watermark']['texts'][0]['text']) echo '<div style="color: red; direction: rtl">مقدار اولین واترمارک خالی است. لطفا از یک فیلد دیگر برای تعیین مقدار واترمارک استفاده کنید.</div>' . $a;
							}
						}
					} catch (Error $e) {
						echo '<div style="color: red">' . $e->getMessage() . '</div>';
						echo '<div style="color: red; direction: rtl">لطفا سینتکس کد وارد شده را بررسی و اصلاح کرده و تنظیمات را ذخیره نمایید.</div>';
					} ?>
				</div>
			</div>
			<div class="description">
				<div>کدی که به منظور ساخت لایسنس استفاده میشود. برای بازیابی مقدار پیشفرض این فیلد را خالی قرار داده و تنظیمات را ذخیره نمایید. برای ساخت لایسنس متغیرهای زیر در دسترس هستند:</div>
				<?php if ($p == 1) { ?>
					<div>متغیر order ووکامرس شامل اطلاعات سفارش است.</div>
					<ul style="direction: ltr">
						<li style="margin-top: 15px"><b>$order</b> <a target="_blank" href="https://woocommerce.github.io/code-reference/classes/WC-Order.html"><small>https://woocommerce.github.io/code-reference/classes/WC-Order.html</small></a></li>
						<li>$order-&gt;get_formatted_billing_full_name()</li>
						<li>$order-&gt;get_billing_phone()</li>
						<li>$order-&gt;get_billing_email()</li>
						<li>$order-&gt;get_meta("_meta_key")</li>
					</ul>
				<?php } else if ($p == 2) { ?>
					<ul style="direction: ltr">
						<li style="margin-top: 15px"><b>$payment</b> <a target="_blank" href="https://docs.easydigitaldownloads.com/article/1113-eddpayment"><small>https://docs.easydigitaldownloads.com/article/1113-eddpayment</small></a></li>
						<li>$payment-&gt;first_name</li>
						<li>$payment-&gt;last_name</li>
						<li>$payment-&gt;email</li>
					</ul>
				<?php } else { ?>
					<div style="color: red">هیچکدام از پلاگین‌های ووکامرس یا EDD فعال نیستند.</div>
				<?php } ?>
				<?php if ($p) { ?>
					<div>متغیر user وردپرس شامل اطلاعات خریدار است.</div>
					<ul style="direction: ltr">
						<li style="margin-top: 15px"><b>$user</b> <a target="_blank" href="https://developer.wordpress.org/reference/classes/wp_user/"><small>https://developer.wordpress.org/reference/classes/wp_user/</small></a></li>
						<li>$user-&gt;user_login</li>
						<li>$user-&gt;user_firstname</li>
						<li>$user-&gt;user_lastname</li>
						<li>$user-&gt;user_email</li>
						<li>$user-&gt;get('digits_phone')</li>
					</ul>
					<div><b style="color: #900">حتما از سیستم پیامک تایید شماره دیجیتس هنگام ثبت نام کاربران استفاده کنید تا واترمارک های ویدیو قابل ردگیری باشد.</b></div>
				<?php } ?>
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row">تنظیمات ساخت لایسنس</th>
		<td>
			<div>
				<input type="checkbox" name="spotplayer[test]" value="1" <?= $sp['test'] ? 'checked="checked"' : '' ?>>
				<b>حالت تستی ایجاد لایسنس ←</b>
				فعال بودن این گزینه باعث ایجاد شدن لایسنس های تستی پس از خریدها میشود.
				<div><b style="color: #900">به یاد داشته باشید که پس از تست افزونه حتما این گزینه را غیرفعال نمایید زیرا باعث میشود لایسنس‌های جدید جایگزین لایسنس‌های قبلی شوند.</b></div>
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row"></th>
		<td>
			<div>
				<input type="checkbox" name="spotplayer[time]" value="<?= $sp['time'] ?: time() ?>" <?= $sp['time'] ? 'checked="checked"' : '' ?>>
				<b>عدم ایجاد لایسنس برای سفارشات قدیمی ←</b>
				فعال کردن این گزینه باعث میشود لایسنس برای سفارشاتی که قبل از فعال کردن این گزینه ثبت شده‌اند ایجاد نشود.
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row"></th>
		<td>
			<div>
				<input type="checkbox" name="spotplayer[completed]" value="1" <?= $sp['completed'] ? 'checked="checked"' : '' ?>>
				<b>ایجاد لایسنس پس از تکمیل سفارش به صورت دستی ←</b>
				فعال کردن این گزینه باعث میشود چنین سفارشی پس از پرداخت به حالت در حال انجام رفته و تا زمانی که تایید نشده است لایسنس ایجاد نشود.
				<div><b style="color: #900">توجه داشته باشید در صورتی که محصول دانلودی باشد ووکامرس به صورت خودکار سفارش را تکمیل خواهد کرد.</b></div>
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row">تنظیمات نمایش</th>
		<td>
			<div>
				<input type="checkbox" name="spotplayer[web]" value="1" <?= $sp['web'] ? 'checked="checked"' : '' ?>
				       onchange="const w = document.getElementById('webonly'); (w.disabled = !this.checked) ? (w.checked = false) : null; w.onchange(null)">
				<b>نمایش نسخه وب در سایت ←</b>
				فعال کردن این گزینه باعث میشود در صورتی که نسخه وب برای لایسنس ساخته شده فعال باشد پلیر تحت وب در سایت شما نمایش داده شود.
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row"></th>
		<td>
			<div>
				<input id="webonly" <?= $sp['web'] ? '' : 'disabled="disabled"' ?> type="checkbox" name="spotplayer[webonly]" value="1" <?= $sp['webonly'] ? 'checked="checked"' : '' ?>
				       onchange="const d = document.getElementById('download'); (d.disabled = this.checked) ? (d.checked = false) : null;">
				<b>فقط نمایش نسخه وب ←</b>
				فعال کردن این گزینه باعث میشود که فقط نسخه وب نمایش داده شده و نسخه های نیتیو و لیست دانلود نمایش داده نشوند.
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row"></th>
		<td>
			<div>
				<input id="download" <?= $sp['webonly'] ? 'disabled="disabled"' : '' ?> type="checkbox" name="spotplayer[download]" value="1" <?= $sp['download'] ? 'checked="checked"' : '' ?>>
				<b>نمایش لیست دانلود ←</b>
				از آنجایی که برنامه به طور خودکار فایل‌ها را دانلود کرده و نمایش می‌دهد این گزینه به طور پیشفرض غیرفعال است.
			</div>
		</td>
	</tr>
	<?php if (spot_woo_or_edd() == 1) { ?>
		<tr>
			<th scope="row"></th>
			<td>
				<div>
					<input type="checkbox" name="spotplayer[wccrs]" value="1" <?= $sp['wccrs'] ? 'checked="checked"' : '' ?>>
					<b>نمایش گزینه لایسنس‌های من در منوی کاربری ووکامرس ←</b>
					فعال کردن این گزینه باعث میشود در منوی حساب من ووکامرس گزینه لایسنس‌های من که به صفحه شورت کد دوره‌ها لینک است نمایش داده شود.
				</div>
			</td>
		</tr>
	<?php }
	if (class_exists('Studiare_Core')) { ?>
		<tr>
			<th scope="row"></th>
			<td>
				<div>
					<input type="checkbox" name="spotplayer[wcspc]" value="1" <?= $sp['wcspc'] ? 'checked="checked"' : '' ?>>
					<b>حذف لینک دوره‌های خریداری شده قالب استادیار از منوی کاربری ووکامرس ←</b>
				</div>
			</td>
		</tr>
	<?php } ?>
	<tr>
		<th scope="row">شورت کدها</th>
		<td>
			<div>
				<b>spotplayer_courses</b>
				با استفاده از این شورت کد، کل دوره‌های سفارش‌های لایسنس دار کاربر با امکان مشاهده آنلاین و دریافت لایسنس نمایش داده میشود.
			</div>
		</td>
	</tr>
	</tbody>
	</table>
	<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="ذخیره تنظیمات"></p>
	</form>

	<hr/>

	<?php if ($use_async) {
		$qs           = spot_bulk_queue_status();
		$as_url       = admin_url('admin.php?page=action-scheduler&s=spot_bulk&status=pending');
		$active_count = $qs['create']['pending'] + $qs['create']['running'] + $qs['disable']['pending'] + $qs['disable']['running'];
		$has_any      = $active_count + $qs['create']['failed'] + $qs['create']['complete'] + $qs['disable']['failed'] + $qs['disable']['complete'] > 0;
		$queue_nonce  = wp_create_nonce('spot_queue_status'); ?>
		<h2>وضعیت صف پردازش ناهمزمان</h2>
		<table id="spot-qs-table" class="widefat" style="max-width: 600px; margin-bottom: 10px">
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
		<p id="spot-qs-status" style="margin:6px 0 15px">
			<?php if ($active_count > 0) { ?>
				<span id="spot-qs-spinner">⏳ در حال پردازش — صفحه به‌روز می‌شود…</span>
			<?php } else if ($has_any) { ?>
				<a href="<?= esc_url($as_url) ?>" target="_blank">مشاهده جزئیات در Action Scheduler ↗</a>
			<?php } ?>
		</p>
		<hr/>
		<script>
		(function () {
			var nonce     = <?= json_encode($queue_nonce) ?>;
			var ajaxurl   = <?= json_encode(admin_url('admin-ajax.php')) ?>;
			var asUrl     = <?= json_encode(esc_url($as_url)) ?>;
			var active    = <?= (int)$active_count ?>;
			var timer     = null;

			function cell(id) { return document.getElementById(id); }

			function setCell(id, val) {
				var el = cell(id);
				if (!el) return;
				el.textContent = val;
				el.style.color = (id.indexOf('failed') !== -1 && val > 0) ? '#900' : '';
			}

			function updateStatus(data) {
				var c = data.create, d = data.disable;
				setCell('spot-qs-create-pending',   c.pending);
				setCell('spot-qs-create-running',   c.running);
				setCell('spot-qs-create-failed',    c.failed);
				setCell('spot-qs-create-complete',  c.complete);
				setCell('spot-qs-disable-pending',  d.pending);
				setCell('spot-qs-disable-running',  d.running);
				setCell('spot-qs-disable-failed',   d.failed);
				setCell('spot-qs-disable-complete', d.complete);

				var nowActive = c.pending + c.running + d.pending + d.running;
				var status    = cell('spot-qs-status');
				if (!status) return;

				if (nowActive > 0) {
					status.innerHTML = '<span id="spot-qs-spinner">⏳ در حال پردازش — صفحه به‌روز می‌شود…</span>';
				} else if (active > 0) {
					// was active, now done
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
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function () {
					try {
						var res = JSON.parse(xhr.responseText);
						if (res.success) updateStatus(res.data);
					} catch (e) {}
				};
				xhr.send('action=spot_queue_status&nonce=' + encodeURIComponent(nonce));
			}

			if (active > 0) timer = setInterval(poll, 5000);
		})();
		</script>
	<?php } ?>

	<h2>غیرفعال‌سازی دسته‌ای لایسنس‌های موجود از فایل اکسل/CSV</h2>
	<p>شناسه هر لایسنس باید در ستونی با نام <code>id</code> قرار گیرد (۲۴ کاراکتر هگز).</p>
	<form method="post" enctype="multipart/form-data">
		<?php wp_nonce_field('spotplayer_bulk_disable', 'spotplayer_bulk_disable_nonce'); ?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><label for="bulk_disable_excel">فایل اکسل (CSV) لایسنس‌های موجود</label></th>
				<td>
					<input type="file" name="bulk_disable_excel" id="bulk_disable_excel" accept=".csv" required>
					<p class="description">فایل CSV که در ردیف اول آن ستونی با نام <code>id</code> و در ردیف‌های بعدی شناسه لایسنس‌ها قرار دارد.</p>
				</td>
			</tr>
			</tbody>
		</table>
		<input type="hidden" name="bulk_disable_submit" value="1">
		<?php submit_button('غیرفعال‌سازی لایسنس‌ها'); ?>
	</form>

	<hr/>
	<h2>ایجاد سفارش دسته‌ای و لایسنس (از طریق محصول ووکامرس)</h2>
	<div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 10px 15px; margin: 15px 0;">
		<p><strong>⚠️ نکته مهم:</strong> برای جلوگیری از خطای Time-out سرور، پیشنهاد می‌شود فایل‌های حجیم را به دسته‌های ۵۰ تایی تقسیم کنید.</p>
	</div>
	<p>فایل CSV باید دارای ۳ ستون با هدرهای زیر باشد: نام (name)، نام خانوادگی (family)، موبایل (phone)</p>
	<form method="post" enctype="multipart/form-data">
		<?php wp_nonce_field('spotplayer_bulk_license', 'spotplayer_bulk_license_nonce'); ?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><label for="bulk_product_id">انتخاب محصول ووکامرس</label></th>
				<td>
					<select name="bulk_product_id" id="bulk_product_id" class="regular-text" required>
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
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bulk_chapters">محدودیت سرفصل (Limit)</label></th>
				<td>
					<input type="text" name="bulk_chapters" id="bulk_chapters" class="regular-text ltr" placeholder="مثال: 0- یا 1,4-6">
					<p class="description">اختیاری. این محدودیت روی لایسنس‌ها اعمال خواهد شد.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bulk_excel">فایل اکسل (CSV)</label></th>
				<td><input type="file" name="bulk_excel" id="bulk_excel" accept=".csv" required></td>
			</tr>
			</tbody>
		</table>
		<?php submit_button('ایجاد سفارشات و لایسنس‌ها'); ?>
	</form>
	</div>
	<?php
}

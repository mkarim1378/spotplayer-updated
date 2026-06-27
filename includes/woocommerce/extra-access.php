<?php
if (!defined('ABSPATH')) exit;

// ── Endpoint registration ────────────────────────────────────────────────────

function spot_extra_endpoint_init(): void {
	add_rewrite_endpoint('license-request', EP_PAGES);
}
add_action('init', 'spot_extra_endpoint_init');

// ── Account menu item ────────────────────────────────────────────────────────

function spot_extra_menu_item(array $links): array {
	$new = ['license-request' => 'درخواست دسترسی اضافه'];
	if (isset($links['licenses'])) {
		$pos = array_search('licenses', array_keys($links), true);
		return array_slice($links, 0, $pos + 1, true)
			+ $new
			+ array_slice($links, $pos + 1, null, true);
	}
	return array_slice($links, 0, 1, true) + $new + array_slice($links, 1, null, true);
}
add_filter('woocommerce_account_menu_items', 'spot_extra_menu_item', 51);

// ── Endpoint content ─────────────────────────────────────────────────────────

function spot_extra_render_page(): void {
	if (!is_user_logged_in()) {
		wp_safe_redirect(wc_get_account_endpoint_url('dashboard'));
		exit;
	}
	?>
	<style>
	.spot-extra-wrap {direction:rtl; }
	.spot-extra-form {
		background:#fff; border:1px solid #e2e8f0; border-radius:8px;
		padding:28px; margin-bottom:28px;
		box-shadow:0 1px 4px rgba(0,0,0,.07);
	}
	.spot-extra-form .form-row { margin-bottom:18px; }
	.spot-extra-form .form-row > label {
		display:block; font-weight:600; margin-bottom:6px;
		color:#374151; font-size:14px;
	}
	.spot-extra-form .input-text,
	.spot-extra-form select {
		border:1px solid #d1d5db; border-radius:5px;
		padding:9px 12px; font-size:14px;
		width:100%; max-width:360px; box-sizing:border-box;
	}
	.spot-extra-form .input-text:focus,
	.spot-extra-form select:focus {
		border-color:#6366f1; outline:none;
		box-shadow:0 0 0 3px rgba(99,102,241,.15);
	}
	#spot-extra-price-row {
		background:#f0fdf4; border:1px solid #bbf7d0;
		border-radius:6px; padding:14px 16px; margin-bottom:18px;
	}
	#spot-extra-price-row > label { color:#166534; }
	#spot-extra-price { font-size:18px; font-weight:700; color:#15803d; }
	#spot-extra-stage { font-size:12px; color:#6b7280; margin-right:6px; }
	#spot-extra-submit-btn {
		background:#4f46e5; border-color:#4338ca; color:#fff;
		padding:10px 24px; font-size:15px; border-radius:6px; cursor:pointer;
	}
	#spot-extra-submit-btn:hover:not(:disabled) { background:#4338ca; }
	#spot-extra-submit-btn:disabled {
		background:#9ca3af; border-color:#9ca3af; cursor:not-allowed;
	}
	.spot-extra-history { width:100%; border-collapse:collapse; font-size:13px; }
	.spot-extra-history th, .spot-extra-history td { text-align:center !important; }
	.spot-device-group { display:flex; gap:10px; flex-wrap:wrap; margin-top:6px; }
	.spot-device-opt {
		display:inline-flex; align-items:center; gap:7px;
		background:#f9fafb; border:1px solid #d1d5db; border-radius:6px;
		padding:8px 14px; cursor:pointer; font-size:14px; font-weight:500;
		transition:border-color .15s, background .15s;
	}
	.spot-device-opt:has(input:checked) {
		background:#eef2ff; border-color:#6366f1; color:#4338ca;
	}
	.spot-device-opt input[type=checkbox] { margin:0; accent-color:#6366f1; width:15px; height:15px; }
	.spot-terms-label {
		display:flex; align-items:flex-start; gap:10px;
		background:#fff7ed; border:1px solid #fed7aa; border-radius:6px;
		padding:12px 14px; font-size:13px; line-height:1.7; cursor:pointer;
	}
	.spot-terms-label input[type=checkbox] { margin-top:3px; flex-shrink:0; accent-color:#6366f1; width:15px; height:15px; }
	.spot-extra-history th {
		background:#f9fafb; padding:8px 12px;
		border:1px solid #e5e7eb; font-weight:600;
	}
	.spot-extra-history td { padding:8px 12px; border:1px solid #e5e7eb; }
	</style>
	<?php

	$uid      = get_current_user_id();
	$customer = new WC_Customer($uid);

	// Licensed orders for this customer (not extra-request orders themselves)
	$licensed_orders = wc_get_orders([
		'customer'   => $uid,
		'limit'      => -1,
		'status'     => ['processing', 'completed'],
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => [
			'relation' => 'AND',
			['key' => '_spotplayer_data',    'compare' => 'EXISTS'],
			['key' => '_spot_extra_request', 'compare' => 'NOT EXISTS'],
		],
	]);

	// Batch-load all paid extra request orders for this user in ONE query
	$all_extra = wc_get_orders([
		'customer'   => $uid,
		'limit'      => -1,
		'status'     => ['processing', 'completed'],
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => [['key' => '_spot_extra_request', 'value' => '1']],
	]);
	$paid_counts   = [];   // origin_id => count of paid requests
	$past_requests = [];   // paid extra orders for history table
	foreach ($all_extra as $ex) {
		$oid = (int) $ex->get_meta('_spot_extra_origin_order');
		$paid_counts[$oid] = ($paid_counts[$oid] ?? 0) + 1;
		$past_requests[]   = $ex;
	}

	// Build origin_map reusing already-loaded licensed orders;
	// batch-fetch any origins needed for past_requests that aren't in licensed_orders
	$origin_map = [];
	foreach ($licensed_orders as $ord) $origin_map[$ord->get_id()] = $ord;
	$missing = array_unique(array_filter(array_map(function ($r) use ($origin_map) {
		$oid = (int) $r->get_meta('_spot_extra_origin_order');
		return isset($origin_map[$oid]) ? null : $oid;
	}, $past_requests)));
	if (!empty($missing)) {
		foreach (wc_get_orders(['include' => $missing, 'limit' => count($missing)]) as $o)
			$origin_map[$o->get_id()] = $o;
	}

	$error = get_transient('spot_extra_error_' . $uid);
	if ($error) delete_transient('spot_extra_error_' . $uid);

	$full_name     = trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
	$billing_phone = $customer->get_billing_phone();

	// Build dropdown options and JS price map using pre-computed data (no per-order queries)
	$order_options = [];
	$price_map     = [];
	foreach ($licensed_orders as $ord) {
		$oid        = $ord->get_id();
		$products   = spot_woo_order_items($ord, true);
		$names      = array_map(function ($p) { return $p->get_name(); }, $products);
		$base_label = implode('، ', $names) ?: 'سفارش #' . $oid;

		$calc       = spot_extra_calc_price_from_count($oid, $paid_counts[$oid] ?? 0, $ord, spot_extra_resolve_course_config($ord));
		$is_blocked = $calc['blocked'];

		if ($calc['blocked']) {
			$suffix = ' (سقف درخواست)';
		} else {
			$suffix = '';
		}

		$order_options[] = [
			'id'      => $oid,
			'label'   => $base_label . ' — #' . $oid . $suffix,
			'blocked' => $is_blocked,
		];
		$price_map[$oid] = [
			'price'   => $calc['price'],
			'stage'   => $calc['stage'],
			'blocked' => $is_blocked,
		];
	}

	$has_options = !empty($order_options);

	// Manual mode: per-course price map for customers without licensed WC orders
	$all_courses = get_option('spot_courses', []);
	$manual_counts = [];
	foreach ($all_extra as $ex) {
		if ($ex->get_meta('_spot_extra_manual') === '1') {
			$mcid = $ex->get_meta('_spot_extra_manual_course');
			if ($mcid !== '') {
				$manual_counts[$mcid] = ($manual_counts[$mcid] ?? 0) + 1;
			}
		}
	}
	$sp_opt = get_option('spotplayer', []);
	$manual_price_map = [];
	foreach ($all_courses as $course) {
		$cid = $course['id'] ?? '';
		if ($cid === '') continue;
		$count = $manual_counts[$cid] ?? 0;
		$cmap  = (array) ($sp_opt['extra_course_stages'] ?? []);
		if (!empty($cmap[$cid]['stages'])) {
			$cfg = ['stages' => array_values($cmap[$cid]['stages']), 'end_mode' => $cmap[$cid]['end_mode'] ?? 'max'];
		} else {
			$cfg = ['stages' => array_values((array) ($sp_opt['extra_stages'] ?? [])), 'end_mode' => $sp_opt['extra_end_mode'] ?? 'max'];
		}
		$mcalc = spot_extra_calc_price_from_count(0, $count, null, $cfg);
		$manual_price_map[$cid] = [
			'price'   => $mcalc['price'],
			'stage'   => $mcalc['stage'],
			'blocked' => $mcalc['blocked'],
		];
	}
	$has_manual = !empty($manual_price_map);
	?>
	<div class="spot-extra-wrap">

		<?php if ($error): ?>
			<ul class="woocommerce-error"><li><?= esc_html($error) ?></li></ul>
		<?php endif ?>

		<form method="post" class="spot-extra-form">
			<?php wp_nonce_field('spot_extra_submit', 'spot_extra_nonce') ?>
			<input type="hidden" name="spot_extra_submit" value="1">
			<input type="hidden" name="spot_extra_mode" value="<?= (!$has_options && $has_manual) ? 'manual' : 'order' ?>" id="spot_extra_mode">

			<p class="form-row">
				<label>نام مشتری</label>
				<strong><?= esc_html($full_name ?: '—') ?></strong>
			</p>

			<p class="form-row">
				<label for="spot_extra_phone">شماره موبایل</label>
				<input type="tel" id="spot_extra_phone" name="spot_extra_phone"
				       value="<?= esc_attr($billing_phone) ?>"
				       placeholder="09XXXXXXXXX" maxlength="11" class="input-text">
			</p>

			<?php if ($has_options): ?>
			<p class="form-row" id="spot-extra-order-row">
				<label for="spot_extra_origin_order">لایسنس مورد نظر</label>
				<select id="spot_extra_origin_order" name="spot_extra_origin_order" class="input-text">
					<option value="">-- انتخاب کنید --</option>
					<?php foreach ($order_options as $opt): ?>
						<option value="<?= esc_attr($opt['id']) ?>"
						        <?php disabled($opt['blocked']) ?>>
							<?= esc_html($opt['label']) ?>
						</option>
					<?php endforeach ?>
				</select>
			</p>
				<?php if ($has_manual): ?>
				<p class="form-row" id="spot-extra-toggle-wrap" style="margin-top:-10px">
					<a href="#" id="spot-manual-toggle" style="font-size:13px;color:#6366f1">لایسنس من در لیست نیست ›</a>
				</p>
				<p class="form-row" id="spot-extra-manual-row" style="display:none">
					<label for="spot_extra_manual_course">دوره‌ای که قبلاً خریداری کرده‌اید را انتخاب کنید</label>
					<select id="spot_extra_manual_course" name="spot_extra_manual_course" class="input-text">
						<option value="">-- انتخاب دوره --</option>
						<?php foreach ($all_courses as $course):
							$cid = $course['id'] ?? '';
							$cname = $course['name'] ?? $cid;
							if ($cid === '') continue;
							$mc = $manual_price_map[$cid] ?? null;
						?>
							<option value="<?= esc_attr($cid) ?>" <?php if ($mc && $mc['blocked']) echo 'disabled' ?>>
								<?= esc_html($cname) ?><?= ($mc && $mc['blocked']) ? ' (سقف درخواست)' : '' ?>
							</option>
						<?php endforeach ?>
					</select>
				</p>
				<?php endif ?>
			<?php elseif ($has_manual): ?>
			<p class="form-row" id="spot-extra-manual-row">
				<label for="spot_extra_manual_course">دوره‌ای که قبلاً خریداری کرده‌اید را انتخاب کنید</label>
				<select id="spot_extra_manual_course" name="spot_extra_manual_course" class="input-text">
					<option value="">-- انتخاب دوره --</option>
					<?php foreach ($all_courses as $course):
						$cid = $course['id'] ?? '';
						$cname = $course['name'] ?? $cid;
						if ($cid === '') continue;
						$mc = $manual_price_map[$cid] ?? null;
					?>
						<option value="<?= esc_attr($cid) ?>" <?php if ($mc && $mc['blocked']) echo 'disabled' ?>>
							<?= esc_html($cname) ?><?= ($mc && $mc['blocked']) ? ' (سقف درخواست)' : '' ?>
						</option>
					<?php endforeach ?>
				</select>
			</p>
			<?php else: ?>
			<p class="form-row"><span>امکان ثبت درخواست در حال حاضر وجود ندارد.</span></p>
			<?php endif ?>

			<p class="form-row" id="spot-extra-price-row" style="display:none">
				<label>مبلغ پرداختی</label>
				<strong id="spot-extra-price"></strong>
				<em id="spot-extra-stage" style="margin-right:6px;color:#888"></em>
			</p>

			<div class="form-row" id="spot-extra-devices-row" style="display:none">
				<label>کدام دستگاه فعال‌شده را می‌خواهید غیرفعال کنیم و به جایش دسترسی اضافه بدهیم؟</label>
				<p style="color:#6b7280;font-size:13px;margin:4px 0 10px">می‌توانید چند گزینه را انتخاب کنید.</p>
				<div class="spot-device-group">
					<label class="spot-device-opt">
						<input type="checkbox" name="spot_extra_devices[]" value="windows" id="spot-dev-windows">
						<span>ویندوز</span>
					</label>
					<label class="spot-device-opt">
						<input type="checkbox" name="spot_extra_devices[]" value="android" id="spot-dev-android">
						<span>اندروید</span>
					</label>
					<label class="spot-device-opt">
						<input type="checkbox" name="spot_extra_devices[]" value="web" id="spot-dev-web">
						<span>وب (آیفون)</span>
					</label>
				</div>
			</div>

			<div class="form-row" id="spot-extra-terms-row" style="display:none">
				<label class="spot-terms-label">
					<input type="checkbox" name="spot_extra_terms" value="1" id="spot_extra_terms">
					<span>آگاهم که لایسنس فعال‌شده فعلی غیرفعال و غیرقابل‌استفاده خواهد شد و لایسنس جدید جایگزین آن می‌شود.</span>
				</label>
			</div>

			<?php if ($has_options || $has_manual): ?>
				<p class="form-row">
					<button type="submit" id="spot-extra-submit-btn" class="button" disabled>
						پرداخت و ثبت درخواست
					</button>
				</p>
			<?php endif ?>
		</form>

		<?php if (!empty($past_requests)): ?>
			<h3 style="margin-top:2rem">درخواست‌های قبلی</h3>
			<table class="shop_table spot-extra-history">
				<thead>
					<tr>
						<th>شماره</th>
						<th>لایسنس</th>
						<th>مرحله</th>
						<th>مبلغ</th>
						<th>تاریخ</th>
						<th>وضعیت</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($past_requests as $req):
						$origin_id    = (int) $req->get_meta('_spot_extra_origin_order');
						$stage        = (int) $req->get_meta('_spot_extra_stage');
						$req_manual   = $req->get_meta('_spot_extra_manual') === '1';
						$c_names      = [];
						if ($req_manual) {
							$mn = (string) $req->get_meta('_spot_extra_manual_course_name');
							if ($mn !== '') $c_names[] = $mn;
						} else {
							$origin_order = $origin_map[$origin_id] ?? null;
							if ($origin_order instanceof WC_Order) {
								foreach (spot_woo_order_items($origin_order, true) as $p)
									$c_names[] = $p->get_name();
							}
						}
					?>
					<tr>
						<td>#<?= $req->get_id() ?></td>
						<td>
							<?= esc_html(implode('، ', $c_names) ?: ($origin_id ? '#' . $origin_id : '—')) ?>
							<?php if ($req_manual): ?><small style="color:#92400e"> (دستی)</small><?php endif ?>
						</td>
						<td><?= esc_html($stage) ?></td>
						<td><?= wc_price($req->get_total()) ?></td>
						<td><?= esc_html(wc_format_datetime($req->get_date_created())) ?></td>
						<td><?= esc_html(wc_get_order_status_name($req->get_status())) ?></td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		<?php endif ?>

	</div>
	<script>
	(function () {
		var orderMap    = <?= wp_json_encode($price_map) ?>;
		var manualMap   = <?= wp_json_encode($manual_price_map) ?>;
		var sel         = document.getElementById('spot_extra_origin_order');
		var manualSel   = document.getElementById('spot_extra_manual_course');
		var modeInput   = document.getElementById('spot_extra_mode');
		var priceRow    = document.getElementById('spot-extra-price-row');
		var priceEl     = document.getElementById('spot-extra-price');
		var stageEl     = document.getElementById('spot-extra-stage');
		var devicesRow  = document.getElementById('spot-extra-devices-row');
		var termsRow    = document.getElementById('spot-extra-terms-row');
		var termsChk    = document.getElementById('spot_extra_terms');
		var btn         = document.getElementById('spot-extra-submit-btn');
		var devCheckboxes = devicesRow ? devicesRow.querySelectorAll('input[type=checkbox]') : [];
		var toggleLink  = document.getElementById('spot-manual-toggle');
		var orderRow    = document.getElementById('spot-extra-order-row');
		var manualRow   = document.getElementById('spot-extra-manual-row');
		var toggleWrap  = document.getElementById('spot-extra-toggle-wrap');
		var isManual    = modeInput && modeInput.value === 'manual';

		if (!btn) return;

		function updateBtn() {
			var selOk = false;
			if (isManual) {
				var cid = manualSel ? manualSel.value : '';
				var md  = cid ? manualMap[cid] : null;
				selOk   = md && !md.blocked;
			} else {
				var oid = sel ? sel.value : '';
				var od  = oid ? orderMap[oid] : null;
				selOk   = od && !od.blocked;
			}
			var devOk = false;
			for (var i = 0; i < devCheckboxes.length; i++) {
				if (devCheckboxes[i].checked) { devOk = true; break; }
			}
			var termsOk = termsChk && termsChk.checked;
			btn.disabled = !(selOk && devOk && termsOk);
		}

		function showPrice(price, stage) {
			if (priceRow) priceRow.style.display = '';
			if (priceEl) priceEl.textContent = Number(price).toLocaleString('fa-IR') + ' تومان';
			if (stageEl) stageEl.textContent = '(مرحله ' + stage + ')';
		}

		function hideExtras() {
			if (priceRow)   priceRow.style.display  = 'none';
			if (devicesRow) devicesRow.style.display = 'none';
			if (termsRow)   termsRow.style.display   = 'none';
			for (var i = 0; i < devCheckboxes.length; i++) devCheckboxes[i].checked = false;
			if (termsChk) termsChk.checked = false;
			updateBtn();
		}

		function onSelect(d) {
			if (d && !d.blocked) {
				showPrice(d.price, d.stage);
				if (devicesRow) devicesRow.style.display = '';
				if (termsRow)   termsRow.style.display   = '';
			} else {
				hideExtras();
			}
			updateBtn();
		}

		if (sel) {
			sel.addEventListener('change', function () {
				if (isManual) return;
				onSelect(this.value ? orderMap[this.value] : null);
			});
		}

		if (manualSel) {
			manualSel.addEventListener('change', function () {
				if (!isManual) return;
				onSelect(this.value ? manualMap[this.value] : null);
			});
		}

		if (toggleLink) {
			toggleLink.addEventListener('click', function (e) {
				e.preventDefault();
				isManual = !isManual;
				modeInput.value = isManual ? 'manual' : 'order';
				if (isManual) {
					if (orderRow)   orderRow.style.display   = 'none';
					if (manualRow)  manualRow.style.display   = '';
					if (toggleWrap) toggleWrap.style.display  = '';
					if (sel) sel.value = '';
					toggleLink.textContent = '‹ بازگشت به لیست سفارش‌ها';
				} else {
					if (orderRow)   orderRow.style.display   = '';
					if (manualRow)  manualRow.style.display   = 'none';
					if (manualSel) manualSel.value = '';
					toggleLink.textContent = 'لایسنس من در لیست نیست ›';
				}
				hideExtras();
			});
		}

		for (var i = 0; i < devCheckboxes.length; i++) {
			devCheckboxes[i].addEventListener('change', updateBtn);
		}
		if (termsChk) termsChk.addEventListener('change', updateBtn);
	})();
	</script>
	<?php
}
add_action('woocommerce_account_license-request_endpoint', 'spot_extra_render_page');

// ── Form submit handler ──────────────────────────────────────────────────────

function spot_extra_handle_submit(): void {
	if (!isset($_POST['spot_extra_submit'])) return;
	if (!is_user_logged_in()) {
		wp_safe_redirect(wc_get_account_endpoint_url('dashboard'));
		exit;
	}

	$uid      = get_current_user_id();
	$page_url = wc_get_account_endpoint_url('license-request');

	$set_error = static function (string $msg) use ($uid, $page_url): void {
		set_transient('spot_extra_error_' . $uid, $msg, 60);
		wp_safe_redirect($page_url);
		exit;
	};

	if (!isset($_POST['spot_extra_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['spot_extra_nonce'])), 'spot_extra_submit')) {
		$set_error('خطای امنیتی. لطفاً دوباره تلاش کنید.');
	}

	$mode  = sanitize_key($_POST['spot_extra_mode'] ?? 'order');
	$phone = spot_sms_normalize_phone(sanitize_text_field(wp_unslash($_POST['spot_extra_phone'] ?? '')));

	$allowed_devices = ['windows', 'android', 'web'];
	$raw_devices     = isset($_POST['spot_extra_devices']) ? (array) $_POST['spot_extra_devices'] : [];
	$devices         = array_values(array_intersect(array_map('sanitize_key', $raw_devices), $allowed_devices));
	if (empty($devices)) {
		$set_error('لطفاً حداقل یک دستگاه برای غیرفعال‌سازی انتخاب کنید.');
	}
	if (empty($_POST['spot_extra_terms']) || $_POST['spot_extra_terms'] !== '1') {
		$set_error('برای ادامه باید قوانین را بپذیرید.');
	}

	$origin_order = null;
	$fee_label    = '';
	$extra_meta   = ['_spot_extra_request' => '1', '_spot_extra_devices' => implode(',', $devices)];

	if ($mode === 'manual') {
		// ── Manual mode: customer selects course from spot_courses list ──
		$manual_cid = sanitize_text_field($_POST['spot_extra_manual_course'] ?? '');
		if (!$manual_cid) {
			$set_error('لطفاً یک دوره انتخاب کنید.');
		}
		$all_courses = get_option('spot_courses', []);
		$course_name = '';
		foreach ($all_courses as $c) {
			if (($c['id'] ?? '') === $manual_cid) {
				$course_name = $c['name'] ?? $manual_cid;
				break;
			}
		}
		if ($course_name === '') {
			$set_error('دوره انتخاب‌شده معتبر نیست.');
		}

		$manual_count = count(wc_get_orders([
			'customer' => $uid, 'limit' => -1, 'return' => 'ids',
			'status'   => ['processing', 'completed'],
			'meta_query' => [
				'relation' => 'AND',
				['key' => '_spot_extra_request', 'value' => '1'],
				['key' => '_spot_extra_manual',  'value' => '1'],
				['key' => '_spot_extra_manual_course', 'value' => $manual_cid],
			],
		]));

		$sp_opt = get_option('spotplayer', []);
		$cmap   = (array) ($sp_opt['extra_course_stages'] ?? []);
		if (!empty($cmap[$manual_cid]['stages'])) {
			$cfg = ['stages' => array_values($cmap[$manual_cid]['stages']), 'end_mode' => $cmap[$manual_cid]['end_mode'] ?? 'max'];
		} else {
			$cfg = ['stages' => array_values((array) ($sp_opt['extra_stages'] ?? [])), 'end_mode' => $sp_opt['extra_end_mode'] ?? 'max'];
		}
		$calc = spot_extra_calc_price_from_count(0, $manual_count, null, $cfg);
		if ($calc['blocked']) {
			$set_error('به سقف درخواست دسترسی اضافه رسیده‌اید.');
		}

		$fee_label  = 'درخواست دسترسی اضافه — دوره ' . $course_name;
		$extra_meta['_spot_extra_manual']             = '1';
		$extra_meta['_spot_extra_manual_course']      = $manual_cid;
		$extra_meta['_spot_extra_manual_course_name'] = $course_name;
		$extra_meta['_spot_extra_stage']              = (string) $calc['stage'];
	} else {
		// ── Order mode: customer selects from licensed WC orders ──
		$origin_id = absint($_POST['spot_extra_origin_order'] ?? 0);
		if (!$origin_id) {
			$set_error('لطفاً یک سفارش انتخاب کنید.');
		}

		$origin_order = wc_get_order($origin_id);
		if (!($origin_order instanceof WC_Order) || (int) $origin_order->get_customer_id() !== $uid) {
			$set_error('سفارش مورد نظر یافت نشد.');
		}

		$spot_data = $origin_order->get_meta('_spotplayer_data');
		if (empty($spot_data['_id'])) {
			$set_error('این سفارش دارای لایسنس اسپات نیست.');
		}

		$calc = spot_extra_calc_price($origin_id);
		if ($calc['blocked']) {
			$set_error('به سقف درخواست دسترسی اضافه رسیده‌اید.');
		}

		$fee_label = 'درخواست دسترسی اضافه — لایسنس #' . $origin_id;
		$extra_meta['_spot_extra_origin_order'] = (string) $origin_id;
		$extra_meta['_spot_extra_stage']        = (string) $calc['stage'];
	}

	// ── Create order ──
	$customer  = new WC_Customer($uid);
	$new_order = wc_create_order(['customer_id' => $uid]);

	$first = $customer->get_billing_first_name();
	$last  = $customer->get_billing_last_name();
	if (trim($first . $last) === '' && $origin_order instanceof WC_Order) {
		$first = $origin_order->get_billing_first_name();
		$last  = $origin_order->get_billing_last_name();
	}
	$email = $customer->get_billing_email() ?: ($origin_order instanceof WC_Order ? $origin_order->get_billing_email() : '');

	$new_order->set_billing_first_name($first);
	$new_order->set_billing_last_name($last);
	$new_order->set_billing_email($email);
	$new_order->set_billing_phone($phone ?: $customer->get_billing_phone() ?: ($origin_order instanceof WC_Order ? $origin_order->get_billing_phone() : ''));
	$new_order->set_billing_address_1($customer->get_billing_address_1() ?: ($origin_order instanceof WC_Order ? $origin_order->get_billing_address_1() : ''));
	$new_order->set_billing_city($customer->get_billing_city() ?: ($origin_order instanceof WC_Order ? $origin_order->get_billing_city() : ''));
	$new_order->set_billing_country($customer->get_billing_country() ?: ($origin_order instanceof WC_Order ? $origin_order->get_billing_country() : ''));

	$fee = new WC_Order_Item_Fee();
	$fee->set_name($fee_label);
	$fee->set_amount($calc['price']);
	$fee->set_total($calc['price']);
	$fee->set_tax_status('none');
	$new_order->add_item($fee);

	foreach ($extra_meta as $mk => $mv) {
		$new_order->update_meta_data($mk, $mv);
	}

	$new_order->calculate_totals();
	$new_order->save();

	wp_safe_redirect($new_order->get_checkout_payment_url());
	exit;
}
add_action('template_redirect', 'spot_extra_handle_submit');

// ── Helper: count paid extra requests for a given origin order ───────────────

function spot_extra_count_paid_requests(int $origin_order_id): int {
	return count(wc_get_orders([
		'limit'      => -1,
		'return'     => 'ids',
		'status'     => ['processing', 'completed'],
		'meta_query' => [
			'relation' => 'AND',
			['key' => '_spot_extra_request',      'value' => '1'],
			['key' => '_spot_extra_origin_order', 'value' => (string) $origin_order_id],
		],
	]));
}

// ── Helper: check if there's already a pending/on-hold extra request ─────────

function spot_extra_has_pending(int $origin_order_id): bool {
	return count(wc_get_orders([
		'limit'      => 1,
		'return'     => 'ids',
		'status'     => ['pending', 'on-hold'],
		'meta_query' => [
			'relation' => 'AND',
			['key' => '_spot_extra_request',      'value' => '1'],
			['key' => '_spot_extra_origin_order', 'value' => (string) $origin_order_id],
		],
	])) > 0;
}

// ── Helper: sum current prices of products in origin order ───────────────────

function spot_extra_origin_total(int $origin_order_id): float {
	$order = wc_get_order($origin_order_id);
	if (!($order instanceof WC_Order)) return 0.0;
	$total = 0.0;
	foreach ($order->get_items() as $item) {
		if (!($item instanceof WC_Order_Item_Product)) continue;
		$product = $item->get_product();
		if (!($product instanceof WC_Product)) continue;
		$total += floatval($product->get_price()) * $item->get_quantity();
	}
	return $total;
}

// ── Helper: resolve per-course pricing config ─────────────────────────────────

/**
 * Returns ['stages' => [...], 'end_mode' => 'max'|'repeat_last'] for the
 * most specific match: course-specific → global fallback.
 */
function spot_extra_resolve_course_config(?WC_Order $origin_order): array {
	$sp  = get_option('spotplayer', []);
	$map = (array) ($sp['extra_course_stages'] ?? []);

	if ($origin_order && !empty($map)) {
		foreach (spot_woo_order_items($origin_order) as $cid) {
			if (!empty($map[$cid]['stages'])) {
				return [
					'stages'   => array_values($map[$cid]['stages']),
					'end_mode' => $map[$cid]['end_mode'] ?? 'max',
				];
			}
		}
	}

	return [
		'stages'   => array_values((array) ($sp['extra_stages'] ?? [])),
		'end_mode' => $sp['extra_end_mode'] ?? 'max',
	];
}

// ── Helper: calculate price and stage — core logic ───────────────────────────

/**
 * Compute price/stage given a pre-known paid count, optionally a pre-loaded
 * WC_Order, and optionally a pre-resolved config (avoids repeated option reads).
 */
function spot_extra_calc_price_from_count(int $origin_order_id, int $paid_count, ?WC_Order $origin_order = null, ?array $config = null): array {
	if ($config === null) {
		$config = spot_extra_resolve_course_config($origin_order);
	}
	$stages = $config['stages'];
	$mode   = $config['end_mode'];
	$stage  = $paid_count + 1;

	if (empty($stages)) return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];

	if ($stage > count($stages)) {
		if ($mode === 'max') return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];
		$def = end($stages);
	} else {
		$def = $stages[$stage - 1];
	}

	$type  = $def['type']  ?? 'fixed';
	$value = floatval($def['value'] ?? 0);

	if ($type === 'percent') {
		if (!($origin_order instanceof WC_Order)) $origin_order = wc_get_order($origin_order_id);
		$total = 0.0;
		if ($origin_order instanceof WC_Order) {
			foreach ($origin_order->get_items() as $item) {
				if (!($item instanceof WC_Order_Item_Product)) continue;
				$product = $item->get_product();
				if (!($product instanceof WC_Product)) continue;
				$total += floatval($product->get_price()) * $item->get_quantity();
			}
		}
		$price = round($value / 100 * $total);
	} else {
		$price = $value;
	}

	return ['blocked' => false, 'stage' => $stage, 'price' => $price];
}

// ── Helper: calculate price and stage for an origin order ────────────────────

function spot_extra_calc_price(int $origin_order_id): array {
	$origin_order = wc_get_order($origin_order_id);
	$order        = ($origin_order instanceof WC_Order) ? $origin_order : null;
	return spot_extra_calc_price_from_count(
		$origin_order_id,
		spot_extra_count_paid_requests($origin_order_id),
		$order,
		spot_extra_resolve_course_config($order)
	);
}

// ── Daily report: next fire timestamp ────────────────────────────────────────

function spot_extra_next_report_timestamp(string $time): int {
	$tz  = wp_timezone();
	$now = new DateTime('now', $tz);
	$dt  = new DateTime('today ' . $time, $tz);
	if ($dt <= $now) $dt->modify('+1 day');
	return $dt->getTimestamp();
}

// ── Daily report: schedule registration ──────────────────────────────────────

function spot_extra_schedule_daily_report(): void {
	$sp          = get_option('spotplayer', []);
	$report_time = (string) ($sp['extra_report_time'] ?? '08:00');
	if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $report_time)) $report_time = '08:00';

	if (function_exists('as_next_scheduled_action')) {
		if (!as_next_scheduled_action('spot_extra_daily_report')) {
			as_schedule_recurring_action(
				spot_extra_next_report_timestamp($report_time),
				DAY_IN_SECONDS,
				'spot_extra_daily_report'
			);
		}
		return;
	}

	// WP-Cron fallback — no exact-time guarantee
	if (!wp_next_scheduled('spot_extra_daily_report'))
		wp_schedule_event(time(), 'daily', 'spot_extra_daily_report');
}
add_action('init', 'spot_extra_schedule_daily_report');

// ── Daily report: reschedule when report time setting changes ─────────────────

function spot_extra_reschedule_report(array $old_value, array $new_value): void {
	if (($old_value['extra_report_time'] ?? '') === ($new_value['extra_report_time'] ?? '')) return;

	if (function_exists('as_unschedule_all_actions')) {
		as_unschedule_all_actions('spot_extra_daily_report');
	} else {
		$ts = wp_next_scheduled('spot_extra_daily_report');
		if ($ts) wp_unschedule_event($ts, 'spot_extra_daily_report');
	}
	// spot_extra_schedule_daily_report() re-registers on the next init
}
add_action('update_option_spotplayer', 'spot_extra_reschedule_report', 10, 2);

// ── Auto-SMS on payment ───────────────────────────────────────────────────────

add_action('woocommerce_order_status_processing', 'spot_extra_auto_sms_on_payment', 20);
add_action('woocommerce_order_status_completed',  'spot_extra_auto_sms_on_payment', 20);
function spot_extra_auto_sms_on_payment(int $order_id): void {
	$order = wc_get_order($order_id);
	if (!($order instanceof WC_Order)) return;
	if ($order->get_meta('_spot_extra_request') !== '1') return;
	// Only trigger once — if already triggered, skip
	if ($order->get_meta('_spot_sms_msg1_status') !== '') return;

	if (function_exists('spot_sms_trigger_extra')) {
		spot_sms_trigger_extra($order);
	}
}

// ── Daily report: handler ─────────────────────────────────────────────────────

add_action('spot_extra_daily_report', 'spot_extra_handle_daily_report');
function spot_extra_handle_daily_report(): void {
	if (!spot_sms_is_enabled()) return;

	$sp          = get_option('spotplayer', []);
	$admin_phone = spot_sms_normalize_phone((string) ($sp['extra_admin_phone'] ?? ''));
	if (!$admin_phone) return;

	$count = count(wc_get_orders([
		'limit'        => -1,
		'return'       => 'ids',
		'status'       => ['processing', 'completed'],
		'date_created' => (time() - DAY_IN_SECONDS) . '...' . time(),
		'meta_query'   => [['key' => '_spot_extra_request', 'value' => '1']],
	]));

	if ($count === 0) return;

	$site = get_bloginfo('name');
	spot_sms_send_raw(
		$admin_phone,
		'سلام. در ۲۴ ساعت گذشته ' . $count . ' درخواست دسترسی اضافه لایسنس در ' . $site . ' ثبت شد. لطفاً داشبورد را بررسی کنید.'
	);
}

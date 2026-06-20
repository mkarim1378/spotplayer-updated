<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'spot_extra_register_admin_page');
function spot_extra_register_admin_page(): void {
	add_submenu_page(
		'spotplayer',
		'درخواست دسترسی اضافه',
		'دسترسی اضافه',
		'manage_options',
		'spot-extra-access',
		'spot_extra_admin_render'
	);
}

// ── Data fetch ───────────────────────────────────────────────────────────────

/**
 * Returns ['rows' => array, 'total' => int].
 * When no search term: DB-level pagination (fast).
 * When search term present: full fetch + PHP filter (necessary for name/phone search).
 * In both cases origin orders are batch-loaded in a single extra query.
 */
function spot_extra_fetch_requests(string $search, string $date_from, string $date_to, string $status = '', int $paged = 1, int $per_page = 20): array {
	$base_args = [
		'status'     => $status !== '' ? [$status] : 'any',
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => [['key' => '_spot_extra_request', 'value' => '1']],
	];
	if ($date_from !== '') $base_args['date_created'] = $date_from . '...' . ($date_to ?: date('Y-m-d'));
	elseif ($date_to !== '') $base_args['date_created'] = '2000-01-01...' . $date_to;

	if ($search === '') {
		// Fast path: one IDs-only query for count, then one paginated object query
		$all_ids  = wc_get_orders(array_merge($base_args, ['limit' => -1, 'return' => 'ids']));
		$db_total = count($all_ids);
		$page_ids = array_slice($all_ids, ($paged - 1) * $per_page, $per_page);
		if (empty($page_ids)) {
			return ['rows' => [], 'total' => $db_total];
		}
		$fetched = wc_get_orders(['include' => $page_ids, 'limit' => count($page_ids), 'status' => $status !== '' ? [$status] : 'any']);
		// Restore original sort order
		$omap = [];
		foreach ($fetched as $o) $omap[$o->get_id()] = $o;
		$orders = array_values(array_filter(array_map(function ($id) use ($omap) {
			return $omap[$id] ?? null;
		}, $page_ids)));
	} else {
		// Slow path (search): full fetch, PHP filter, then PHP paginate
		$orders   = wc_get_orders(array_merge($base_args, ['limit' => -1]));
		$db_total = null; // computed below after filter
	}

	// Batch-load all unique origin orders in one query
	$origin_ids = array_unique(array_filter(array_map(function ($o) {
		return (int) $o->get_meta('_spot_extra_origin_order');
	}, $orders)));
	$origin_map = [];
	if (!empty($origin_ids)) {
		foreach (wc_get_orders(['include' => $origin_ids, 'limit' => count($origin_ids)]) as $o)
			$origin_map[$o->get_id()] = $o;
	}

	$rows = [];
	foreach ($orders as $order) {
		$origin_id = (int) $order->get_meta('_spot_extra_origin_order');
		$name      = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		$phone     = $order->get_billing_phone();

		if ($search !== '') {
			$haystack = mb_strtolower($name . ' ' . $phone);
			if (mb_strpos($haystack, mb_strtolower($search)) === false) continue;
		}

		$stage        = (int) $order->get_meta('_spot_extra_stage');
		$dt           = $order->get_date_created();
		$origin_order = $origin_map[$origin_id] ?? null;
		$course_names = [];
		if ($origin_order instanceof WC_Order) {
			foreach ($origin_order->get_items() as $item) {
				if ($item instanceof WC_Order_Item_Product)
					$course_names[] = $item->get_name();
			}
		}

		$rows[] = [
			'order'        => $order,
			'order_id'     => $order->get_id(),
			'origin_id'    => $origin_id,
			'stage'        => $stage,
			'name'         => $name,
			'phone'        => $phone,
			'courses'      => implode('، ', $course_names) ?: '—',
			'total'        => $order->get_total(),
			'date'         => $dt ? $dt->date('Y-m-d') : '',
			'status'       => $order->get_status(),
			'devices'      => (string) $order->get_meta('_spot_extra_devices'),
			'msg1_status'  => (string) $order->get_meta('_spot_sms_msg1_status'),
			'msg2_status'  => (string) $order->get_meta('_spot_sms_msg2_status'),
		];
	}

	// Search path: apply PHP pagination and compute total
	if ($search !== '') {
		$db_total = count($rows);
		$rows     = array_slice($rows, ($paged - 1) * $per_page, $per_page);
	}

	return ['rows' => $rows, 'total' => $db_total ?? 0];
}

// ── Order status badge helper ─────────────────────────────────────────────────

function spot_extra_order_badge(string $status): string {
	$map = [
		'completed'  => ['تکمیل‌شده',       'background:#dcfce7;color:#15803d'],
		'processing' => ['در حال پردازش',    'background:#dbeafe;color:#1d4ed8'],
		'pending'    => ['در انتظار پرداخت', 'background:#fef9c3;color:#854d0e'],
		'on-hold'    => ['معلق',              'background:#fef3c7;color:#92400e'],
		'cancelled'  => ['لغو شده',          'background:#f3f4f6;color:#6b7280'],
		'failed'     => ['ناموفق',           'background:#fce8e8;color:#b91c1c'],
		'refunded'   => ['بازگشت وجه',       'background:#f5f3ff;color:#6d28d9'],
	];
	[$label, $style] = $map[$status] ?? [$status, 'background:#f0f0f0;color:#646970'];
	return '<span style="display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600;' . esc_attr($style) . '">' . esc_html($label) . '</span>';
}

// ── SMS status badge helper ──────────────────────────────────────────────────

function spot_extra_sms_badge(string $status): string {
	$map = [
		'sent'      => ['✓ ارسال شد',    'background:#dcfce7;color:#15803d'],
		'pending'   => ['در صف',          'background:#fef9c3;color:#854d0e'],
		'abandoned' => ['✗ ناموفق',       'background:#fce8e8;color:#b91c1c'],
		'failed'    => ['✗ خطا',          'background:#fce8e8;color:#b91c1c'],
		'blocked'   => ['—',              'background:#f0f0f0;color:#646970'],
		''          => ['ارسال نشده',     'background:#f0f0f0;color:#646970'],
	];
	[$label, $style] = $map[$status] ?? ['نامشخص', 'background:#f0f0f0;color:#646970'];
	return '<span style="display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600;' . esc_attr($style) . '">' . esc_html($label) . '</span>';
}

// ── Page render ──────────────────────────────────────────────────────────────

function spot_extra_admin_render(): void {
	if (!current_user_can('manage_options')) return;

	// ── پردازش فرم تنظیمات دسترسی اضافه ─────────────────────────────────────
	$extra_notice = '';
	if (isset($_POST['spot_save_extra']) && check_admin_referer('spot_save_extra', 'spot_extra_nonce')) {
		// Global fallback stages
		$stages = [];
		foreach ((array)($_POST['spot_extra_stages'] ?? []) as $row) {
			$type  = sanitize_key($row['type'] ?? '');
			$value = floatval($row['value'] ?? 0);
			if (!in_array($type, ['fixed', 'percent'], true) || $value <= 0) continue;
			$stages[] = ['type' => $type, 'value' => $value];
		}
		$end_mode = sanitize_key($_POST['spot_extra_end_mode'] ?? 'max');
		if (!in_array($end_mode, ['max', 'repeat_last'], true)) $end_mode = 'max';

		// Per-course stages
		$course_stages = [];
		foreach ((array)($_POST['spot_extra_course_stages'] ?? []) as $raw_cid => $data) {
			$cid = sanitize_text_field($raw_cid);
			if (empty($cid)) continue;
			$cstages = [];
			foreach ((array)($data['stages'] ?? []) as $row) {
				$type  = sanitize_key($row['type'] ?? '');
				$value = floatval($row['value'] ?? 0);
				if (!in_array($type, ['fixed', 'percent'], true) || $value <= 0) continue;
				$cstages[] = ['type' => $type, 'value' => $value];
			}
			$cmode = sanitize_key($data['end_mode'] ?? 'max');
			if (!in_array($cmode, ['max', 'repeat_last'], true)) $cmode = 'max';
			$course_stages[$cid] = ['stages' => $cstages, 'end_mode' => $cmode];
		}

		$sp_opt = get_option('spotplayer', []);
		$sp_opt['extra_stages']        = $stages;
		$sp_opt['extra_end_mode']      = $end_mode;
		$sp_opt['extra_course_stages'] = $course_stages;
		update_option('spotplayer', $sp_opt);

		$extra_notice = '<div class="notice notice-success is-dismissible"><p>✅ تنظیمات دسترسی اضافه ذخیره شد.</p></div>';
	}

	$sp = get_option('spotplayer', []);

	$search    = sanitize_text_field($_GET['search']    ?? '');
	$date_from = sanitize_text_field($_GET['date_from'] ?? '');
	$date_to   = sanitize_text_field($_GET['date_to']   ?? '');
	$status    = sanitize_key($_GET['status']            ?? '');
	$paged     = max(1, intval($_GET['paged'] ?? 1));
	$per_page  = 20;
	$page_url  = admin_url('admin.php?page=spot-extra-access');

	$result      = spot_extra_fetch_requests($search, $date_from, $date_to, $status, $paged, $per_page);
	$page_rows   = $result['rows'];
	$total_items = $result['total'];
	$total_pages = max(1, (int) ceil($total_items / $per_page));
	$paged       = min($paged, $total_pages);
	$sms_enabled = spot_sms_is_enabled();
	?>
	<style>
	.sp-extra-page table th, .sp-extra-page table td { text-align:right !important }
	/* ── Extra pricing settings ── */
	.sp-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px 24px; margin-bottom:20px; }
	.sp-card h2 { margin:0 0 16px; padding:0 0 12px; border-bottom:1px solid #f0f0f1; font-size:15px; }
	.sp-stages-table { width:100%; border-collapse:collapse; margin-bottom:12px; max-width:560px; }
	.sp-stages-table th { text-align:right; padding:6px 8px; background:#f6f7f7; border-bottom:1px solid #dcdcde; font-size:12px; font-weight:600; }
	.sp-stages-table td { padding:5px 8px; border-bottom:1px solid #f0f0f1; vertical-align:middle; }
	.sp-stages-table td select, .sp-stages-table td input[type=number] { width:100%; margin:0; }
	.sp-stage-remove { color:#c00; border-color:#c00; padding:2px 8px; min-height:0; height:26px; line-height:24px; }
	.sp-end-mode-opt { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-radius:3px; background:#f9f9f9; border:1px solid #f0f0f1; margin-bottom:8px; cursor:pointer; }
	.sp-end-mode-opt:last-child { margin-bottom:0; }
	.sp-end-mode-opt input[type=radio] { margin-top:3px; flex-shrink:0; }
	.sp-course-extra { border:1px solid #dcdcde; border-radius:4px; margin-bottom:10px; }
	.sp-course-extra > summary { cursor:pointer; padding:10px 14px; background:#f6f7f7; border-radius:4px; list-style:none; display:flex; align-items:center; gap:8px; }
	.sp-course-extra > summary::-webkit-details-marker { display:none; }
	.sp-course-extra > summary::before { content:'▶'; font-size:10px; margin-left:4px; transition:transform .15s; display:inline-block; }
	.sp-course-extra[open] > summary::before { transform:rotate(90deg); }
	.sp-course-extra-body { padding:14px 16px 18px; }
	.sp-extra-fallback { border-style:dashed; margin-top:16px; }
	</style>
	<div class="wrap sp-extra-page">

	<?= $extra_notice ?>

	<!-- ── تنظیمات قیمت‌گذاری دسترسی اضافه ── -->
	<details style="border:1px solid #c3c4c7;border-radius:4px;margin-bottom:24px;background:#fff">
		<summary style="cursor:pointer;padding:14px 20px;background:#f6f7f7;border-radius:4px;list-style:none;display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px">
			💳 تنظیمات قیمت‌گذاری دسترسی اضافه
		</summary>
		<div style="padding:20px 24px">
			<p style="margin-top:0;color:#646970;font-size:13px">برای هر دوره پله‌های قیمت جداگانه تعریف کنید. اگر دوره‌ای پله‌ای نداشته باشد، از بخش «پیش‌فرض» در انتها استفاده می‌شود.</p>

			<form method="post">
				<?php wp_nonce_field('spot_save_extra', 'spot_extra_nonce') ?>
				<input type="hidden" name="spot_save_extra" value="1">

				<?php
				$all_courses   = get_option('spot_courses', []);
				$course_stages = $sp['extra_course_stages'] ?? [];

				if (empty($all_courses)): ?>
					<div class="notice notice-warning inline"><p>ابتدا دوره‌ها را در تنظیمات > دوره‌ها تعریف کنید.</p></div>
				<?php else:
					foreach ($all_courses as $course):
						$cid    = $course['id']   ?? '';
						$cname  = $course['name'] ?? $cid;
						$cdata  = $course_stages[$cid] ?? [];
						$cstages = $cdata['stages']   ?? [];
						$cmode   = $cdata['end_mode'] ?? 'max';
						$has_stages = !empty($cstages);
				?>
				<details class="sp-course-extra" <?= $has_stages ? 'open' : '' ?>>
					<summary>
						<strong><?= esc_html($cname) ?></strong>
						<small style="color:#646970;font-family:monospace;font-size:11px"><?= esc_html($cid) ?></small>
						<?php if ($has_stages): ?>
							<span style="color:#2a7a2a;font-size:11px;font-weight:600"><?= count($cstages) ?> پله</span>
						<?php else: ?>
							<span style="color:#999;font-size:11px">بدون پله — از پیش‌فرض استفاده می‌شود</span>
						<?php endif; ?>
					</summary>
					<div class="sp-course-extra-body">
						<table class="sp-stages-table">
							<thead><tr>
								<th style="width:12%">پله</th>
								<th style="width:40%">نوع</th>
								<th style="width:33%">مقدار</th>
								<th style="width:15%"></th>
							</tr></thead>
							<tbody class="sp-stages-tbody" data-cid="<?= esc_attr($cid) ?>">
							<?php foreach ($cstages as $si => $stage): ?>
								<tr>
									<td style="text-align:center;font-weight:600;color:#646970"><?= $si + 1 ?></td>
									<td>
										<select name="spot_extra_course_stages[<?= esc_attr($cid) ?>][stages][<?= $si ?>][type]">
											<option value="fixed"   <?= ($stage['type'] ?? '') === 'fixed'   ? 'selected' : '' ?>>مبلغ ثابت (تومان)</option>
											<option value="percent" <?= ($stage['type'] ?? '') === 'percent' ? 'selected' : '' ?>>درصد از مجموع سفارش</option>
										</select>
									</td>
									<td><input type="number" name="spot_extra_course_stages[<?= esc_attr($cid) ?>][stages][<?= $si ?>][value]" value="<?= esc_attr($stage['value'] ?? '') ?>" min="0" step="any" placeholder="مثلاً 500000"></td>
									<td><button type="button" class="button sp-stage-remove">✕</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<button type="button" class="button sp-add-stage-course">+ افزودن پله</button>

						<hr style="margin:14px 0;border:none;border-top:1px solid #f0f0f1">
						<p style="font-weight:600;margin:0 0 8px;font-size:13px">رفتار بعد از آخرین پله</p>
						<label class="sp-end-mode-opt">
							<input type="radio" name="spot_extra_course_stages[<?= esc_attr($cid) ?>][end_mode]" value="max" <?= $cmode === 'max' ? 'checked' : '' ?>>
							<div><span style="font-weight:600;display:block;margin-bottom:2px">حداکثر N بار</span><span style="color:#646970;font-size:12px">بعد از آخرین پله، ثبت درخواست جدید ممکن نخواهد بود.</span></div>
						</label>
						<label class="sp-end-mode-opt">
							<input type="radio" name="spot_extra_course_stages[<?= esc_attr($cid) ?>][end_mode]" value="repeat_last" <?= $cmode === 'repeat_last' ? 'checked' : '' ?>>
							<div><span style="font-weight:600;display:block;margin-bottom:2px">تکرار قیمت پله آخر</span><span style="color:#646970;font-size:12px">از پله آخر به بعد همان مبلغ اعمال می‌شود.</span></div>
						</label>
					</div>
				</details>
				<?php endforeach; endif; ?>

				<!-- ── پیش‌فرض (fallback) ── -->
				<details class="sp-course-extra sp-extra-fallback">
					<summary>
						<strong>پیش‌فرض</strong>
						<small style="color:#646970;font-size:11px">برای سفارش‌هایی که دوره‌شان پله تعریف‌شده ندارد</small>
					</summary>
					<div class="sp-course-extra-body">
						<table class="sp-stages-table">
							<thead><tr>
								<th style="width:12%">پله</th><th style="width:40%">نوع</th><th style="width:33%">مقدار</th><th style="width:15%"></th>
							</tr></thead>
							<tbody id="sp-stages-tbody">
							<?php
							$saved_stages = $sp['extra_stages'] ?? [];
							foreach ($saved_stages as $si => $stage): ?>
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
							<?php endforeach; ?>
							</tbody>
						</table>
						<button type="button" id="sp-add-stage" class="button">+ افزودن پله</button>
						<hr style="margin:14px 0;border:none;border-top:1px solid #f0f0f1">
						<p style="font-weight:600;margin:0 0 8px;font-size:13px">رفتار بعد از آخرین پله</p>
						<?php $end_mode_val = $sp['extra_end_mode'] ?? 'max'; ?>
						<label class="sp-end-mode-opt">
							<input type="radio" name="spot_extra_end_mode" value="max" <?= $end_mode_val === 'max' ? 'checked' : '' ?>>
							<div><span style="font-weight:600;display:block;margin-bottom:2px">حداکثر N بار</span><span style="color:#646970;font-size:12px">بعد از آخرین پله، ثبت درخواست جدید ممکن نخواهد بود.</span></div>
						</label>
						<label class="sp-end-mode-opt">
							<input type="radio" name="spot_extra_end_mode" value="repeat_last" <?= $end_mode_val === 'repeat_last' ? 'checked' : '' ?>>
							<div><span style="font-weight:600;display:block;margin-bottom:2px">تکرار قیمت پله آخر</span><span style="color:#646970;font-size:12px">از پله آخر به بعد همان مبلغ اعمال می‌شود.</span></div>
						</label>
					</div>
				</details>

				<p style="margin-top:20px">
					<?php submit_button('ذخیره تنظیمات دسترسی اضافه', 'primary', 'spot_extra_submit', false) ?>
				</p>
			</form>
		</div>
	</details>

	<script>
	(function () {
		function reindex(tbody) {
			var cid = tbody.dataset.cid || null;
			tbody.querySelectorAll('tr').forEach(function (tr, i) {
				tr.querySelector('td:first-child').textContent = i + 1;
				if (cid) {
					tr.querySelector('select').name = 'spot_extra_course_stages[' + cid + '][stages][' + i + '][type]';
					tr.querySelector('input[type=number]').name = 'spot_extra_course_stages[' + cid + '][stages][' + i + '][value]';
				} else {
					tr.querySelector('select').name = 'spot_extra_stages[' + i + '][type]';
					tr.querySelector('input[type=number]').name = 'spot_extra_stages[' + i + '][value]';
				}
			});
		}

		function makeRow(tbody) {
			var cid = tbody.dataset.cid || null;
			var i   = tbody.querySelectorAll('tr').length;
			var tr  = document.createElement('tr');
			var sName = cid
				? 'spot_extra_course_stages[' + cid + '][stages][' + i + '][type]'
				: 'spot_extra_stages[' + i + '][type]';
			var vName = cid
				? 'spot_extra_course_stages[' + cid + '][stages][' + i + '][value]'
				: 'spot_extra_stages[' + i + '][value]';
			tr.innerHTML =
				'<td style="text-align:center;font-weight:600;color:#646970">' + (i + 1) + '</td>'
				+ '<td><select name="' + sName + '">'
				+ '<option value="fixed">مبلغ ثابت (تومان)</option>'
				+ '<option value="percent">درصد از مجموع سفارش</option>'
				+ '</select></td>'
				+ '<td><input type="number" name="' + vName + '" min="0" step="any" placeholder="مثلاً 500000"></td>'
				+ '<td><button type="button" class="button sp-stage-remove">✕</button></td>';
			return tr;
		}

		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.sp-stage-remove');
			if (!btn) return;
			var tbody = btn.closest('tbody');
			btn.closest('tr').remove();
			if (tbody) reindex(tbody);
		});

		document.querySelectorAll('.sp-add-stage-course').forEach(function (addBtn) {
			addBtn.addEventListener('click', function () {
				var tbody = addBtn.closest('.sp-course-extra-body').querySelector('tbody.sp-stages-tbody');
				if (tbody) tbody.appendChild(makeRow(tbody));
			});
		});

		var globalAdd   = document.getElementById('sp-add-stage');
		var globalTbody = document.getElementById('sp-stages-tbody');
		if (globalAdd && globalTbody) {
			globalAdd.addEventListener('click', function () {
				globalTbody.appendChild(makeRow(globalTbody));
			});
		}
	})();
	</script>

	<h1 style="margin-bottom:12px">📋 درخواست‌های دسترسی اضافه</h1>

	<?php if (!$sms_enabled): ?>
	<div class="notice notice-warning inline" style="margin-bottom:12px"><p>⚠️ سرویس پیامک فعال نیست — دکمه ارسال پیامک نمایش داده نخواهد شد. <a href="<?= esc_url(admin_url('admin.php?page=spot-sms-report')) ?>">تنظیمات پیامک</a></p></div>
	<?php endif; ?>

	<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
		<input type="hidden" name="page" value="spot-extra-access">
		<input type="search" name="search" value="<?= esc_attr($search) ?>"
		       placeholder="نام یا شماره..." style="width:200px">
		<select name="status" style="min-width:160px">
			<option value="">همه وضعیت‌ها</option>
			<?php foreach (wc_get_order_statuses() as $s_key => $s_label):
				$s_val = str_replace('wc-', '', $s_key); ?>
				<option value="<?= esc_attr($s_val) ?>" <?= $status === $s_val ? 'selected' : '' ?>><?= esc_html($s_label) ?></option>
			<?php endforeach; ?>
		</select>
		<label style="font-size:13px">از:
			<input type="date" name="date_from" value="<?= esc_attr($date_from) ?>" style="margin-right:4px">
		</label>
		<label style="font-size:13px">تا:
			<input type="date" name="date_to" value="<?= esc_attr($date_to) ?>" style="margin-right:4px">
		</label>
		<button type="submit" class="button">فیلتر</button>
		<?php if ($search !== '' || $date_from !== '' || $date_to !== '' || $status !== ''): ?>
			<a href="<?= esc_url($page_url) ?>" class="button">× پاک کردن</a>
		<?php endif; ?>
		<span style="color:#646970;font-size:12px;margin-right:8px"><?= $total_items ?> درخواست</span>
	</form>

	<?php if (empty($page_rows)): ?>
		<p style="color:#646970;padding:12px 0">هیچ درخواستی یافت نشد.</p>
	<?php else: ?>

	<?php
	$device_labels = ['windows' => 'ویندوز', 'android' => 'اندروید', 'web' => 'وب (آیفون)'];
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th style="width:7%">شماره</th>
			<th style="width:12%">نام مشتری</th>
			<th style="width:10%">موبایل</th>
			<th style="width:13%">دوره‌ها</th>
			<th style="width:5%">مرحله</th>
			<th style="width:7%">مبلغ</th>
			<th style="width:10%">وضعیت</th>
			<th style="width:12%">دستگاه</th>
			<th style="width:7%">تاریخ</th>
			<th style="width:9%">پیامک</th>
			<th style="width:8%">عملیات</th>
		</tr></thead>
		<tbody>
		<?php if (empty($page_rows)): ?>
			<tr><td colspan="11" style="text-align:center;color:#646970;padding:24px">موردی یافت نشد.</td></tr>
		<?php else: foreach ($page_rows as $r):
			$devs = array_filter(explode(',', $r['devices']));
			$dev_badges = [];
			foreach ($devs as $d) {
				if (isset($device_labels[$d])) $dev_badges[] = '<span style="display:inline-block;padding:1px 6px;border-radius:3px;font-size:11px;background:#f3f4f6;border:1px solid #d1d5db">' . esc_html($device_labels[$d]) . '</span>';
			}
		?>
			<tr>
				<td>
					<a href="<?= esc_url($r['order']->get_edit_order_url()) ?>">#<?= $r['order_id'] ?></a>
					<?php if ($r['origin_id']): ?>
						<br><small style="color:#646970">← #<?= $r['origin_id'] ?></small>
					<?php endif; ?>
				</td>
				<td><?= esc_html($r['name']) ?></td>
				<td dir="ltr"><a href="tel:<?= esc_attr(preg_replace('/[^0-9+]/', '', $r['phone'])) ?>"><?= esc_html($r['phone']) ?></a></td>
				<td><?= esc_html($r['courses']) ?></td>
				<td style="text-align:center"><?= esc_html($r['stage']) ?></td>
				<td><?= wc_price($r['total']) ?></td>
				<td><?= spot_extra_order_badge($r['status']) ?></td>
				<td><?= !empty($dev_badges) ? implode(' ', $dev_badges) : '<span style="color:#9ca3af">—</span>' ?></td>
				<td><?= esc_html($r['date']) ?></td>
				<td>
					<div><?= spot_extra_sms_badge($r['msg1_status']) ?></div>
					<div style="margin-top:2px"><?= spot_extra_sms_badge($r['msg2_status']) ?></div>
				</td>
				<td style="white-space:nowrap">
					<?php if ($sms_enabled && in_array($r['status'], ['processing', 'completed'], true)): ?>
					<button type="button" class="button button-small spot-extra-sms-btn"
					        data-order="<?= esc_attr($r['order_id']) ?>"
					        data-nonce="<?= esc_attr(wp_create_nonce('spot_extra_sms_' . $r['order_id'])) ?>">
						📲 ارسال پیامک
					</button>
					<?php else: ?>—<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>

	<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px">
		<p style="margin:0;color:#646970;font-size:12px">
			نمایش <?= count($page_rows) ?> از <?= $total_items ?> درخواست
		</p>
		<?php if ($total_pages > 1): ?>
		<div style="display:flex;gap:4px;flex-wrap:wrap">
			<?php for ($p = 1; $p <= $total_pages; $p++): ?>
				<a href="<?= esc_url(add_query_arg(['paged' => $p, 'search' => $search, 'status' => $status, 'date_from' => $date_from, 'date_to' => $date_to], $page_url)) ?>"
				   class="button button-small"
				   style="<?= $p === $paged ? 'font-weight:700;background:#2271b1;color:#fff;border-color:#2271b1' : '' ?>"><?= $p ?></a>
			<?php endfor; ?>
		</div>
		<?php endif; ?>
	</div>

	<?php endif; ?>
	</div>

	<script>
	(function () {
		var ajax = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.spot-extra-sms-btn');
			if (!btn || btn.disabled) return;
			var orig = btn.textContent;
			btn.disabled = true;
			btn.textContent = '…';
			var fd = new FormData();
			fd.append('action',   'spot_send_extra_sms');
			fd.append('order_id', btn.dataset.order);
			fd.append('nonce',    btn.dataset.nonce);
			fetch(ajax, {method: 'POST', body: fd})
				.then(function (r) { return r.json(); })
				.then(function (r) {
					btn.disabled = false;
					btn.textContent = r.success ? '✓ در صف ارسال' : ('✗ ' + (typeof r.data === 'string' ? r.data : 'خطا'));
					setTimeout(function () { btn.textContent = orig; }, 4000);
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = '✗ خطای شبکه';
					setTimeout(function () { btn.textContent = orig; }, 4000);
				});
		});
	})();
	</script>
	<?php
}

// ── AJAX: trigger SMS for an extra-access request ────────────────────────────

add_action('wp_ajax_spot_send_extra_sms', 'spot_ajax_send_extra_sms');
function spot_ajax_send_extra_sms(): void {
	if (!current_user_can('manage_options')) {
		wp_send_json_error('unauthorized');
	}

	$order_id = absint($_POST['order_id'] ?? 0);
	check_ajax_referer('spot_extra_sms_' . $order_id, 'nonce');

	$order = $order_id ? wc_get_order($order_id) : null;
	if (!($order instanceof WC_Order) || $order->get_meta('_spot_extra_request') !== '1') {
		wp_send_json_error('سفارش یافت نشد.');
	}

	if (!function_exists('spot_sms_trigger_extra')) {
		wp_send_json_error('تابع ارسال پیامک هنوز پیکربندی نشده.');
	}

	spot_sms_trigger_extra($order);
	wp_send_json_success();
}

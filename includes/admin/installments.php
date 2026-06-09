<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'spot_installments_register_page');
function spot_installments_register_page(): void {
	add_submenu_page(
		'spotplayer',
		'مدیریت اقساط',
		'مدیریت اقساط',
		'manage_options',
		'spot-installments',
		'spot_installments_render'
	);
}

// ── CSV export — must run before any output ──────────────────────────────────

add_action('admin_init', 'spot_installments_maybe_export');
function spot_installments_maybe_export(): void {
	if (
		!is_admin() ||
		($_GET['page'] ?? '') !== 'spot-installments' ||
		!isset($_GET['spot_export_installments'])
	) return;
	if (!current_user_can('manage_options')) wp_die('Unauthorized');
	check_admin_referer('spot_export_inst');

	$filter  = sanitize_key($_GET['filter'] ?? 'all');
	$search  = sanitize_text_field($_GET['search'] ?? '');
	$entries = spot_installments_apply_filter(spot_installments_fetch_all(), $filter, $search);

	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="installments-' . date('Y-m-d') . '.csv"');
	header('Pragma: no-cache');
	header('Expires: 0');

	echo chr(0xEF) . chr(0xBB) . chr(0xBF); // UTF-8 BOM for Excel
	$out = fopen('php://output', 'w');
	fputcsv($out, ['نام مشتری', 'شماره تماس', 'دوره', 'قسط', 'کل اقساط', 'تاریخ پرداخت', 'سررسید بعدی', 'وضعیت', 'شناسه سفارش']);
	foreach ($entries as $e) {
		fputcsv($out, [
			$e['name'], $e['phone'], $e['course'],
			$e['number'], $e['total'], $e['order_date'], $e['due'],
			spot_installments_status_label($e['status']), $e['order_id'],
		]);
	}
	fclose($out);
	exit;
}

// ── Dashboard data helpers ────────────────────────────────────────────────────

function spot_installments_fetch_ids(): array {
	global $wpdb;
	$ids = $wpdb->get_col($wpdb->prepare(
		"SELECT DISTINCT oi.order_id
		 FROM {$wpdb->prefix}woocommerce_order_items oi
		 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
		     ON oi.order_item_id = oim.order_item_id
		 WHERE oim.meta_key = %s AND CAST(oim.meta_value AS SIGNED) >= 1",
		'_spot_installment_number'
	));
	return array_map('intval', $ids ?: []);
}

function spot_installments_fetch_all(): array {
	$ids = spot_installments_fetch_ids();
	if (empty($ids)) return [];

	$orders = wc_get_orders([
		'include' => $ids,
		'status'  => ['processing', 'completed'],
		'limit'   => -1,
		'orderby' => 'date',
		'order'   => 'DESC',
	]);

	$course_by_id = array_column(get_option('spot_courses', []), 'name', 'id');
	$today        = date('Y-m-d');
	$in3          = date('Y-m-d', strtotime('+3 days'));
	$entries      = [];

	foreach ($orders as $order) {
		foreach ($order->get_items() as $item) {
			if (!($item instanceof WC_Order_Item_Product)) continue;
			$number = intval($item->get_meta('_spot_installment_number'));
			if ($number < 1) continue;

			$total = intval($item->get_meta('_spot_installment_total'));
			$due   = (string) $item->get_meta('_spot_installment_due');

			$course_name = '';
			$product = $item->get_product();
			if ($product instanceof WC_Product) {
				$cids = array_filter(explode(',', (string) $product->get_meta('_spotplayer_course')));
				$cid  = reset($cids);
				if ($cid) $course_name = $course_by_id[$cid] ?? $cid;
			}

			if ($number >= $total) {
				$status = 'complete';
			} elseif ($due === '') {
				$status = 'normal';
			} elseif ($due < $today) {
				$status = 'overdue';
			} elseif ($due <= $in3) {
				$status = 'near';
			} else {
				$status = 'normal';
			}

			$dt = $order->get_date_created();

			$entries[] = [
				'order'      => $order,
				'order_id'   => $order->get_id(),
				'name'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
				'phone'      => $order->get_billing_phone(),
				'course'     => $course_name,
				'number'     => $number,
				'total'      => $total,
				'due'        => $due,
				'order_date' => $dt ? $dt->date('Y-m-d') : '',
				'status'     => $status,
			];
		}
	}

	return $entries;
}

function spot_installments_apply_filter(array $all, string $filter, string $search): array {
	$today = date('Y-m-d');
	$in7   = date('Y-m-d', strtotime('+7 days'));
	$out   = [];
	foreach ($all as $e) {
		if ($filter === 'overdue'  && $e['status'] !== 'overdue') continue;
		if ($filter === 'complete' && $e['status'] !== 'complete') continue;
		if ($filter === 'week') {
			$soon = $e['status'] !== 'complete' && $e['due'] !== '' && $e['due'] >= $today && $e['due'] <= $in7;
			if (!$soon) continue;
		}
		if ($search !== '' && mb_strpos(mb_strtolower($e['name'] . ' ' . $e['phone']), mb_strtolower($search)) === false) continue;
		$out[] = $e;
	}
	return $out;
}

function spot_installments_status_label(string $status): string {
	$map = ['complete' => 'تکمیل‌شده', 'overdue' => 'سررسید گذشته', 'near' => 'نزدیک سررسید', 'normal' => 'عادی'];
	return $map[$status] ?? $status;
}

// ── Migration Wizard helpers ─────────────────────────────────────────────────

function spot_migration_extract_installment(string $text): int {
	if (preg_match('/(?:^|\s)قسط\s*([0-9]+)/u', $text, $m)) return intval($m[1]);
	if (preg_match('/(?:^|\s)ق\s*([0-9]+)/u',    $text, $m)) return intval($m[1]);
	return 0;
}

function spot_migration_scan_billing(): array {
	$orders = wc_get_orders([
		'meta_query' => [['key' => '_spotplayer_data', 'compare' => 'EXISTS']],
		'status'     => ['processing', 'completed'],
		'limit'      => -1,
	]);

	$results = [];
	foreach ($orders as $order) {
		$data = $order->get_meta('_spotplayer_data');
		if (empty($data['_id'])) continue;

		$full_name    = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		$license_name = (string) ($data['name'] ?? '');

		$num = spot_migration_extract_installment($full_name)
		    ?: spot_migration_extract_installment($license_name);
		if ($num < 1) continue;

		$courses = spot_woo_order_items($order);
		$cid     = count($courses) === 1 ? $courses[0] : '';

		$by_id      = array_column(get_option('spot_courses', []), 'name', 'id');
		$course_name = $cid ? ($by_id[$cid] ?? $cid) : '';

		$results[] = [
			'license_id'   => $data['_id'],
			'order_id'     => $order->get_id(),
			'customer'     => $full_name,
			'phone'        => $order->get_billing_phone(),
			'inst_number'  => $num,
			'inst_total'   => 0,
			'course_id'    => $cid,
			'course_name'  => $course_name,
			'source'       => 'billing',
		];
	}

	return $results;
}

// ── Migration Wizard AJAX ─────────────────────────────────────────────────────

add_action('wp_ajax_spot_migration_scan', 'spot_ajax_migration_scan');
function spot_ajax_migration_scan(): void {
	if (!current_user_can('manage_options')) { wp_send_json_error('unauthorized'); return; }
	check_ajax_referer('spot_migration', 'nonce');

	$phase = sanitize_key($_POST['phase'] ?? 'billing');
	$page  = max(1, intval($_POST['page'] ?? 1));
	$per   = 10;

	if ($phase === 'billing') {
		wp_send_json_success(['results' => spot_migration_scan_billing(), 'has_more' => false]);
		return;
	}

	// API phase: paginated scan of all orders with a SpotPlayer license
	$all_ids = wc_get_orders([
		'meta_query' => [['key' => '_spotplayer_data', 'compare' => 'EXISTS']],
		'status'     => ['processing', 'completed'],
		'limit'      => -1,
		'return'     => 'ids',
	]);
	$total  = count($all_ids);
	$offset = ($page - 1) * $per;
	$batch  = array_slice($all_ids, $offset, $per);

	$by_id = array_column(get_option('spot_courses', []), 'name', 'id');

	$results = [];
	foreach ($batch as $order_id) {
		$order = wc_get_order($order_id);
		if (!$order) continue;
		$data = $order->get_meta('_spotplayer_data');
		if (empty($data['_id'])) continue;

		try {
			$lic  = spot_request_license_get($data['_id']);
		} catch (Exception $e) {
			continue;
		}

		$lic_name = (string) ($lic['name'] ?? '');
		$num      = spot_migration_extract_installment($lic_name);
		if ($num < 1) continue;

		$courses     = spot_woo_order_items($order);
		$cid         = count($courses) === 1 ? $courses[0] : '';
		$course_name = $cid ? ($by_id[$cid] ?? $cid) : '';
		$full_name   = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

		$results[] = [
			'license_id'  => $data['_id'],
			'order_id'    => intval($order_id),
			'customer'    => $full_name,
			'phone'       => $order->get_billing_phone(),
			'inst_number' => $num,
			'inst_total'  => 0,
			'course_id'   => $cid,
			'course_name' => $course_name,
			'source'      => 'api',
		];
	}

	wp_send_json_success([
		'results'   => $results,
		'has_more'  => ($offset + $per) < $total,
		'next_page' => $page + 1,
		'progress'  => ['done' => min($offset + $per, $total), 'total' => $total],
	]);
}

add_action('wp_ajax_spot_migration_apply', 'spot_ajax_migration_apply');
function spot_ajax_migration_apply(): void {
	if (!current_user_can('manage_options')) { wp_send_json_error('unauthorized'); return; }
	check_ajax_referer('spot_migration', 'nonce');

	$items = json_decode(wp_unslash($_POST['action_items'] ?? ''), true);
	if (!is_array($items)) { wp_send_json_error('داده‌های نامعتبر'); return; }

	$results = [];

	foreach ($items as $item) {
		$license_id  = sanitize_text_field($item['license_id'] ?? '');
		$order_id    = intval($item['order_id'] ?? 0);
		$course_id   = sanitize_text_field($item['course_id'] ?? '');
		$limit       = preg_replace('/[^0-9\-]/', '', (string) ($item['limit'] ?? ''));
		$inst_number = intval($item['inst_number'] ?? 0);
		$inst_total  = intval($item['inst_total'] ?? 0);

		if (!$license_id || $inst_number < 1) {
			$results[] = ['license_id' => $license_id, 'ok' => false, 'error' => 'داده‌های ناقص'];
			continue;
		}

		try {
			// 1. Update SpotPlayer license limit
			if ($course_id && $limit !== '') {
				spot_request(
					'https://panel.spotplayer.ir/license/edit/' . $license_id,
					['data' => ['limit' => [$course_id => $limit]]]
				);
			}

			// 2. Save installment item meta on the WooCommerce order
			if ($order_id > 0) {
				$order = wc_get_order($order_id);
				if ($order) {
					$target = null;
					foreach ($order->get_items() as $item_obj) {
						if (!($item_obj instanceof WC_Order_Item_Product)) continue;
						$product = $item_obj->get_product();
						if (!($product instanceof WC_Product)) continue;
						$cids = array_filter(explode(',', (string) $product->get_meta('_spotplayer_course')));
						if ($course_id && in_array($course_id, $cids, true)) { $target = $item_obj; break; }
						if (!$target) $target = $item_obj;
					}

					if ($target) {
						$due = '';
						if ($inst_total > 0 && $inst_number < $inst_total && $course_id) {
							foreach (get_option('spot_courses', []) as $c) {
								if ($c['id'] !== $course_id || empty($c['installments'])) continue;
								$pi = $c['installments'][$inst_number - 1] ?? null;
								if ($pi && intval($pi['days'] ?? 0) > 0) {
									$dt = $order->get_date_created();
									if ($dt) $due = date('Y-m-d', $dt->getTimestamp() + intval($pi['days']) * DAY_IN_SECONDS);
								}
								break;
							}
						}
						$target->update_meta_data('_spot_installment_number', $inst_number);
						$target->update_meta_data('_spot_installment_total',  $inst_total ?: $inst_number);
						$target->update_meta_data('_spot_installment_limit',  $limit);
						$target->update_meta_data('_spot_installment_days',   0);
						$target->update_meta_data('_spot_installment_due',    $due);
						$target->save();
					}
				}
			}

			$results[] = ['license_id' => $license_id, 'ok' => true];
		} catch (Exception $e) {
			$results[] = ['license_id' => $license_id, 'ok' => false, 'error' => $e->getMessage()];
		}
	}

	wp_send_json_success($results);
}

// ── Page render ──────────────────────────────────────────────────────────────

function spot_installments_render(): void {
	if (!current_user_can('manage_options')) return;

	$filter   = sanitize_key($_GET['filter'] ?? 'all');
	$search   = sanitize_text_field($_GET['search'] ?? '');
	$paged    = max(1, intval($_GET['paged'] ?? 1));
	$per_page = 50;
	$page_url = admin_url('admin.php?page=spot-installments');

	$all   = spot_installments_fetch_all();
	$today = date('Y-m-d');
	$in7   = date('Y-m-d', strtotime('+7 days'));

	$counts = ['all' => count($all), 'overdue' => 0, 'week' => 0, 'complete' => 0];
	foreach ($all as $e) {
		if ($e['status'] === 'overdue') $counts['overdue']++;
		if ($e['status'] === 'complete') $counts['complete']++;
		if ($e['status'] !== 'complete' && $e['due'] !== '' && $e['due'] >= $today && $e['due'] <= $in7) $counts['week']++;
	}

	$filtered    = spot_installments_apply_filter($all, $filter, $search);
	$total_items = count($filtered);
	$total_pages = max(1, (int) ceil($total_items / $per_page));
	$paged       = min($paged, $total_pages);
	$rows        = array_slice($filtered, ($paged - 1) * $per_page, $per_page);

	$badge = [
		'complete' => 'background:#f0f0f0;color:#646970',
		'overdue'  => 'background:#fce8e8;color:#b91c1c',
		'near'     => 'background:#fef3cd;color:#92400e',
		'normal'   => 'background:#dcfce7;color:#15803d',
	];
	$row_tint = ['overdue' => '#fff5f5', 'near' => '#fffcf0'];
	?>
	<style>
	.sp-inst-page table th, .sp-inst-page table td { text-align:right !important }
	.sp-inst-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600 }
	</style>
	<div class="wrap sp-inst-page">

	<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
		<h1 style="margin:0">📋 مدیریت اقساط</h1>
		<?php if ($total_items > 0): ?>
		<a href="<?= esc_url(wp_nonce_url(add_query_arg(
			['spot_export_installments' => 1, 'filter' => $filter, 'search' => $search], $page_url
		), 'spot_export_inst')) ?>" class="button">⬇ خروجی CSV</a>
		<?php endif; ?>
	</div>

	<?php if (!spot_sms_is_enabled()): ?>
	<div class="notice notice-warning inline" style="margin-bottom:12px"><p>⚠️ سرویس پیامک فعال نیست — دکمه ارسال یادآور نمایش داده نخواهد شد. <a href="<?= esc_url(admin_url('admin.php?page=spotplayer')) ?>">تنظیمات پیامک</a></p></div>
	<?php endif; ?>

	<ul class="subsubsub" style="margin-bottom:8px">
	<?php
	$tabs  = ['all' => 'همه', 'overdue' => 'سررسید گذشته', 'week' => 'این هفته', 'complete' => 'تکمیل‌شده'];
	$parts = [];
	foreach ($tabs as $k => $lbl) {
		$url   = esc_url(add_query_arg(['filter' => $k, 'paged' => 1, 'search' => $search], $page_url));
		$cls   = $filter === $k ? ' class="current"' : '';
		$parts[] = '<li><a href="' . $url . '"' . $cls . '>' . $lbl . ' <span class="count">(' . $counts[$k] . ')</span></a></li>';
	}
	echo implode(' | ', $parts);
	?>
	</ul>

	<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
		<input type="hidden" name="page" value="spot-installments">
		<input type="hidden" name="filter" value="<?= esc_attr($filter) ?>">
		<input type="search" name="search" value="<?= esc_attr($search) ?>" placeholder="جستجو بر اساس نام یا شماره..." style="width:240px">
		<button type="submit" class="button">جستجو</button>
		<?php if ($search !== ''): ?>
			<a href="<?= esc_url(add_query_arg(['filter' => $filter, 'paged' => 1, 'search' => ''], $page_url)) ?>" class="button">× پاک کردن</a>
		<?php endif; ?>
	</form>

	<?php if (empty($all)): ?>
		<p style="color:#646970;padding:12px 0">هنوز سفارشی با تنظیمات قسطی ثبت نشده است.</p>
	<?php else: ?>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th style="width:16%">نام مشتری</th>
			<th style="width:13%">شماره تماس</th>
			<th style="width:17%">دوره</th>
			<th style="width:9%">قسط</th>
			<th style="width:10%">آخرین پرداخت</th>
			<th style="width:10%">سررسید بعدی</th>
			<th style="width:11%">وضعیت</th>
			<th style="width:14%">عملیات</th>
		</tr></thead>
		<tbody>
		<?php if (empty($rows)): ?>
			<tr><td colspan="8" style="text-align:center;color:#646970;padding:24px">موردی یافت نشد.</td></tr>
		<?php else: foreach ($rows as $e):
			$tint = $row_tint[$e['status']] ?? '';
		?>
			<tr<?= $tint ? ' style="background:' . esc_attr($tint) . '"' : '' ?>>
				<td><?= esc_html($e['name']) ?></td>
				<td dir="ltr"><a href="tel:<?= esc_attr(preg_replace('/[^0-9+]/', '', $e['phone'])) ?>"><?= esc_html($e['phone']) ?></a></td>
				<td><?= esc_html($e['course']) ?></td>
				<td>قسط <?= intval($e['number']) ?> از <?= intval($e['total']) ?></td>
				<td><?= esc_html($e['order_date']) ?></td>
				<td><?= esc_html($e['due'] ?: '—') ?></td>
				<td><span class="sp-inst-badge" style="<?= esc_attr($badge[$e['status']] ?? '') ?>"><?= esc_html(spot_installments_status_label($e['status'])) ?></span></td>
				<td style="white-space:nowrap">
					<a href="<?= esc_url($e['order']->get_edit_order_url()) ?>" class="button button-small">سفارش ↗</a>
					<?php if ($e['status'] !== 'complete' && spot_sms_is_enabled()): ?>
						<button type="button" class="button button-small spot-inst-sms"
							style="margin-right:4px"
							data-order="<?= esc_attr($e['order_id']) ?>"
							data-nonce="<?= esc_attr(wp_create_nonce('spot_reminder_sms_' . $e['order_id'])) ?>">📲 یادآور</button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>

	<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px">
		<p style="margin:0;color:#646970;font-size:12px">
			نمایش <?= count($rows) ?> از <?= $total_items ?> مورد
			<?php if ($search !== '' || $filter !== 'all'): ?> — از مجموع <?= count($all) ?> قسط<?php endif; ?>
		</p>
		<?php if ($total_pages > 1): ?>
		<div style="display:flex;gap:4px;flex-wrap:wrap">
			<?php for ($p = 1; $p <= $total_pages; $p++): ?>
				<a href="<?= esc_url(add_query_arg(['filter' => $filter, 'search' => $search, 'paged' => $p], $page_url)) ?>"
				   class="button button-small"
				   style="<?= $p === $paged ? 'font-weight:700;background:#2271b1;color:#fff;border-color:#2271b1' : '' ?>"><?= $p ?></a>
			<?php endfor; ?>
		</div>
		<?php endif; ?>
	</div>

	<?php endif; ?>

	<?php spot_migration_render_wizard(); ?>
	</div>

	<script>
	(function () {
		var ajax = <?= json_encode(admin_url('admin-ajax.php')) ?>;
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.spot-inst-sms');
			if (!btn || btn.disabled) return;
			var orig = btn.textContent;
			btn.disabled = true; btn.textContent = '…';
			var fd = new FormData();
			fd.append('action',   'spot_send_reminder_sms');
			fd.append('order_id', btn.dataset.order);
			fd.append('nonce',    btn.dataset.nonce);
			fetch(ajax, {method: 'POST', body: fd})
				.then(function (r) { return r.json(); })
				.then(function (r) {
					btn.disabled = false;
					btn.textContent = r.success ? '✓ ارسال شد' : ('✗ ' + (typeof r.data === 'string' ? r.data : 'خطا'));
					setTimeout(function () { btn.textContent = orig; }, 3500);
				})
				.catch(function () {
					btn.disabled = false; btn.textContent = '✗ خطا';
					setTimeout(function () { btn.textContent = orig; }, 3500);
				});
		});
	})();
	</script>
	<?php
}

// ── Migration Wizard render ───────────────────────────────────────────────────

function spot_migration_render_wizard(): void {
	$courses_raw = get_option('spot_courses', []);
	$courses_js  = [];
	foreach ($courses_raw as $c) {
		$plan = [];
		foreach ($c['installments'] ?? [] as $idx => $inst) {
			$to    = (string) $inst['to'];
			$plan[] = [
				'n' => $idx + 1,
				'l' => $inst['from'] . '-' . $to,
				'v' => 'قسط ' . ($idx + 1) . ' — ' . ($to === '' ? 'دسترسی کامل' : ('سرفصل ' . $inst['from'] . ' تا ' . $to)),
			];
		}
		$courses_js[] = ['id' => $c['id'], 'name' => $c['name'], 'total' => count($plan), 'plan' => $plan];
	}
	$nonce = wp_create_nonce('spot_migration');
	?>
	<details id="sp-mig-wrap" style="margin-top:32px;border:1px solid #dcdcde;border-radius:4px">
		<summary style="cursor:pointer;font-weight:600;padding:12px 16px;background:#f6f7f7;border-radius:4px;list-style:none;display:flex;align-items:center;gap:8px">
			🔄 همگام‌سازی سفارشات قدیمی
		</summary>
		<div style="padding:16px">
		<p class="description" style="margin-top:0">برای سفارشاتی که پیش از این سیستم به‌صورت دستی قسطی ثبت شده‌اند و شماره قسط در نام مشتری یا لایسنس آمده (مثلاً «علی احمدی ق۲» یا «قسط 1») از این ابزار استفاده کنید.</p>

		<!-- Idle -->
		<div id="sp-mig-idle">
			<button type="button" class="button button-primary" id="sp-mig-start-btn">🔍 شروع اسکن</button>
		</div>

		<!-- Scanning -->
		<div id="sp-mig-scanning" style="display:none">
			<p id="sp-mig-scan-status" style="margin-bottom:6px">در حال اسکن...</p>
			<div style="background:#f0f0f0;border-radius:3px;height:8px;overflow:hidden;width:400px;max-width:100%">
				<div id="sp-mig-bar" style="height:100%;background:#2271b1;width:0;transition:width .3s"></div>
			</div>
			<p id="sp-mig-scan-count" style="font-size:12px;color:#646970;margin:4px 0 0"></p>
		</div>

		<!-- Review -->
		<div id="sp-mig-review" style="display:none">
			<p id="sp-mig-found-msg" style="color:#646970;margin-bottom:8px"></p>

			<table class="wp-list-table widefat striped" style="direction:rtl">
				<thead><tr>
					<th style="width:30px"><input type="checkbox" id="sp-mig-check-all" checked title="انتخاب همه"></th>
					<th>نام / شماره</th>
					<th>لایسنس</th>
					<th style="width:60px">قسط</th>
					<th style="width:60px">از</th>
					<th style="width:140px">دوره</th>
					<th style="width:100px">Limit</th>
					<th style="width:60px">منبع</th>
				</tr></thead>
				<tbody id="sp-mig-tbody"></tbody>
			</table>

			<!-- Manual add -->
			<details style="margin-top:14px">
				<summary style="cursor:pointer;font-size:13px;color:#2271b1;padding:4px 0">+ افزودن دستی</summary>
				<div style="margin-top:8px;padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
					<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">شناسه لایسنس اسپات</label>
						<input type="text" id="sp-mig-m-lid" placeholder="24 کاراکتر هگز" style="width:192px;font-family:monospace;direction:ltr;font-size:12px"></div>
					<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">شناسه سفارش WC</label>
						<input type="number" id="sp-mig-m-oid" placeholder="۱۲۳۴" style="width:90px"></div>
					<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">قسط</label>
						<input type="number" id="sp-mig-m-num" min="1" value="1" style="width:55px"></div>
					<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">از</label>
						<input type="number" id="sp-mig-m-total" min="1" value="3" style="width:55px"></div>
					<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">دوره</label>
						<select id="sp-mig-m-course" style="font-size:12px;max-width:140px">
							<option value="">— انتخاب —</option>
						</select></div>
					<div><label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px">Limit</label>
						<input type="text" id="sp-mig-m-limit" placeholder="0-4" style="width:75px;direction:ltr;font-family:monospace"></div>
					<button type="button" class="button" id="sp-mig-m-add">افزودن به جدول</button>
				</div>
			</details>

			<div style="margin-top:14px;display:flex;align-items:center;gap:12px">
				<button type="button" class="button button-primary" id="sp-mig-apply-btn">✔ اعمال تغییرات</button>
				<span id="sp-mig-apply-msg" style="font-size:13px"></span>
			</div>
		</div>

		<!-- Results -->
		<div id="sp-mig-results" style="display:none">
			<h3 style="margin-top:0">نتایج اعمال تغییرات</h3>
			<div id="sp-mig-results-list"></div>
			<button type="button" class="button" id="sp-mig-restart-btn" style="margin-top:12px">↺ اسکن مجدد</button>
		</div>

		</div>
	</details>

	<script>
	(function () {
		var AJAX    = <?= json_encode(admin_url('admin-ajax.php')) ?>;
		var NONCE   = <?= json_encode($nonce) ?>;
		var courses = <?= json_encode($courses_js) ?>;
		var scanData = {}; // keyed by license_id

		function g(id) { return document.getElementById(id); }
		function show(id) { g(id).style.display = ''; }
		function hide(id) { g(id).style.display = 'none'; }
		function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

		// Populate manual course dropdown
		courses.forEach(function (c) {
			var o = document.createElement('option');
			o.value = c.id; o.textContent = c.name;
			g('sp-mig-m-course').appendChild(o);
		});

		// ── Scan ─────────────────────────────────────────────────────────────────
		g('sp-mig-start-btn').addEventListener('click', function () {
			hide('sp-mig-idle');
			show('sp-mig-scanning');
			setProgress(0, 'فاز ۱: اسکن نام‌های مشتریان...');
			post({phase: 'billing'}).then(function (r) {
				if (!r.success) { scanErr(r.data); return; }
				merge(r.data.results);
				setProgress(5, 'فاز ۲: بررسی نام لایسنس‌ها در اسپات پلیر...');
				apiScan(1);
			}).catch(scanErr);
		});

		function apiScan(page) {
			post({phase: 'api', page: page}).then(function (r) {
				if (!r.success) { scanErr(r.data); return; }
				merge(r.data.results);
				var p = r.data.progress;
				if (p && p.total) {
					var pct = 5 + Math.round(90 * p.done / p.total);
					setProgress(pct, 'فاز ۲: ' + p.done + ' از ' + p.total + ' لایسنس بررسی شد...');
				}
				if (r.data.has_more) { apiScan(r.data.next_page); }
				else { setProgress(100, 'اسکن تمام شد.'); setTimeout(showReview, 500); }
			}).catch(scanErr);
		}

		function scanErr(msg) {
			g('sp-mig-scan-status').textContent = '✗ خطا: ' + (typeof msg === 'string' ? msg : 'خطای ناشناخته');
		}

		function merge(results) {
			(results || []).forEach(function (r) {
				if (r.license_id && !scanData[r.license_id]) scanData[r.license_id] = r;
			});
			g('sp-mig-scan-count').textContent = Object.keys(scanData).length + ' مورد یافت شد';
		}

		function setProgress(pct, txt) {
			g('sp-mig-bar').style.width = pct + '%';
			if (txt) g('sp-mig-scan-status').textContent = txt;
		}

		// ── Review ────────────────────────────────────────────────────────────────
		function showReview() {
			hide('sp-mig-scanning');
			var items = Object.values(scanData);
			g('sp-mig-found-msg').textContent = items.length
				? items.length + ' مورد یافت شد. تنظیمات را بررسی کرده و اعمال تغییرات را بزنید.'
				: 'هیچ موردی یافت نشد. می‌توانید از فرم «افزودن دستی» استفاده کنید.';
			var tbody = g('sp-mig-tbody');
			tbody.innerHTML = '';
			items.forEach(function (item) { tbody.appendChild(makeRow(item)); });
			show('sp-mig-review');
		}

		function courseOptions(selectedId) {
			var opts = '<option value="">— انتخاب —</option>';
			courses.forEach(function (c) {
				opts += '<option value="' + esc(c.id) + '"' + (c.id === selectedId ? ' selected' : '') + '>' + esc(c.name) + '</option>';
			});
			return opts;
		}

		function getPlanLimit(courseId, num) {
			var c = courses.find(function (x) { return x.id === courseId; });
			if (!c || !c.plan) return '';
			var p = c.plan.find(function (x) { return x.n === num; });
			return p ? p.l : (c.plan[0] ? c.plan[0].l : '');
		}

		function makeRow(item) {
			var tr = document.createElement('tr');
			tr.dataset.licenseId = item.license_id || '';
			tr.dataset.orderId   = item.order_id   || 0;

			var limitVal = item.limit || getPlanLimit(item.course_id, item.inst_number || 1);
			var totalVal = item.inst_total || (function(){
				var c = courses.find(function(x){ return x.id === item.course_id; });
				return c ? c.total : '';
			})();
			var srcBadge = item.source === 'api'
				? '<span style="background:#e8f0fe;color:#1a56db;padding:1px 6px;border-radius:2px;font-size:11px">API</span>'
				: (item.source === 'manual'
					? '<span style="background:#f3e8ff;color:#6b21a8;padding:1px 6px;border-radius:2px;font-size:11px">دستی</span>'
					: '<span style="background:#e8f5e9;color:#2e7d32;padding:1px 6px;border-radius:2px;font-size:11px">billing</span>');

			tr.innerHTML = '<td><input type="checkbox" class="sp-mig-chk" checked></td>'
				+ '<td><b>' + esc(item.customer) + '</b><br><small style="color:#646970;direction:ltr">' + esc(item.phone) + '</small></td>'
				+ '<td><a href="https://panel.spotplayer.ir/license/edit/' + esc(item.license_id) + '" target="_blank" style="font-size:11px;font-family:monospace;direction:ltr;display:inline-block">' + esc((item.license_id||'').slice(0,8) + '…') + '</a></td>'
				+ '<td><input type="number" class="sp-mig-num" min="1" value="' + esc(item.inst_number || 1) + '" style="width:48px"></td>'
				+ '<td><input type="number" class="sp-mig-total" min="1" value="' + esc(totalVal) + '" placeholder="—" style="width:48px"></td>'
				+ '<td><select class="sp-mig-course" style="font-size:12px;max-width:135px">' + courseOptions(item.course_id) + '</select></td>'
				+ '<td><input type="text" class="sp-mig-limit" value="' + esc(limitVal) + '" placeholder="0-4" style="width:72px;direction:ltr;font-family:monospace"></td>'
				+ '<td>' + srcBadge + '</td>';

			var numInp    = tr.querySelector('.sp-mig-num');
			var courseInp = tr.querySelector('.sp-mig-course');
			var limitInp  = tr.querySelector('.sp-mig-limit');
			var totalInp  = tr.querySelector('.sp-mig-total');

			function autofill() {
				var cid = courseInp.value;
				var num = parseInt(numInp.value) || 1;
				var lv  = getPlanLimit(cid, num);
				if (lv) limitInp.value = lv;
				var c = courses.find(function(x){ return x.id === cid; });
				if (c && c.total) totalInp.value = c.total;
			}
			numInp.addEventListener('change', autofill);
			courseInp.addEventListener('change', autofill);

			return tr;
		}

		// ── Select-all checkbox ───────────────────────────────────────────────────
		g('sp-mig-check-all').addEventListener('change', function () {
			var checked = this.checked;
			document.querySelectorAll('#sp-mig-tbody .sp-mig-chk').forEach(function (c) { c.checked = checked; });
		});

		// ── Manual add ────────────────────────────────────────────────────────────
		g('sp-mig-m-add').addEventListener('click', function () {
			var lid = g('sp-mig-m-lid').value.trim();
			if (!lid) { alert('شناسه لایسنس الزامی است.'); return; }
			var num  = parseInt(g('sp-mig-m-num').value)   || 1;
			var tot  = parseInt(g('sp-mig-m-total').value) || num;
			var cid  = g('sp-mig-m-course').value;
			var lim  = g('sp-mig-m-limit').value.trim() || getPlanLimit(cid, num);
			var item = {
				license_id: lid, order_id: parseInt(g('sp-mig-m-oid').value) || 0,
				customer: 'دستی — سفارش #' + (g('sp-mig-m-oid').value || '?'),
				phone: '', inst_number: num, inst_total: tot,
				course_id: cid, limit: lim, source: 'manual',
			};
			var tr = makeRow(item);
			tr.querySelector('.sp-mig-limit').value = lim;
			g('sp-mig-tbody').appendChild(tr);
			show('sp-mig-review');
			g('sp-mig-m-lid').value = ''; g('sp-mig-m-oid').value = ''; g('sp-mig-m-limit').value = '';
		});

		// ── Apply ─────────────────────────────────────────────────────────────────
		g('sp-mig-apply-btn').addEventListener('click', function () {
			var items = [];
			document.querySelectorAll('#sp-mig-tbody tr').forEach(function (tr) {
				var chk = tr.querySelector('.sp-mig-chk');
				if (!chk || !chk.checked) return;
				items.push({
					license_id:  tr.dataset.licenseId,
					order_id:    parseInt(tr.dataset.orderId) || 0,
					course_id:   tr.querySelector('.sp-mig-course').value,
					limit:       tr.querySelector('.sp-mig-limit').value.trim(),
					inst_number: parseInt(tr.querySelector('.sp-mig-num').value)   || 1,
					inst_total:  parseInt(tr.querySelector('.sp-mig-total').value) || 0,
				});
			});
			if (!items.length) { alert('هیچ موردی انتخاب نشده است.'); return; }

			g('sp-mig-apply-btn').disabled = true;
			g('sp-mig-apply-msg').textContent = 'در حال اعمال...';

			post({action_items: JSON.stringify(items)}, 'spot_migration_apply').then(function (r) {
				g('sp-mig-apply-btn').disabled = false;
				g('sp-mig-apply-msg').textContent = '';
				hide('sp-mig-review');
				renderResults(r.success ? r.data : []);
				show('sp-mig-results');
			}).catch(function () {
				g('sp-mig-apply-btn').disabled = false;
				g('sp-mig-apply-msg').textContent = '✗ خطا در ارتباط با سرور';
			});
		});

		// ── Results ───────────────────────────────────────────────────────────────
		function renderResults(results) {
			var html = '<table class="wp-list-table widefat striped" style="direction:rtl">'
				+ '<thead><tr><th style="direction:ltr;font-family:monospace">لایسنس ID</th><th>وضعیت</th><th>پیام</th></tr></thead><tbody>';
			(results || []).forEach(function (r) {
				html += '<tr><td style="font-family:monospace;font-size:11px;direction:ltr">' + esc(r.license_id) + '</td>'
					+ '<td>' + (r.ok ? '<span style="color:green;font-weight:600">✓ موفق</span>' : '<span style="color:#b91c1c;font-weight:600">✗ خطا</span>') + '</td>'
					+ '<td>' + esc(r.error || '') + '</td></tr>';
			});
			g('sp-mig-results-list').innerHTML = html + '</tbody></table>';
		}

		g('sp-mig-restart-btn').addEventListener('click', function () {
			scanData = {};
			g('sp-mig-tbody').innerHTML = '';
			g('sp-mig-found-msg').textContent = '';
			hide('sp-mig-results');
			show('sp-mig-idle');
		});

		// ── Shared POST helper ────────────────────────────────────────────────────
		function post(extra, action) {
			var fd = new FormData();
			fd.append('action', action || 'spot_migration_scan');
			fd.append('nonce', NONCE);
			Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
			return fetch(AJAX, {method: 'POST', body: fd}).then(function (r) { return r.json(); });
		}
	})();
	</script>
	<?php
}

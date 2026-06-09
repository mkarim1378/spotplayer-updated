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

	// UTF-8 BOM so Excel opens it correctly
	echo chr(0xEF) . chr(0xBB) . chr(0xBF);
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

// ── Data helpers ─────────────────────────────────────────────────────────────

/** Returns order IDs that contain at least one item with installment meta. */
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

/** Fetches all installment entries across all matching orders. */
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

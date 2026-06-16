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
function spot_extra_fetch_requests(string $search, string $date_from, string $date_to, int $paged = 1, int $per_page = 20): array {
	$base_args = [
		'status'     => ['processing', 'completed'],
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
		$fetched = wc_get_orders(['include' => $page_ids, 'limit' => count($page_ids)]);
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
			foreach (spot_woo_order_items($origin_order, true) as $p)
				$course_names[] = $p->get_name();
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

	$search    = sanitize_text_field($_GET['search']    ?? '');
	$date_from = sanitize_text_field($_GET['date_from'] ?? '');
	$date_to   = sanitize_text_field($_GET['date_to']   ?? '');
	$paged     = max(1, intval($_GET['paged'] ?? 1));
	$per_page  = 20;
	$page_url  = admin_url('admin.php?page=spot-extra-access');

	$result      = spot_extra_fetch_requests($search, $date_from, $date_to, $paged, $per_page);
	$page_rows   = $result['rows'];
	$total_items = $result['total'];
	$total_pages = max(1, (int) ceil($total_items / $per_page));
	$paged       = min($paged, $total_pages);
	$sms_enabled = spot_sms_is_enabled();
	?>
	<style>
	.sp-extra-page table th, .sp-extra-page table td { text-align:right !important }
	</style>
	<div class="wrap sp-extra-page">

	<h1 style="margin-bottom:12px">📋 درخواست‌های دسترسی اضافه</h1>

	<?php if (!$sms_enabled): ?>
	<div class="notice notice-warning inline" style="margin-bottom:12px"><p>⚠️ سرویس پیامک فعال نیست — دکمه ارسال پیامک نمایش داده نخواهد شد. <a href="<?= esc_url(admin_url('admin.php?page=spotplayer')) ?>">تنظیمات پیامک</a></p></div>
	<?php endif; ?>

	<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
		<input type="hidden" name="page" value="spot-extra-access">
		<input type="search" name="search" value="<?= esc_attr($search) ?>"
		       placeholder="نام یا شماره..." style="width:200px">
		<label style="font-size:13px">از:
			<input type="date" name="date_from" value="<?= esc_attr($date_from) ?>" style="margin-right:4px">
		</label>
		<label style="font-size:13px">تا:
			<input type="date" name="date_to" value="<?= esc_attr($date_to) ?>" style="margin-right:4px">
		</label>
		<button type="submit" class="button">فیلتر</button>
		<?php if ($search !== '' || $date_from !== '' || $date_to !== ''): ?>
			<a href="<?= esc_url($page_url) ?>" class="button">× پاک کردن</a>
		<?php endif; ?>
		<span style="color:#646970;font-size:12px;margin-right:8px"><?= $total_items ?> درخواست</span>
	</form>

	<?php if (empty($rows)): ?>
		<p style="color:#646970;padding:12px 0">هیچ درخواستی یافت نشد.</p>
	<?php else: ?>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th style="width:8%">شماره</th>
			<th style="width:15%">نام مشتری</th>
			<th style="width:12%">موبایل</th>
			<th style="width:18%">دوره‌ها</th>
			<th style="width:6%">مرحله</th>
			<th style="width:9%">مبلغ</th>
			<th style="width:9%">تاریخ</th>
			<th style="width:11%">پیامک</th>
			<th style="width:12%">عملیات</th>
		</tr></thead>
		<tbody>
		<?php if (empty($page_rows)): ?>
			<tr><td colspan="9" style="text-align:center;color:#646970;padding:24px">موردی یافت نشد.</td></tr>
		<?php else: foreach ($page_rows as $r): ?>
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
				<td><?= esc_html($r['date']) ?></td>
				<td>
					<div><?= spot_extra_sms_badge($r['msg1_status']) ?></div>
					<div style="margin-top:2px"><?= spot_extra_sms_badge($r['msg2_status']) ?></div>
				</td>
				<td style="white-space:nowrap">
					<?php if ($sms_enabled): ?>
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
				<a href="<?= esc_url(add_query_arg(['paged' => $p, 'search' => $search, 'date_from' => $date_from, 'date_to' => $date_to], $page_url)) ?>"
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

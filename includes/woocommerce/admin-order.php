<?php
if (!defined('ABSPATH')) exit;

function spot_woo_get_current_order(): ?WC_Order {
	if (!function_exists('wc_get_order')) return null;
	// HPOS: order ID in $_GET['id']; Classic: in $_GET['post'] or $post global
	$order_id = absint($_GET['id'] ?? 0)
		?: absint($_GET['post'] ?? 0)
		?: absint($GLOBALS['post']->ID ?? 0);
	if (!$order_id) return null;
	$order = wc_get_order($order_id);
	return $order instanceof WC_Order ? $order : null;
}

function spot_woo_admin_order() {
	$order = spot_woo_get_current_order();
	if ($order && count(spot_woo_order_items($order))) {
		add_meta_box('sp-order', 'اسپات پلیر', 'spot_woo_admin_order_box', null, 'side', 'high');
	}
}
add_action('add_meta_boxes', 'spot_woo_admin_order');

function spot_woo_admin_order_box() {
	$order = spot_woo_get_current_order();
	spot_admin_order_box($order ? spot_woo_license_data($order) : [], $order);
}

// ── License copy button in orders list ───────────────────────────────────────

function spot_woo_orders_list_column($columns) {
	$columns['spot_license'] = 'اسپات پلیر';
	return $columns;
}
add_filter('manage_edit-shop_order_columns', 'spot_woo_orders_list_column');
add_filter('manage_woocommerce_page_wc-orders_columns', 'spot_woo_orders_list_column');

function spot_woo_orders_list_column_content($column, $order_id) {
	if ($column !== 'spot_license') return;
	$order = wc_get_order($order_id);
	if (!$order) return;
	$data = $order->get_meta('_spotplayer_data');
	if (empty($data['key'])) return;
	echo '<button type="button" class="button spot-copy-key" data-key="' . esc_attr($data['key']) . '">کپی لایسنس</button>';
	echo ' <a href="https://panel.spotplayer.ir/license/edit/' . esc_attr($data['_id']) . '" target="_blank" class="button">مشاهده در پنل ↗</a>';
	if (spot_sms_is_enabled()) {
		$oid = $order->get_id();
		echo ' <button type="button" class="button spot-sms-send"'
			. ' data-order="' . esc_attr($oid) . '"'
			. ' data-nonce="' . esc_attr(wp_create_nonce('spot_resend_sms_' . $oid)) . '"'
			. '>ارسال پیامک لایسنس</button>'
			. '<span class="spot-sms-send-res" style="font-size:11px;margin-right:4px"></span>';
	}
}
add_action('manage_shop_order_posts_custom_column', 'spot_woo_orders_list_column_content', 10, 2);
add_action('manage_woocommerce_page_wc-orders_custom_column', 'spot_woo_orders_list_column_content', 10, 2);

add_action('admin_footer', function () {
	$screen = get_current_screen();
	if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'])) return;
	?>
	<script>
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.spot-copy-key');
		if (!btn) return;
		var key = btn.dataset.key, orig = btn.textContent;
		function done() { btn.textContent = '✓ کپی شد'; setTimeout(function () { btn.textContent = orig; }, 2000); }
		function legacy() { var t = document.createElement('textarea'); t.value = key; t.style.cssText = 'position:absolute;opacity:0'; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); }
		navigator.clipboard ? navigator.clipboard.writeText(key).then(done).catch(function () { legacy(); done(); }) : (legacy(), done());
	});
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.spot-sms-send');
		if (!btn || btn.disabled) return;
		var res = btn.nextElementSibling, orig = btn.textContent;
		btn.disabled = true; btn.textContent = '…';
		res.style.color = ''; res.textContent = '';
		var fd = new FormData();
		fd.append('action',   'spot_resend_sms');
		fd.append('order_id', btn.dataset.order);
		fd.append('platform', 'woo');
		fd.append('nonce',    btn.dataset.nonce);
		fetch(<?= json_encode(admin_url('admin-ajax.php')) ?>, {method:'POST', body:fd})
			.then(function(r){return r.json()})
			.then(function(r){
				btn.disabled = false; btn.textContent = orig;
				res.textContent = r.success ? '✓ در صف ارسال' : ('✗ ' + (typeof r.data==='string' ? r.data : 'خطا'));
				res.style.color = r.success ? 'green' : 'red';
				setTimeout(function(){ res.textContent = ''; }, 4000);
			})
			.catch(function(){
				btn.disabled = false; btn.textContent = orig;
				res.textContent = '✗ خطا'; res.style.color = 'red';
				setTimeout(function(){ res.textContent = ''; }, 4000);
			});
	});
	</script>
	<?php
});

// ── Installment dropdown — order edit page ───────────────────────────────────

add_action('wp_ajax_spot_get_installments', 'spot_ajax_get_installments');
function spot_ajax_get_installments(): void {
	check_ajax_referer('spot_admin_order', 'nonce');
	if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }

	$item_id = intval($_POST['item_id'] ?? 0);
	if (!$item_id) { wp_send_json_success(null); return; }

	$item = WC_Order_Factory::get_order_item($item_id);
	if (!($item instanceof WC_Order_Item_Product)) { wp_send_json_success(null); return; }

	$product = $item->get_product();
	if (!$product) { wp_send_json_success(null); return; }

	$course_ids = array_filter(explode(',', (string) $product->get_meta('_spotplayer_course')));
	if (count($course_ids) !== 1) { wp_send_json_success(null); return; }

	$course_id = $course_ids[0];
	$plan      = null;
	foreach (get_option('spot_courses', []) as $c) {
		if ($c['id'] === $course_id) { $plan = $c; break; }
	}
	if (!$plan || empty($plan['installments'])) { wp_send_json_success(null); return; }

	$total  = count($plan['installments']);
	$result = [];
	foreach ($plan['installments'] as $i => $inst) {
		$num    = $i + 1;
		$to_raw = (string) $inst['to'];
		$label  = 'قسط ' . $num . ' از ' . $total . ' — '
		        . ($to_raw === '' ? 'دسترسی کامل' : ('سرفصل ' . $inst['from'] . ' تا ' . $to_raw));
		$result[] = [
			'number' => $num,
			'total'  => $total,
			'label'  => $label,
			'limit'  => $inst['from'] . '-' . $to_raw,
			'days'   => intval($inst['days'] ?? 0),
		];
	}

	$current = null;
	$cur_num = intval($item->get_meta('_spot_installment_number'));
	if ($cur_num > 0) $current = ['number' => $cur_num];

	wp_send_json_success(['course_id' => $course_id, 'installments' => $result, 'current' => $current]);
}

add_action('woocommerce_before_save_order_item', 'spot_save_installment_item_meta');
function spot_save_installment_item_meta(WC_Order_Item $item): void {
	if (!($item instanceof WC_Order_Item_Product) || empty($_POST['items'])) return;

	$parsed = [];
	parse_str(wp_unslash($_POST['items']), $parsed);

	$item_id  = $item->get_id();
	$json_map = (array) ($parsed['spot_installment_json'] ?? []);
	if (!array_key_exists($item_id, $json_map)) return;

	$raw = $json_map[$item_id];

	if ($raw === '' || $raw === null) {
		foreach (['_spot_installment_number', '_spot_installment_total', '_spot_installment_limit', '_spot_installment_days', '_spot_installment_due'] as $key)
			$item->delete_meta_data($key);
		return;
	}

	$data   = json_decode(sanitize_text_field($raw), true);
	if (!is_array($data)) return;

	$number = intval($data['n'] ?? 0);
	$total  = intval($data['t'] ?? 0);
	$limit  = preg_replace('/[^0-9\-]/', '', (string) ($data['l'] ?? ''));
	$days   = max(0, intval($data['d'] ?? 0));
	if ($number < 1 || $total < 1) return;

	$due = '';
	if ($days > 0) {
		$order   = wc_get_order($item->get_order_id());
		$created = $order ? $order->get_date_created() : null;
		if ($created) $due = date('Y-m-d', $created->getTimestamp() + $days * DAY_IN_SECONDS);
	}

	$item->update_meta_data('_spot_installment_number', $number);
	$item->update_meta_data('_spot_installment_total',  $total);
	$item->update_meta_data('_spot_installment_limit',  $limit);
	$item->update_meta_data('_spot_installment_days',   $days);
	$item->update_meta_data('_spot_installment_due',    $due);
}

add_action('admin_footer', function () {
	$screen = get_current_screen();
	if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'])) return;
	$nonce = wp_create_nonce('spot_admin_order');
	?>
	<style>
	.sp-installment-wrap { margin-top:6px; padding:6px 8px; background:#f0f6fc; border:1px solid #c3defa; border-radius:3px; display:inline-block; max-width:300px; width:100%; box-sizing:border-box; }
	.sp-installment-wrap label { font-weight:600; font-size:11px; color:#2271b1; display:block; margin-bottom:4px; }
	.sp-installment-select { width:100%; font-size:12px; }
	</style>
	<script>
	(function () {
		var ajax  = <?= json_encode(admin_url('admin-ajax.php')) ?>;
		var nonce = <?= json_encode($nonce) ?>;

		function injectDropdown(tr, data, current) {
			var existing = tr.querySelector('.sp-installment-wrap');
			if (existing) existing.remove();

			var nameTd = tr.querySelector('td.name');
			if (!nameTd) return;

			var itemId = tr.dataset.order_item_id;

			var wrap   = document.createElement('div');
			wrap.className = 'sp-installment-wrap';

			var lbl    = document.createElement('label');
			lbl.textContent = '📋 خرید قسطی:';

			var sel    = document.createElement('select');
			sel.className = 'sp-installment-select';

			var opt0   = document.createElement('option');
			opt0.value = '';
			opt0.textContent = '— خرید کامل';
			sel.appendChild(opt0);

			data.installments.forEach(function (inst) {
				var opt = document.createElement('option');
				opt.value = JSON.stringify({n: inst.number, t: inst.total, l: inst.limit, d: inst.days});
				opt.textContent = inst.label;
				if (current && current.number === inst.number) opt.selected = true;
				sel.appendChild(opt);
			});

			var hidden = document.createElement('input');
			hidden.type  = 'hidden';
			hidden.name  = 'spot_installment_json[' + itemId + ']';
			hidden.value = sel.value;
			sel.addEventListener('change', function () { hidden.value = sel.value; });

			wrap.appendChild(lbl);
			wrap.appendChild(sel);
			wrap.appendChild(hidden);
			nameTd.appendChild(wrap);
		}

		function processRow(tr) {
			if (tr.dataset.spotDone) return;
			tr.dataset.spotDone = '1';

			var itemId = tr.dataset.order_item_id;
			if (!itemId) return;

			var fd = new FormData();
			fd.append('action',  'spot_get_installments');
			fd.append('nonce',   nonce);
			fd.append('item_id', itemId);

			fetch(ajax, {method: 'POST', body: fd})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res.success && res.data) injectDropdown(tr, res.data, res.data.current);
				})
				.catch(function () {});
		}

		function scanRows() {
			var table = document.getElementById('order_line_items');
			if (table) table.querySelectorAll('tr.item').forEach(processRow);
		}

		scanRows();

		// WC replaces the entire #order_line_items table when items are added/removed,
		// so we observe the stable metabox container instead of the table itself.
		var container = document.getElementById('woocommerce-order-items') || document.body;
		new MutationObserver(scanRows).observe(container, {childList: true, subtree: true});
	})();
	</script>
	<?php
});

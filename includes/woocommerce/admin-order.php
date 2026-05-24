<?php
if (!defined('ABSPATH')) exit;

function spot_woo_admin_order() {
	if (function_exists('wc_get_order') && count(spot_woo_order_items(wc_get_order() ?: null))) add_meta_box(
		'sp-order', 'اسپات پلیر', 'spot_woo_admin_order_box', null, 'side', 'high');
}
add_action('add_meta_boxes', 'spot_woo_admin_order');

function spot_woo_admin_order_box() {
	$order = wc_get_order();
	spot_admin_order_box(spot_woo_license_data($order), $order);
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
	</script>
	<?php
});

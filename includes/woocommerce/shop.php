<?php
if (!defined('ABSPATH')) exit;

function spot_woo_shop_order(WC_Order $ord) {
	if ($ord->get_customer_id() !== get_current_user_id()) return;
	if (!in_array($status = $ord->get_status(), ['processing', 'completed', 'partial-payment'])) return;
	if (!count(spot_woo_order_items($ord))) return;

	$sp        = get_option('spotplayer');
	$completed = ($status == 'completed');
	if (@$sp['completed'] && !$completed) return;

	$data = spot_woo_license_data($ord);

	if (@$data['_id']) {
		try {
			spot_shop_success($data);
			if ($completed) return;
			foreach ($ord->get_items() as $item)
				if (($item instanceof WC_Order_Item_Product) &&
					(($product = $item->get_product()) instanceof WC_Product) &&
					!($product->is_downloadable() || $product->get_meta('_spotplayer_course'))) return;
			$ord->update_status('completed');
		} catch (Exception $ex) {
			spot_shop_failed($ex->getMessage());
		}
		return;
	}

	// License not ready yet — trigger creation via AJAX (no page-blocking, no WP-Cron dependency)
	$_spot_nonce = wp_create_nonce('spot_auto_create_' . $ord->get_id()); ?>
	<div id="spot_pending">
		<div class="spot_pending_spinner"></div>
		<p>لایسنس شما در حال آماده‌سازی است، لطفاً چند لحظه صبر کنید...</p>
	</div>
	<script>(function(){
		var ajax=<?= json_encode(admin_url('admin-ajax.php')) ?>,
			oid=<?= (int) $ord->get_id() ?>,
			key=<?= json_encode($ord->get_order_key()) ?>,
			nonce=<?= json_encode($_spot_nonce) ?>,
			n=0;
		function go(){
			var fd=new FormData();
			fd.append('action','spot_auto_create');
			fd.append('order_id',oid);
			fd.append('nonce',nonce);
			fd.append('order_key',key);
			fetch(ajax,{method:'POST',body:fd})
				.then(function(r){return r.json()})
				.then(function(res){
					if(res.success)location.reload();
					else if(++n<5)setTimeout(go,5000);
					else document.getElementById('spot_pending').innerHTML='<p>در ایجاد لایسنس مشکلی پیش آمد. لطفاً با پشتیبانی تماس بگیرید.</p>';
				})
				.catch(function(){if(++n<5)setTimeout(go,5000)});
		}
		go();
	})();</script>
	<?php
}
add_action('woocommerce_order_details_before_order_table', 'spot_woo_shop_order');

function spot_woo_shop_order_legacy(WC_Order $ord): bool {
	$legacy = false;
	foreach ($ord->get_items() as $item)
		if (($item instanceof WC_Order_Item_Product) && @($data = $item->get_meta('_spotplayer_data'))['_id']) {
			spot_shop_success($data, $item->get_product()->get_name());
			$legacy = true;
		}
	return $legacy;
}

function spot_woo_shortcode() {
	$uid      = get_current_user_id();
	$redirect = '<script type="application/javascript">window.location.href = "' . get_home_url() . '"</script>';

	if (!$uid) return $redirect;

	$ord = null;
	if (!empty($_GET['spo'])) {
		$ord = wc_get_order(intval($_GET['spo']));
		if (!$ord || $ord->get_customer_id() !== $uid) return $redirect;
	}

	ob_start();
	if ($ord) {
		$product = !empty($_GET['spp']) ? wc_get_product(intval($_GET['spp'])) : null;
		spot_shop_success($ord->get_meta('_spotplayer_data'), $product ? $product->get_name() : '', $_GET['spc'] ?? null);
	} else {
		$per_page = 12;
		$paged    = max(1, intval($_GET['sppage'] ?? 1));

		$result = wc_get_orders([
			'customer'   => $uid,
			'limit'      => $per_page,
			'paged'      => $paged,
			'paginate'   => true,
			'meta_query' => [['key' => '_spotplayer_data', 'compare' => 'EXISTS']],
		]); ?>
		<div id="sp_courses">
			<?php foreach ($result->orders as $o) {
				foreach (spot_woo_order_items($o, true) as $p) { ?>
					<a href="<?= esc_url("?spo={$o->get_id()}&spp={$p->get_id()}&spc={$p->get_meta('_spotplayer_course')}") ?>"><?= $p->get_image() ?><h2><?= esc_html($p->get_name()) ?></h2></a>
				<?php }
			} ?>
		</div>
		<?php if ($result->max_num_pages > 1) { ?>
			<div id="sp_pagination">
				<?php if ($paged > 1) { ?>
					<a href="<?= esc_url(add_query_arg('sppage', $paged - 1)) ?>" class="sp_page_btn">« صفحه قبل</a>
				<?php } ?>
				<span><?= $paged ?> از <?= $result->max_num_pages ?></span>
				<?php if ($paged < $result->max_num_pages) { ?>
					<a href="<?= esc_url(add_query_arg('sppage', $paged + 1)) ?>" class="sp_page_btn">صفحه بعد »</a>
				<?php } ?>
			</div>
		<?php }
	}
	return ob_get_clean();
}

function spot_woo_shop_my_menu($links): array {
	$o = @get_option('spotplayer');
	if (class_exists('Studiare_Core') && @$o['wcspc']) unset($links['purchased-products']);
	if (!@$o['wccrs']) return $links;
	return array_slice($links, 0, 1, true) + ['licenses' => 'لایسنس‌های من'] + array_slice($links, 1, null, true);
}
add_filter('woocommerce_account_menu_items', 'spot_woo_shop_my_menu', 50);

function spot_woo_shop_my_licenses_init() {
	add_rewrite_endpoint('licenses', EP_PAGES);
}
add_action('init', 'spot_woo_shop_my_licenses_init');

function spot_woo_shop_my_licenses_content() {
	echo spot_shortcode();
}
add_action('woocommerce_account_licenses_endpoint', 'spot_woo_shop_my_licenses_content');

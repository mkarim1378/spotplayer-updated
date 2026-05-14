<?php
if (!defined('ABSPATH')) exit;

function spot_woo_shop_order(WC_Order $ord) {
	if ($ord->get_customer_id() !== get_current_user_id()) return;
	if (!in_array($status = $ord->get_status(), ['processing', 'completed', 'partial-payment'])) return;
	if (!count(spot_woo_order_items($ord))) return;

	$sp        = get_option('spotplayer');
	$completed = ($status == 'completed');
	if (@$sp['completed'] && !$completed) return;

	try {
		spot_shop_success(spot_woo_order_license_request($ord));

		if ($completed) return;
		foreach ($ord->get_items() as $item)
			if (($item instanceof WC_Order_Item_Product) &&
				(($product = $item->get_product()) instanceof WC_Product) &&
				!($product->is_downloadable() || $product->get_meta('_spotplayer_course'))) return;
		$ord->update_status('completed');

	} catch (Exception $ex) {
		spot_shop_failed($ex->getMessage());
	}
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
	} else { ?>
		<div id="sp_courses">
			<?php foreach (wc_get_orders(['customer' => $uid, 'page' => 0]) as $o) {
				if (@$o->get_meta('_spotplayer_data')['_id']) {
					foreach (spot_woo_order_items($o, true) as $p) { ?>
						<a href="<?= esc_url("?spo={$o->get_id()}&spp={$p->get_id()}&spc={$p->get_meta('_spotplayer_course')}") ?>"><?= $p->get_image() ?><h2><?= esc_html($p->get_name()) ?></h2></a>
					<?php }
				}
			} ?>
		</div>
	<?php }
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

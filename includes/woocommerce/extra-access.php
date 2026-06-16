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
	// Phase 4: full implementation
}
add_action('woocommerce_account_license-request_endpoint', 'spot_extra_render_page');

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

// ── Helper: calculate price and stage for an origin order ────────────────────

function spot_extra_calc_price(int $origin_order_id): array {
	$sp     = get_option('spotplayer', []);
	$stages = array_values((array) ($sp['extra_stages'] ?? []));
	$mode   = $sp['extra_end_mode'] ?? 'max';
	$stage  = spot_extra_count_paid_requests($origin_order_id) + 1;

	if (empty($stages)) return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];

	if ($stage > count($stages)) {
		if ($mode === 'max') return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];
		$def = end($stages); // repeat_last
	} else {
		$def = $stages[$stage - 1];
	}

	$type  = $def['type']  ?? 'fixed';
	$value = floatval($def['value'] ?? 0);
	$price = ($type === 'percent')
		? round($value / 100 * spot_extra_origin_total($origin_order_id))
		: $value;

	return ['blocked' => false, 'stage' => $stage, 'price' => $price];
}

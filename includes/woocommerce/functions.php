<?php
if (!defined('ABSPATH')) exit;

/** @return WC_Product[] */
function spot_woo_order_items(?WC_Order $ord, $products = false): array {
	$r = [];
	if ($ord) foreach ($ord->get_items() as $i)
		if (($i instanceof WC_Order_Item_Product) && (($p = $i->get_product()) instanceof WC_Product) && ($c = $p->get_meta('_spotplayer_course')))
			$products ? array_push($r, $p) : ($r = array_merge($r, explode(',', $c)));
	return $r;
}

/** @throws Exception */
function spot_woo_order_license_request(WC_Order $ord, $admin = false): ?array {
	if (@($data = spot_woo_license_data($ord))['_id']) return $data;
	if (!count($courses = spot_woo_order_items($ord))) return null;
	if (!$admin && ($ord->get_date_created()->getTimestamp() < (get_option('spotplayer')['time'] ?: 0))) return null;

	try {
		$req_data = array_merge($data, ['course' => $courses, 'payload' => strval($ord->get_id())]);

		$custom_limit = $ord->get_meta('_spot_limit');
		if (!empty($custom_limit)) {
			$limit_map = [];
			foreach ($courses as $cid) $limit_map[$cid] = $custom_limit;

			$current_data_field = isset($req_data['data']) ? $req_data['data'] : [];
			$current_data_field['limit'] = $limit_map;
			if (!isset($current_data_field['confs'])) $current_data_field['confs'] = 0;
			$req_data['data'] = $current_data_field;
		}

		$rep = spot_request_license_put($req_data);
		if (!($id = @$rep['_id'])) throw new Exception('پاسخ نامعتبر از سرور', 999);

		$ord->update_meta_data('_spotplayer_data', $data = array_merge($data, $rep));
		$ord->save_meta_data();
		$ord->add_order_note(sprintf('لایسنس با شناسه %s برای این سفارش ایجاد شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>'));
		return $data;

	} catch (Exception $ex) {
		$err = sprintf('خطای %s هنگام ایجاد لایسنس روی داد.', '<b>«' . $ex->getMessage() . '»</b>');
		$ord->add_order_note($err . (($ex->getCode() == 999) ? ' <a target="_blank" href="' . parse_url(get_home_url(), PHP_URL_PATH) . '/spdeb?id=' . $ord->get_id() . '">اطلاعات دیباگ</a>' : ''));
		spot_admin_notice($err . ' <a href="' . get_edit_post_link($ord->get_id()) . '">سفارش ' . $ord->get_id() . '</a>');
		throw new Exception($err);
	}
}

function spot_woo_license_data(WC_Order $ord): array { // dont rename $order, used in eval code
	$data = $ord->get_meta('_spotplayer_data') ?: [];
	if (in_array($ord->get_status(), ['auto-draft', 'draft'])) return $data;
	return $data ?: spot_woo_license_data_eval($ord);
}

function spot_woo_license_data_eval(?WC_Order $order): ?array { // dont rename $order & $user
	if (!$order) return null;
	/** @noinspection PhpUnusedLocalVariableInspection */
	$user = $order->get_user();
	return eval('return ' . spot_license_code() . ';');
}

add_action('woocommerce_order_status_completed', 'spot_auto_license_on_completed', 10, 1);
function spot_auto_license_on_completed($order_id) {
	$order = wc_get_order($order_id);
	if (!$order) return;
	if ($order->get_meta('_spotplayer_data')) return;
	if (!count(spot_woo_order_items($order))) return;

	try {
		$result = spot_woo_order_license_request($order, true);
		if (is_array($result) && !empty($result['_id']))
			$order->add_order_note('✅ لایسنس اسپات پلیر به صورت خودکار ساخته شد. شناسه: ' . $result['_id']);
		else
			$order->add_order_note('⚠️ تلاش خودکار برای ساخت لایسنس انجام شد ولی موفق نبود.');
	} catch (Exception $e) {
		$order->add_order_note('❌ خطا در ایجاد خودکار لایسنس: ' . $e->getMessage());
	}
}

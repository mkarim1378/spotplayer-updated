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
		$code_txt = $ex->getCode() ? ' (کد: ' . $ex->getCode() . ')' : '';
		$err      = sprintf('خطای %s هنگام ایجاد لایسنس روی داد.', '<b>«' . $ex->getMessage() . '»</b>') . $code_txt;
		$extra    = $ex->getCode() == 999
			? ' <a target="_blank" href="' . parse_url(get_home_url(), PHP_URL_PATH) . '/spdeb?id=' . $ord->get_id() . '">اطلاعات دیباگ</a>'
			: ' <a target="_blank" href="https://spotplayer.ir/help/api/wordpress">راهنما</a>';
		$ord->add_order_note($err . $extra);
		spot_admin_notice($err . $extra . ' — <a href="' . $ord->get_edit_order_url() . '">سفارش ' . $ord->get_id() . '</a>');
		if (spot_is_fatal_license_error($ex->getMessage())) {
			$ord->update_meta_data('_spot_fatal_error', $ex->getMessage());
			$ord->save_meta_data();
		}
		throw new Exception($err);
	}
}

function spot_is_fatal_license_error(string $msg): bool {
	return mb_strpos($msg, 'واترمارک') !== false && mb_strpos($msg, 'قبلا') !== false;
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


add_action('woocommerce_order_status_processing', 'spot_auto_license_on_processing', 10, 1);
function spot_auto_license_on_processing($order_id): void {
	if (@get_option('spotplayer')['completed']) return;
	$order = wc_get_order($order_id);
	if (!$order || @spot_woo_license_data($order)['_id'] || !count(spot_woo_order_items($order))) return;
	try { spot_woo_order_license_request($order); } catch (Exception $e) {}
}

add_action('woocommerce_order_status_completed', 'spot_auto_license_on_completed', 10, 1);
function spot_auto_license_on_completed($order_id): void {
	$order = wc_get_order($order_id);
	if (!$order || @spot_woo_license_data($order)['_id'] || !count(spot_woo_order_items($order))) return;
	try { spot_woo_order_license_request($order); } catch (Exception $e) { /* AJAX fallback handles this */ }
}

// ── AJAX auto-create (admin order box + customer order page fallback) ─────────

add_action('wp_ajax_spot_auto_create',        'spot_ajax_auto_create');
add_action('wp_ajax_nopriv_spot_auto_create', 'spot_ajax_auto_create');
function spot_ajax_auto_create(): void {
	$order_id = intval($_POST['order_id'] ?? 0);
	if (!wp_verify_nonce(sanitize_text_field($_POST['nonce'] ?? ''), 'spot_auto_create_' . $order_id))
		wp_send_json_error('nonce');

	$order = wc_get_order($order_id);
	if (!$order) wp_send_json_error('not_found');

	$uid         = get_current_user_id();
	$is_admin    = current_user_can('manage_woocommerce');
	$is_customer = $uid && (int) $order->get_customer_id() === $uid;
	$is_guest    = !$uid && $order->get_order_key() === sanitize_text_field($_POST['order_key'] ?? '');
	if (!$is_admin && !$is_customer && !$is_guest) wp_send_json_error('unauthorized');

	if (@spot_woo_license_data($order)['_id']) wp_send_json_success(['status' => 'exists']);
	if (($fe = $order->get_meta('_spot_fatal_error'))) wp_send_json_error(['fatal' => true, 'message' => $fe]);
	if (!count(spot_woo_order_items($order)))  wp_send_json_error('no_courses');

	try {
		spot_woo_order_license_request($order, true);
		wp_send_json_success(['status' => 'created']);
	} catch (Exception $e) {
		wp_send_json_error($e->getMessage());
	}
}

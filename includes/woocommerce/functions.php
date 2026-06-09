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

/** Returns map of course_id => [number, total, limit, days] for items that have installment meta set. */
function spot_woo_order_installment_items(WC_Order $ord): array {
	$result = [];
	foreach ($ord->get_items() as $item) {
		if (!($item instanceof WC_Order_Item_Product)) continue;
		$number = intval($item->get_meta('_spot_installment_number'));
		if ($number < 1) continue;
		$product = $item->get_product();
		if (!($product instanceof WC_Product)) continue;
		$cids = array_filter(explode(',', (string) $product->get_meta('_spotplayer_course')));
		if (count($cids) !== 1) continue;
		$cid   = reset($cids);
		$limit = (string) $item->get_meta('_spot_installment_limit');
		// Normalize legacy "N-" (empty to) stored before v24.13 fix — treat as full access
		if (substr($limit, -1) === '-') $limit = '';
		$result[$cid] = [
			'number' => $number,
			'total'  => intval($item->get_meta('_spot_installment_total')),
			'limit'  => $limit,
			'days'   => intval($item->get_meta('_spot_installment_days')),
			'due'    => (string) $item->get_meta('_spot_installment_due'),
		];
	}
	return $result;
}

/** Finds _spotplayer_data from the most recent previous order for the same customer containing course_id. */
function spot_find_prev_license(WC_Order $ord, string $course_id): ?array {
	$current_id  = $ord->get_id();
	$customer_id = intval($ord->get_customer_id());
	$phone       = $ord->get_billing_phone();

	$base = [
		'status'  => ['processing', 'completed'],
		'limit'   => 20,
		'orderby' => 'date',
		'order'   => 'DESC',
		'exclude' => [$current_id],
	];

	$candidates = [];

	if ($customer_id) {
		$candidates = wc_get_orders(array_merge($base, ['customer_id' => $customer_id]));
	}

	if (empty($candidates) && $phone) {
		// Direct arg — WC 7+ / HPOS
		$candidates = wc_get_orders(array_merge($base, ['billing_phone' => $phone]));
	}

	if (empty($candidates) && $phone) {
		// Classic WC fallback via meta_query
		$candidates = wc_get_orders(array_merge($base, [
			'meta_query' => [['key' => '_billing_phone', 'value' => $phone]],
		]));
	}

	foreach ($candidates as $prev) {
		$prev_data = $prev->get_meta('_spotplayer_data');
		if (empty($prev_data['_id'])) continue;
		if (!in_array($course_id, spot_woo_order_items($prev), true)) continue;
		return $prev_data;
	}

	return null;
}

/** @throws Exception */
function spot_woo_order_license_request(WC_Order $ord, $admin = false): ?array {
	if (@($data = spot_woo_license_data($ord))['_id']) return $data;
	if (!count($courses = spot_woo_order_items($ord))) return null;
	if (!$admin && ($ord->get_date_created()->getTimestamp() < (get_option('spotplayer')['time'] ?: 0))) return null;

	$inst_items = spot_woo_order_installment_items($ord);

	$update_items = [];
	foreach ($inst_items as $cid => $inst) {
		if ($inst['number'] > 1) $update_items[$cid] = $inst;
	}

	try {
		if (!empty($update_items)) {
			// ── UPDATE MODE: installment 2+ ──────────────────────────────────────
			reset($update_items);
			$lookup_cid  = key($update_items);
			$lookup_inst = $update_items[$lookup_cid];

			$prev_data = spot_find_prev_license($ord, $lookup_cid);
			if (!$prev_data) throw new Exception('لایسنس قبلی برای این مشتری و دوره پیدا نشد', 998);

			$license_id = $prev_data['_id'];

			$limit_map = [];
			foreach ($update_items as $cid => $u_inst) {
				$limit_map[$cid] = $u_inst['limit'];
			}

			$suffix   = ' قسط ' . $lookup_inst['number'] . ' از ' . $lookup_inst['total'];
			// Strip any previous installment suffix from the stored license name before appending new one
			$base_name = preg_replace('/\s*قسط\s*\d+\s*از\s*\d+\s*$/', '', (string)($prev_data['name'] ?? ''));
			$api_name  = $base_name . $suffix;

			$rep = spot_request(
				'https://panel.spotplayer.ir/license/edit/' . $license_id,
				['name' => $api_name, 'data' => ['limit' => $limit_map]]
			);
			if (empty($rep['_id'])) throw new Exception('پاسخ نامعتبر از سرور اسپات', 999);

			$saved = array_merge($prev_data, $rep);
			$ord->update_meta_data('_spotplayer_data', $saved);
			$ord->save_meta_data();
			$ord->add_order_note(sprintf(
				'لایسنس با شناسه %s برای قسط %d از %d آپدیت شد.',
				'<a href="https://panel.spotplayer.ir/license/edit/' . esc_attr($license_id) . '" target="_blank">' . $license_id . '</a>',
				$lookup_inst['number'],
				$lookup_inst['total']
			));
			spot_sms_trigger_installment_update($ord, $lookup_inst);
			return $saved;
		}

		// ── CREATE MODE: installment 1 or standard purchase ──────────────────────
		$req_data = array_merge($data, ['course' => $courses, 'payload' => strval($ord->get_id())]);

		if (!empty($inst_items)) {
			// Build per-item limit map; ignore _spot_limit order meta
			$limit_map = [];
			foreach ($inst_items as $cid => $inst) {
				if ($inst['limit'] !== '') $limit_map[$cid] = $inst['limit'];
			}
			if (!empty($limit_map)) {
				$df = isset($req_data['data']) ? $req_data['data'] : [];
				$df['limit'] = $limit_map;
				if (!isset($df['confs'])) $df['confs'] = 0;
				$req_data['data'] = $df;
			}

			// Append installment suffix to the license name (API only)
			reset($inst_items);
			$first_inst       = current($inst_items);
			$suffix           = ' قسط ' . $first_inst['number'] . ' از ' . $first_inst['total'];
			$req_data['name'] = (isset($req_data['name']) ? $req_data['name'] : '') . $suffix;
		} else {
			$custom_limit = $ord->get_meta('_spot_limit');
			if (!empty($custom_limit)) {
				$limit_map = [];
				foreach ($courses as $cid) $limit_map[$cid] = $custom_limit;

				$current_data_field = isset($req_data['data']) ? $req_data['data'] : [];
				$current_data_field['limit'] = $limit_map;
				if (!isset($current_data_field['confs'])) $current_data_field['confs'] = 0;
				$req_data['data'] = $current_data_field;
			}
		}

		$rep = spot_request_license_put($req_data, $ord);
		if (!($id = @$rep['_id'])) throw new Exception('پاسخ نامعتبر از سرور', 999);

		$ord->update_meta_data('_spotplayer_data', $data = array_merge($data, $rep));
		$ord->save_meta_data();
		$ord->add_order_note(sprintf('لایسنس با شناسه %s برای این سفارش ایجاد شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>'));
		spot_sms_trigger_woo($ord);
		return $data;

	} catch (Exception $ex) {
		$code_txt = $ex->getCode() ? ' (کد: ' . $ex->getCode() . ')' : '';
		$err      = sprintf('خطای %s هنگام ایجاد/آپدیت لایسنس روی داد.', '<b>«' . $ex->getMessage() . '»</b>') . $code_txt;
		$extra    = ($ex->getCode() == 999)
			? ' <a target="_blank" href="' . parse_url(get_home_url(), PHP_URL_PATH) . '/spdeb?id=' . $ord->get_id() . '">اطلاعات دیباگ</a>'
			: ' <a target="_blank" href="https://spotplayer.ir/help/api/wordpress">راهنما</a>';
		$ord->add_order_note($err . $extra);
		spot_admin_notice($err . $extra . ' — <a href="' . $ord->get_edit_order_url() . '">سفارش ' . $ord->get_id() . '</a>');
		if (spot_is_fatal_license_error($ex->getMessage())) {
			$ord->update_meta_data('_spot_fatal_error', $ex->getMessage());
			$ord->save_meta_data();
		}
		throw new Exception($ex->getMessage(), $ex->getCode());
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
	if (!$order || $order->get_created_via() === 'admin') return;
	if (@spot_woo_license_data($order)['_id'] || !count(spot_woo_order_items($order))) return;
	try { spot_woo_order_license_request($order); } catch (Exception $e) {}
}

add_action('woocommerce_order_status_completed', 'spot_auto_license_on_completed', 10, 1);
function spot_auto_license_on_completed($order_id): void {
	$order = wc_get_order($order_id);
	if (!$order || $order->get_created_via() === 'admin') return;
	if (@spot_woo_license_data($order)['_id'] || !count(spot_woo_order_items($order))) return;
	try { spot_woo_order_license_request($order); } catch (Exception $e) { /* AJAX fallback handles this */ }
}

// ── AJAX auto-create (admin order box + customer order page fallback) ─────────

add_action('wp_ajax_spot_set_test_flag', 'spot_ajax_set_test_flag');
function spot_ajax_set_test_flag(): void {
	$order_id = intval($_POST['order_id'] ?? 0);
	if (!current_user_can('manage_woocommerce')) wp_send_json_error('unauthorized');
	if (!wp_verify_nonce(sanitize_text_field($_POST['nonce'] ?? ''), 'spot_set_test_' . $order_id))
		wp_send_json_error('nonce');
	$order = wc_get_order($order_id);
	if (!$order) wp_send_json_error('not_found');
	$order->update_meta_data('_spot_test', intval($_POST['test'] ?? 0) ? 1 : 0);
	$order->save_meta_data();
	wp_send_json_success();
}

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
	if ($order->get_meta('_spot_fatal_error')) wp_send_json_error('reload');
	if (!count(spot_woo_order_items($order)))  wp_send_json_error('no_courses');

	try {
		spot_woo_order_license_request($order, true);
		wp_send_json_success(['status' => 'created']);
	} catch (Exception $e) {
		wp_send_json_error($e->getMessage());
	}
}

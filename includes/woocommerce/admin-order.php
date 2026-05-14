<?php
if (!defined('ABSPATH')) exit;

function spot_woo_admin_order() {
	if (function_exists('wc_get_order') && count(spot_woo_order_items(wc_get_order() ?: null))) add_meta_box(
		'sp-order', 'اسپات پلیر', 'spot_woo_admin_order_box', null, 'normal', 'high');
}
add_action('add_meta_boxes', 'spot_woo_admin_order');

function spot_woo_admin_order_box() {
	spot_admin_order_box(spot_woo_license_data(wc_get_order()));
}

function spot_woo_admin_order_save(int $oid) {
	if (!current_user_can('administrator')) return;

	$ord = wc_get_order($oid);
	if (!count(spot_woo_order_items($ord))) return;

	if (!empty($_POST['spot-remove'])) {
		$ord->delete_meta_data('_spotplayer_data');
		$ord->save_meta_data();
		$ord->add_order_note('اطلاعات لایسنس اسپات پلیر حذف شد.');
		return;
	}
	if (@($data = spot_woo_license_data($ord))['_id']) return;

	if (!empty($_POST['spot-retrieve'])) {
		if (!preg_match('/^[0-9a-f]{24}$/i', $id = sanitize_text_field($_POST['spot-id'] ?? '')))
			return spot_admin_notice('شناسه لایسنس اسپات پلیر باید یک رشته هگز 24 کاراکتری باشد.', 'warning');

		try {
			$rep = spot_request_license_get($id);
			if (!($id = @$rep['_id'])) throw new Exception('909');

			$ord->update_meta_data('_spotplayer_data', $rep);
			$ord->save_meta_data();
			$ord->add_order_note($note = sprintf('اطلاعات لایسنس %s دریافت شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>'));
			spot_admin_notice($note . ' <a href="' . get_edit_post_link($ord->get_id()) . '">سفارش ' . $ord->get_id() . '</a>', 'info');
		} catch (Exception $ex) {
			spot_admin_notice('هنگام دریافت لایسنس خطای ' . $ex->getMessage() . ' روی داد.');
		}
	} else if (!empty($_POST['spot-create'])) {
		if (($n = sanitize_text_field($_POST['spot-name'] ?? '')) && ($t = $_POST['spot-text'] ?? [])) {
			try {
				$ord->update_meta_data('_spotplayer_data', array_merge($data, [
					'name'      => $n,
					'watermark' => ['texts' => array_values(array_filter(
						[['text' => $t[0]], ['text' => $t[1]], ['text' => $t[2]]],
						fn($e) => strlen($e['text']) > 3
					))],
				]));
				$ord->save_meta_data();
				spot_woo_order_license_request($ord, true);
			} catch (Exception $ex) {
			}
		} else spot_admin_notice('نام و متن واترمارک اول وارد نشده بود.', 'warning');
	}
}
add_action('woocommerce_process_shop_order_meta', 'spot_woo_admin_order_save', 10, 1);

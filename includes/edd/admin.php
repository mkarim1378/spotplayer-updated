<?php
if (!defined('ABSPATH')) exit;

function spot_edd_admin_dl($dl_id) { ?>
	<div id="spot-course">
		<label for="course">شناسه دوره های اسپات پلیر</label>
		<textarea id="course" name="spot_course"><?= implode(',', get_post_meta($dl_id, '_spot_course', true) ?: []) ?></textarea>
		<div>شناسه یک دوره یا چند دوره که با , از هم جدا شده اند.</div>
	</div>
<?php }
add_action('edd_price_field', 'spot_edd_admin_dl', 10, 1);

function spot_edd_admin_dl_save($dl_id) {
	update_post_meta($dl_id, '_spot_course', array_filter(explode(',', sanitize_text_field($_POST['spot_course'] ?? '')), function ($id) {
		return preg_match('/^[0-9a-f]{24}$/i', $id);
	}));
}
add_action('edd_save_download', 'spot_edd_admin_dl_save', 10, 2);

function spot_edd_admin_payment_box(int $pid) { ?>
	<div id="sp-order" class="postbox">
		<h3 class="hndle"><span>اطلاعات اسپات پلیر</span></h3>
		<div class="inside edd-clearfix"><?php spot_admin_order_box(spot_edd_license_data(edd_get_payment($pid))) ?></div>
	</div>
<?php }
add_action('edd_view_order_details_main_before', 'spot_edd_admin_payment_box', 10, 1);

function spot_edd_admin_payment_save(int $pid) {
	if (!current_user_can('administrator')) return;
	if (!isset($_POST['spot_order_nonce']) || !wp_verify_nonce($_POST['spot_order_nonce'], 'spot_order_save')) return;

	$pay = edd_get_payment($pid);
	if (!count(spot_edd_payment_items($pay))) return;

	if (@($data = spot_edd_license_data($pay))['_id']) return;

	if (!empty($_POST['spot-retrieve'])) {
		if (!preg_match('/^[0-9a-f]{24}$/i', $id = sanitize_text_field($_POST['spot-id'] ?? '')))
			return spot_admin_notice('شناسه لایسنس اسپات پلیر باید یه رشته هگز 24 کاراکتری باشد.', 'warning');

		try {
			$rep = spot_request_license_get($id);
			if (!($id = @$rep['_id'])) throw new Exception('پاسخ نامعتبر از سرور', 999);

			$pay->update_meta('_spot_data', $rep);
			edd_insert_payment_note($pay->ID, $note = sprintf('اطلاعات لایسنس %s دریافت شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>'));
			spot_admin_notice($note . ' <a href="' . get_edit_post_link($pay->ID) . '">سفارش ' . $pay->ID . '</a>', 'info');
		} catch (Exception $ex) {
			$code_txt = $ex->getCode() ? ' (کد: ' . $ex->getCode() . ')' : '';
			spot_admin_notice('هنگام دریافت اطلاعات لایسنس خطا رخ داد: <b>«' . $ex->getMessage() . '»</b>' . $code_txt . ' — <a href="https://spotplayer.ir/help/api/wordpress" target="_blank">راهنما</a>');
		}
	} else if (!empty($_POST['spot-create'])) {
		if (($n = sanitize_text_field($_POST['spot-name'] ?? '')) && ($t = $_POST['spot-text'] ?? [])) {
			try {
				$pay->update_meta('_spot_data', array_merge($data, [
					'name'      => $n,
					'watermark' => ['texts' => array_values(array_filter(
						[['text' => $t[0]], ['text' => $t[1]], ['text' => $t[2]]],
						fn($e) => strlen($e['text']) > 3
					))],
				]));
				spot_edd_payment_license_request($pay, true);
			} catch (Exception $ex) {
			}
		} else spot_admin_notice('نام و متن واترمارک اول وارد نشده بود.', 'warning');
	}
}
add_action('edd_updated_edited_purchase', 'spot_edd_admin_payment_save', 10, 1);

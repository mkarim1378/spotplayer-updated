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


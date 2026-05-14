<?php
if (!defined('ABSPATH')) exit;

function spot_edd_shop_order(EDD_Payment $pay) {
	if (intval(edd_get_payment_user_id($pay->ID)) !== get_current_user_id()) return;
	if (edd_get_payment_status($pay) !== 'complete') return;

	try {
		spot_shop_success(spot_edd_payment_license_request($pay));
	} catch (Exception $ex) {
		spot_shop_failed($ex->getMessage());
	}
}
add_action('edd_payment_receipt_after_table', 'spot_edd_shop_order', 10, 1);

function spot_edd_shortcode() {
	if (!($uid = get_current_user_id()) || (($o = $_GET['spo']) && (intval(edd_get_payment_customer_id($o)) !== $uid)))
		return '<script type="application/javascript">window.location.href = "' . get_home_url() . '"</script>';

	ob_start();
	if ($o) spot_shop_success(edd_get_payment($o)->get_meta('_spot_data'), get_the_title($o), $_GET['spc']);
	else { ?>
		<div id="sp_courses">
			<?php foreach (edd_get_payments(['user' => $uid, 'output' => 'payments']) as $pay) {
				if (@$pay->get_meta('_spot_data')['_id']) {
					foreach (spot_edd_payment_items($pay, true) as $d) { ?>
						<a href=<?= "?spo=$pay->ID&spp={$d['id']}&spc={$d['course']}" ?>>
							<?= get_the_post_thumbnail($d['id']) ?>
							<h2><?= $d['name'] ?></h2>
						</a>
					<?php }
				}
			} ?>
		</div>
	<?php }
	return ob_get_clean();
}

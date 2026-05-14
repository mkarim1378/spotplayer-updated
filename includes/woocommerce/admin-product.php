<?php
if (!defined('ABSPATH')) exit;

function spot_woo_admin_product_tab($tabs) {
	$tabs['spotplayer-tab'] = ['label' => 'اسپات پلیر', 'target' => 'spotplayer-product', 'class' => 'show_if_simple'];
	return $tabs;
}
add_filter('woocommerce_product_data_tabs', 'spot_woo_admin_product_tab');

function spot_woo_admin_product_panel() { ?>
	<div id="spotplayer-product" class="panel woocommerce_options_panel">
		<?php woocommerce_wp_textarea_input([
			'id'          => '_spotplayer_course',
			'name'        => '_spotplayer_course',
			'label'       => 'شناسه دوره‌ها',
			'class'       => 'ltr',
			'desc_tip'    => true,
			'description' => 'شناسه دوره های مد نظر را از پنل اسپات پلیر کپی و با جدا کننده , در اینجا وارد کنید.',
		]) ?>
	</div>
<?php }
add_action('woocommerce_product_data_panels', 'spot_woo_admin_product_panel');

function spot_woo_admin_product_update(WC_Product $product) {
	spot_woo_admin_product_save($product, $_POST['_spotplayer_course']);
}
add_action('woocommerce_admin_process_product_object', 'spot_woo_admin_product_update');

function spot_woo_admin_variation_panel(int $i, $data) { ?>
	<div id="spotplayer-product"><?php woocommerce_wp_textarea_input([
		'id'            => "spotplayer_course$i",
		'name'          => "spotplayer_course[$i]",
		'value'         => $data['_spotplayer_course'][0],
		'label'         => 'شناسه های دوره اسپات پلیر',
		'wrapper_class' => 'form-row form-row-full',
		'class'         => 'ltr',
		'desc_tip'      => true,
		'description'   => 'شناسه دوره های مد نظر را از پنل اسپات پلیر کپی و با جدا کننده , در اینجا وارد کنید.',
	]) ?></div>
<?php }
add_action('woocommerce_product_after_variable_attributes', 'spot_woo_admin_variation_panel', 10, 2);

function spot_woo_admin_variation_update(WC_Product_Variation $variation, int $i) {
	spot_woo_admin_product_save($variation, $_POST['spotplayer_course'][$i]);
}
add_action('woocommerce_admin_process_variation_object', 'spot_woo_admin_variation_update', 10, 2);

function spot_woo_admin_product_save($product, $course) {
	if (!current_user_can('administrator')) return;
	if (preg_match('/^[0-9a-f]{24}(,[0-9a-f]{24})*$/i', $course)) {
		$product->update_meta_data('_spotplayer_course', $course);
		$product->set_virtual(true);
		$product->set_sold_individually(true);
	} else $product->update_meta_data('_spotplayer_course', '');
}

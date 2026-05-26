<?php
if (!defined('ABSPATH')) exit;

add_filter('woocommerce_product_data_tabs', 'spot_woo_admin_product_tab');
function spot_woo_admin_product_tab($tabs) {
	$tabs['spotplayer-tab'] = ['label' => 'اسپات پلیر', 'target' => 'spotplayer-product', 'class' => 'show_if_simple'];
	return $tabs;
}

function spot_woo_courses_select(string $field_name, string $current_value): void {
	$courses  = get_option('spot_courses', []);
	$selected = array_filter(explode(',', $current_value));

	// اگه شناسه‌ای روی محصول ذخیره شده ولی توی لیست تنظیمات نیست، به عنوان fallback نشون بده
	$course_ids = array_column($courses, 'id');
	foreach ($selected as $sid) {
		if (!in_array($sid, $course_ids, true))
			$courses[] = ['id' => $sid, 'name' => '(' . $sid . ')'];
	}

	if (empty($courses)) { ?>
		<span style="color:#646970;font-size:12px">هنوز دوره‌ای تعریف نشده —
			<a href="<?= esc_url(admin_url('admin.php?page=spotplayer')) ?>">از تنظیمات اسپات پلیر اضافه کنید ↗</a>
		</span>
	<?php return; } ?>
	<select name="<?= esc_attr($field_name) ?>" multiple class="wc-enhanced-select" style="width:100%">
		<?php foreach ($courses as $c) { ?>
			<option value="<?= esc_attr($c['id']) ?>" <?= in_array($c['id'], $selected, true) ? 'selected' : '' ?>><?= esc_html($c['name']) ?></option>
		<?php } ?>
	</select>
<?php }

add_action('woocommerce_product_data_panels', 'spot_woo_admin_product_panel');
function spot_woo_admin_product_panel() {
	global $post;
	$product = wc_get_product($post->ID);
	$current = $product ? (string)$product->get_meta('_spotplayer_course') : ''; ?>
	<div id="spotplayer-product" class="panel woocommerce_options_panel">
		<div class="options_group">
			<p class="form-field">
				<label>دوره‌های اسپات پلیر</label>
				<?php spot_woo_courses_select('_spotplayer_course[]', $current) ?>
				<span class="description" style="display:block;margin-top:4px">دوره‌هایی که خریدار این محصول به آن‌ها دسترسی خواهد داشت.</span>
			</p>
		</div>
	</div>
<?php }

add_action('woocommerce_admin_process_product_object', 'spot_woo_admin_product_update');
function spot_woo_admin_product_update(WC_Product $product) {
	$raw = array_filter(array_map('sanitize_text_field', (array)($_POST['_spotplayer_course'] ?? [])));
	spot_woo_admin_product_save($product, implode(',', $raw));
}

add_action('woocommerce_product_after_variable_attributes', 'spot_woo_admin_variation_panel', 10, 2);
function spot_woo_admin_variation_panel(int $i, $data) {
	$current = (string)($data['_spotplayer_course'][0] ?? ''); ?>
	<div class="form-row form-row-full" style="padding:12px">
		<label style="font-weight:600;display:block;margin-bottom:6px">دوره‌های اسپات پلیر</label>
		<?php spot_woo_courses_select("spotplayer_course[{$i}][]", $current) ?>
	</div>
<?php }

add_action('woocommerce_admin_process_variation_object', 'spot_woo_admin_variation_update', 10, 2);
function spot_woo_admin_variation_update(WC_Product_Variation $variation, int $i) {
	$raw = array_filter(array_map('sanitize_text_field', (array)($_POST['spotplayer_course'][$i] ?? [])));
	spot_woo_admin_product_save($variation, implode(',', $raw));
}

function spot_woo_admin_product_save($product, $course) {
	if (!current_user_can('administrator')) return;
	if (preg_match('/^[0-9a-f]{24}(,[0-9a-f]{24})*$/i', $course)) {
		$product->update_meta_data('_spotplayer_course', $course);
		$product->set_virtual(true);
		$product->set_sold_individually(true);
	} else $product->update_meta_data('_spotplayer_course', '');
}

<?php
if (!defined('ABSPATH')) exit;

function spot_admin_order_box($data) {
	$texts   = @$data['watermark']['texts'];
	$disable = @$data['_id'] ? 'disabled readonly' : '';
	wp_nonce_field('spot_order_save', 'spot_order_nonce'); ?>
	<table class="widefat" style="border: none">
		<tr>
			<td>شناسه:</td>
			<td>
				<input type="text" class="ltr" name="spot-id" value="<?= esc_attr(@$data['_id']) ?>" <?= $disable ?>/>
				<?php if (!$disable) { ?>
					<button type="submit" name="spot-retrieve" value="1">دریافت اطلاعات لایسنس با شناسه</button>
				<?php } ?>
			</td>
		</tr>
		<tr>
			<td>نام:</td>
			<td><input type="text" name="spot-name" value="<?= esc_attr($data['name'] ?? '') ?>" <?= $disable ?>/></td>
		</tr>
		<?php for ($i = 0; $i < 3; $i++) { ?>
			<tr>
				<td>واترمارک <?= $i + 1 ?>:</td>
				<td><input type="text" class="ltr" name="spot-text[<?= $i ?>]" value="<?= esc_attr(@$texts[$i]['text']) ?>" <?= $disable ?>/></td>
			</tr>
		<?php } ?>
		<tr>
			<td></td>
			<td>
				<?php if (!$disable) { ?>
					<button type="submit" name="spot-create" value="1">ایجاد لایسنس</button>
				<?php } ?>
			</td>
		</tr>
	</table>
	<?php
}

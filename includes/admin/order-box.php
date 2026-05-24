<?php
if (!defined('ABSPATH')) exit;

function spot_admin_order_box($data, $order = null) {
	$texts    = @$data['watermark']['texts'];
	$has_id   = !empty($data['_id']);
	$sp       = get_option('spotplayer');
	$status   = $order ? $order->get_status() : '';

	$auto_create = !$has_id && $order && (
		$status === 'completed' ||
		($status === 'processing' && !@$sp['completed'])
	);

	if ($auto_create) {
		$nonce = wp_create_nonce('spot_auto_create_' . $order->get_id()); ?>
		<div id="spot-creating" style="padding:16px 8px;text-align:center">
			<div id="spot-spin" style="display:inline-block;width:22px;height:22px;border:3px solid #ddd;border-top-color:#2271b1;border-radius:50%;animation:spot-spin .8s linear infinite;vertical-align:middle"></div>
			<span id="spot-msg" style="margin-right:10px;color:#444">در حال ایجاد لایسنس، لطفاً صبر کنید…</span>
			<p id="spot-err" style="display:none;color:#c00;margin:8px 0 0"></p>
		</div>
		<style>@keyframes spot-spin{to{transform:rotate(360deg)}}</style>
		<script>(function(){
			var ajax=<?= json_encode(admin_url('admin-ajax.php')) ?>,
				nonce=<?= json_encode($nonce) ?>,
				oid=<?= (int) $order->get_id() ?>,
				n=0;
			function go(){
				var fd=new FormData();
				fd.append('action','spot_auto_create');
				fd.append('order_id',oid);
				fd.append('nonce',nonce);
				fetch(ajax,{method:'POST',body:fd})
					.then(function(r){return r.json()})
					.then(function(res){
						if(res.success){location.reload()}
						else if(++n<3){setTimeout(go,5000)}
						else{
							document.getElementById('spot-spin').style.display='none';
							var e=document.getElementById('spot-err');
							e.textContent='خطا در ایجاد لایسنس: '+(res.data||'نامشخص');
							e.style.display='block';
						}
					})
					.catch(function(){if(++n<3)setTimeout(go,5000)});
			}
			go();
		})();</script>
		<?php return;
	}

	$disable = $has_id ? 'disabled readonly' : '';
	wp_nonce_field('spot_order_save', 'spot_order_nonce'); ?>
	<table class="widefat" style="border: none">
		<tr>
			<td>شناسه:</td>
			<td>
				<input type="text" class="ltr" name="spot-id" value="<?= esc_attr(@$data['_id']) ?>" <?= $disable ?>/>
				<?php if ($disable && @$data['_id']) { ?>
					<a href="https://panel.spotplayer.ir/license/edit/<?= esc_attr($data['_id']) ?>" target="_blank" class="button button-small" style="margin-right:6px">مشاهده در پنل ↗</a>
				<?php } ?>
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

<?php
if (!defined('ABSPATH')) exit;

function spot_admin_order_box($data, $order = null) {
	$sp      = get_option('spotplayer');
	$status  = $order ? $order->get_status() : '';
	$has_id  = !empty($data['_id']);

	$fatal_error = $order ? $order->get_meta('_spot_fatal_error') : '';

	$auto_create = !$has_id && !$fatal_error && $order && (
		$status === 'completed' ||
		($status === 'processing' && !@$sp['completed'])
	);

	// ── حالت ۱: در حال ایجاد لایسنس ─────────────────────────────────────────
	if ($auto_create) {
		$nonce = wp_create_nonce('spot_auto_create_' . $order->get_id()); ?>
		<div id="spot-creating" style="text-align:center;padding:8px 0">
			<div id="spot-spin" style="display:inline-block;width:20px;height:20px;border:3px solid #ddd;border-top-color:#2271b1;border-radius:50%;animation:spot-spin .8s linear infinite;vertical-align:middle"></div>
			<span id="spot-msg" style="margin-right:8px;color:#444;font-size:13px">در حال ایجاد لایسنس…</span>
			<div id="spot-err" style="display:none;margin-top:10px">
				<p style="color:#c00;font-size:12px;margin:0 0 6px" id="spot-err-txt"></p>
				<button type="button" class="button button-small" id="spot-retry">تلاش مجدد</button>
			</div>
		</div>
		<style>@keyframes spot-spin{to{transform:rotate(360deg)}}</style>
		<script>(function(){
			var ajax=<?= json_encode(admin_url('admin-ajax.php')) ?>,
				nonce=<?= json_encode($nonce) ?>,
				oid=<?= (int) $order->get_id() ?>;

			function go(){
				document.getElementById('spot-spin').style.display='inline-block';
				document.getElementById('spot-msg').style.display='inline';
				document.getElementById('spot-err').style.display='none';
				var fd=new FormData();
				fd.append('action','spot_auto_create');
				fd.append('order_id',oid);
				fd.append('nonce',nonce);
				fetch(ajax,{method:'POST',body:fd})
					.then(function(r){return r.json()})
					.then(function(res){
						if(res.success||res.data==='reload'){location.reload()}
						else{showErr(typeof res.data==='string'?res.data:'خطای نامشخص')}
					})
					.catch(function(){showErr('خطا در ارتباط با سرور')});
			}

			function showErr(msg){
				document.getElementById('spot-spin').style.display='none';
				document.getElementById('spot-msg').style.display='none';
				document.getElementById('spot-err-txt').textContent=msg;
				document.getElementById('spot-err').style.display='block';
			}

			document.getElementById('spot-retry').addEventListener('click',go);
			go();
		})();</script>
		<?php return;
	}

	// ── حالت ۱.۵: خطای غیرقابل تلاش مجدد ──────────────────────────────────────
	if ($fatal_error) { ?>
		<p style="color:#c00;font-size:12px;margin:0 0 4px">
			<b>ایجاد لایسنس ناموفق بود:</b><br><?= esc_html($fatal_error) ?>
		</p>
	<?php return; }

	// ── حالت ۲: لایسنس موجود است ─────────────────────────────────────────────
	if ($has_id) {
		$key = $data['key'] ?? '';
		$id  = $data['_id']; ?>
		<div style="display:flex;flex-direction:column;gap:6px"> <?php if ($key) { ?>
			<button type="button" class="button" id="spot-copy-btn" data-key="<?= esc_attr($key) ?>" style="width:100%;text-align:center">
				کپی لایسنس
			</button>
		<?php } ?>
			<a href="https://panel.spotplayer.ir/license/edit/<?= esc_attr($id) ?>" target="_blank" class="button" style="width:100%;text-align:center;box-sizing:border-box">
				مشاهده در پنل ↗
			</a>
		</div>
		<script>(function(){
			var btn=document.getElementById('spot-copy-btn');
			if(!btn)return;
			btn.addEventListener('click',function(){
				var key=btn.dataset.key,orig=btn.textContent.trim();
				function done(){btn.textContent='✓ کپی شد';setTimeout(function(){btn.textContent=orig;},2000);}
				function legacy(){var t=document.createElement('textarea');t.value=key;t.style.cssText='position:absolute;opacity:0';document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);}
				navigator.clipboard?navigator.clipboard.writeText(key).then(done).catch(function(){legacy();done();}):done(legacy());
			});
		})();</script>
		<?php return;
	}

	// ── حالت ۳: لایسنس ندارد (وضعیت‌های دیگر) ───────────────────────────────
	echo '<p style="color:#888;font-size:12px;margin:0">لایسنس هنوز ایجاد نشده است.</p>';
}

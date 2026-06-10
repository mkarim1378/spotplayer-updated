<?php
if (!defined('ABSPATH')) exit;

function spot_admin_order_box($data, $order = null) {
	$sp      = get_option('spotplayer');
	$status  = $order ? $order->get_status() : '';
	$has_id  = !empty($data['_id']);

	$fatal_error = $order ? $order->get_meta('_spot_fatal_error') : '';

	$is_admin_order = $order && $order->get_created_via() === 'admin';
	$auto_create = !$has_id && !$fatal_error && $order && !$is_admin_order && (
		$status === 'completed' ||
		($status === 'processing' && !@$sp['completed'])
	);

	// Per-order test flag — defaults to unchecked
	$raw_test  = $order ? $order->get_meta('_spot_test') : '';
	$is_test   = $raw_test !== '' && (bool) $raw_test;
	$test_attr = $is_test ? 'checked' : '';

	// ── حالت ۱: در حال ایجاد لایسنس ─────────────────────────────────────────
	if ($auto_create) {
		$nonce_create = wp_create_nonce('spot_auto_create_' . $order->get_id());
		$nonce_flag   = wp_create_nonce('spot_set_test_' . $order->get_id()); ?>
		<div style="margin-bottom:8px;font-size:12px">
			<label style="cursor:pointer">
				<input type="checkbox" id="spot-test-flag" <?= $test_attr ?>> لایسنس تستی
			</label>
		</div>
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
				nonceCreate=<?= json_encode($nonce_create) ?>,
				nonceFlag=<?= json_encode($nonce_flag) ?>,
				oid=<?= (int) $order->get_id() ?>;

			function saveFlag(done){
				var fd=new FormData();
				fd.append('action','spot_set_test_flag');
				fd.append('order_id',oid);
				fd.append('nonce',nonceFlag);
				fd.append('test',document.getElementById('spot-test-flag').checked?1:0);
				fetch(ajax,{method:'POST',body:fd}).then(done).catch(done);
			}

			function go(){
				document.getElementById('spot-spin').style.display='inline-block';
				document.getElementById('spot-msg').style.display='inline';
				document.getElementById('spot-err').style.display='none';
				saveFlag(function(){
					var fd=new FormData();
					fd.append('action','spot_auto_create');
					fd.append('order_id',oid);
					fd.append('nonce',nonceCreate);
					fetch(ajax,{method:'POST',body:fd})
						.then(function(r){return r.json()})
						.then(function(res){
							if(res.success||res.data==='reload'){location.reload()}
							else{showErr(typeof res.data==='string'?res.data:'خطای نامشخص')}
						})
						.catch(function(){showErr('خطا در ارتباط با سرور')});
				});
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
		<?php

		// ── وضعیت پیامک ──────────────────────────────────────────────────────
		if ($order) spot_sms_admin_section($order, $order->get_id(), 'woo');

		return;
	}

	// ── حالت ۳: لایسنس ندارد (وضعیت‌های دیگر) ───────────────────────────────
	if ($order) {
		$nonce_create = wp_create_nonce('spot_auto_create_' . $order->get_id());
		$nonce_flag   = wp_create_nonce('spot_set_test_' . $order->get_id()); ?>
		<p style="color:#888;font-size:12px;margin:0 0 8px">لایسنس هنوز ایجاد نشده است.</p>
		<div style="margin-bottom:8px;font-size:12px">
			<label style="cursor:pointer">
				<input type="checkbox" id="spot-test-flag" <?= $test_attr ?>> لایسنس تستی
			</label>
		</div>
		<button type="button" class="button button-primary" id="spot-create-btn" style="width:100%;text-align:center">ایجاد لایسنس</button>
		<div id="spot-create-err" style="display:none;color:#c00;font-size:12px;margin-top:6px"></div>
		<script>(function(){
			var ajax=<?= json_encode(admin_url('admin-ajax.php')) ?>,
				nonceCreate=<?= json_encode($nonce_create) ?>,
				nonceFlag=<?= json_encode($nonce_flag) ?>,
				oid=<?= (int) $order->get_id() ?>;

			var btn=document.getElementById('spot-create-btn');
			var errEl=document.getElementById('spot-create-err');
			var cb=document.getElementById('spot-test-flag');

			// Persist the test-flag immediately so it's already saved if the order
			// is later marked completed via WooCommerce's own "Update" button
			// (which triggers automatic license creation without going through this box).
			cb.addEventListener('change',function(){
				var fd=new FormData();
				fd.append('action','spot_set_test_flag');
				fd.append('order_id',oid);
				fd.append('nonce',nonceFlag);
				fd.append('test',cb.checked?1:0);
				fetch(ajax,{method:'POST',body:fd});
			});

			btn.addEventListener('click',function(){
				btn.disabled=true;
				btn.textContent='در حال ایجاد…';
				errEl.style.display='none';

				var fd1=new FormData();
				fd1.append('action','spot_set_test_flag');
				fd1.append('order_id',oid);
				fd1.append('nonce',nonceFlag);
				fd1.append('test',cb.checked?1:0);

				fetch(ajax,{method:'POST',body:fd1})
					.then(function(){
						var fd2=new FormData();
						fd2.append('action','spot_auto_create');
						fd2.append('order_id',oid);
						fd2.append('nonce',nonceCreate);
						return fetch(ajax,{method:'POST',body:fd2});
					})
					.then(function(r){return r.json()})
					.then(function(res){
						if(res.success||res.data==='reload'){location.reload()}
						else{
							btn.disabled=false;
							btn.textContent='ایجاد لایسنس';
							errEl.textContent=typeof res.data==='string'?res.data:'خطای نامشخص';
							errEl.style.display='block';
						}
					})
					.catch(function(){
						btn.disabled=false;
						btn.textContent='ایجاد لایسنس';
						errEl.textContent='خطا در ارتباط با سرور';
						errEl.style.display='block';
					});
			});
		})();</script>
		<?php return;
	}

	echo '<p style="color:#888;font-size:12px;margin:0">لایسنس هنوز ایجاد نشده است.</p>';
}

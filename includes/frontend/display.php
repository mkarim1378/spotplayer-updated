<?php
if (!defined('ABSPATH')) exit;

function spot_url_handler() {
	$p = str_replace(parse_url(get_home_url(), PHP_URL_PATH), '', $_SERVER['REQUEST_URI']);
	$s = substr($p, 0, 6);
	if ($s == '/spotx') spot_shop_x();
	if ($s == '/spdeb') spot_debug();
}
add_action('parse_request', 'spot_url_handler');

function spot_debug() {
	current_user_can('administrator') or die('Access denied');
	header('Content-Type: application/json');
	if (spot_woo_or_edd() == 1) {
		$o = wc_get_order($_GET['id']);
		header('Content-Disposition: attachment; filename=debug-' . $o->get_id() . '.json');
		die(json_encode([
			'code' => spot_license_code(),
			'user' => get_user_meta($o->get_user_id()),
			'data' => $o->get_data(),
			'meta' => $o->get_meta_data(),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRETTY_PRINT));
	} else {
		$p = edd_get_payment($_GET['id']);
		header('Content-Disposition: attachment; filename=debug-' . $p->ID . '.json');
		die(json_encode([
			'code' => spot_license_code(),
			'user' => get_user_meta($p->user_id),
			'data' => $p,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRETTY_PRINT));
	}
}

function spot_shop_x() {
	if (empty($_COOKIE['X'])) die();
	$O = $_COOKIE['X'];
	if ((microtime(true) * 1000) > hexdec(substr($O, 24, 12))) {
		$N = Requests::head('https://app.spotplayer.ir/', ['cookie' => 'X=' . $O], ['verify' => false, 'verifyname' => false])->cookies['X'];
		setcookie('X', $N, time() + 9e9, '/', parse_url(get_home_url(), PHP_URL_HOST), true, false);
	}
	die();
}

function spot_shop_failed($err) { ?>
	<div id="spot_fail">
		<p><?= $err ?></p>
		<button onclick="window.location.reload();">تلاش مجدد</button>
	</div>
<?php }

function spot_shop_success($data, $product = '', $course = null) {
	if (!$data) return;

	$sp     = get_option('spotplayer');
	$domain = $sp['domain'] ?: 'app.spotplayer.ir'; ?>
	<script type="application/javascript">
		function copy(txt, lbl) {
			try {
				navigator.clipboard.writeText(txt).catch(function () { copyLegacy(txt); });
			} catch (e) {
				copyLegacy(txt);
			} finally {
				alert(lbl + ' به کلیپ بورد کپی شد.');
			}
		}

		function copyLegacy(txt) {
			const el = document.createElement('textarea');
			el.value = txt;
			el.style.position = 'absolute';
			el.style.opacity = '0';
			document.body.appendChild(el);
			el.select();
			document.execCommand('copy');
			document.body.removeChild(el);
		}

		function toggle(el) {
			el.className = el.className === 'active' ? '' : 'active';
		}

		/** @type {[{name: string, file: string, image: string, version: number, disable: boolean}]} */
		let spotplayer_players;
		/** @type {[{_id: string, name: string, items: [{_id: string, type: string, name: string, desc: string}]}]} */
		let spotplayer_courses;
	</script>
	<div id="sp">
		<?php if ($product) { ?><h1><?= $product ?></h1><?php } ?>
		<div id="sp-warn">مطالب این دوره دارای واترمارک‌های پیدا و پنهان هستند و هر گونه کپی برداری و نشر آن قابل پیگیری بوده و موجب پیگرد قانونی خواهد شد.</div>
		<?php if (@$sp['web']) { ?>
			<div id="sp-web">
				<h2>مشاهده در پلیر وب</h2>
				<p>توجه داشته باشید پس از فعال کردن لایسنس در این مرورگر، فقط در همین دستگاه و مرورگر میتوانید دوره را مشاهده کنید و همچنین یک دستگاه از ظرفیت لایسنس کم خواهد شد.</p>
				<div id="spotplayer"></div>
				<!--suppress JSUnresolvedLibraryURL -->
				<script src="https://<?= $domain ?>/assets/js/app-api.js"></script>
				<!--suppress JSUnresolvedFunction -->
				<script type="application/javascript">
					(async function () {
						(new SpotPlayer(document.getElementById('spotplayer'), '<?= parse_url(get_home_url(), PHP_URL_PATH) ?>/spotx'))
							.Open('<?= $data['key'] ?>', <?= preg_match('/^[0-9a-f]{24}$/i', $course) ? "'$course'" : 'null' ?>);
					})();
				</script>
			</div>
		<?php } ?>
		<?php if (!@$sp['webonly']) { ?>
			<div id="sp-app">
				<h2>مشاهده در اپلیکیشن</h2>
				<p>برای مشاهده دوره‌ها ابتدا پلیر را با توجه به سیستم عامل خود دانلود و نصب نمایید. پس از اجرای پلیر، در صفحه ثبت دوره جدید کلید لایسنس را وارد، مکان ذخیره‌سازی را انتخاب و سپس فرم را تایید کنید.</p>

				<div id="sp_players">
					<h3><b>❶</b> دانلود و نصب پلیر</h3>
					<div>
						<script src="https://<?= $domain ?>/player/?f=js&l=<?= $data['_id'] ?>"></script>
						<script type="application/javascript">
							document.write(window.spotplayer_players.map(function (p) {
								return [
									'<a target="_blank" ' + (p.file ? ('href="https://<?= $domain ?>' + p.file + '"') : '') + ' class="' + (p.disable ? 'disable' : '') + '">',
									' <img alt="' + p.name + '" src="https://<?= $domain ?>' + p.image + '">',
									' <b>' + p.name + '</b>',
									' <u>' + (p.file ? p.version : 'به زودی') + '</u>',
									'</a>',
								].join('');
							}).join(''));
						</script>
					</div>
				</div>

				<div id="sp_license">
					<h3><b>❷</b> کپی و وارد نمودن کلید در پلیر</h3>
					<textarea readonly><?= esc_html($data['key']) ?></textarea>
					<button class="sp_color_back" onclick="copy('<?= esc_js($data['key']) ?>', 'کلید لایسنس')">کپی کلید</button>
				</div>

				<?php if (@$sp['download']) { ?>
					<?php $burl = 'https://' . $domain . '/' . $data['_id'] . '/' . md5(hex2bin(substr($data['key'], 24, 64))) . '/'; ?>
					<div id="sp_videos">
						<h3><b>❸</b> دانلود ویدیوها</h3>
						<p>اگرچه پلیر به صورت خودکار فایل‌های دوره را دانلود و در حین دانلود نمایش میدهد، اما میتوانید فایل‌های دوره را به صورت مجزا دانلود کنید.</p>
						<ul>
							<script src="<?= $burl ?>?f=js"></script>
							<script type="application/javascript">
								document.write(window.spotplayer_courses.map(function (c) {
									return [
										'<li><h4 onclick="toggle(this.parentNode)">',
										'<img src="<?= SPOTPLAYER_URL ?>assets/svg/down.svg">' + c.name,
										'</h4><ul>',
										c.items.map(function (v) {
											return [
												'<li class="sp_' + v.type + '"><a href="<?= $burl ?>' + c._id + '/' + v._id + '.spot">',
												'<img src="<?= SPOTPLAYER_URL ?>assets/svg/dl.svg" />' + v.name,
												'</a></li>',
											].join('');
										}).join(''),
										'</ul></li>',
									].join('');
								}).join(''));
							</script>
						</ul>
					</div>
				<?php } ?>
			</div>
		<?php } ?>
	</div>
	<?php
}

function spot_shortcode() {
	$p = spot_woo_or_edd();
	return $p == 1 ? spot_woo_shortcode() : ($p == 2 ? spot_edd_shortcode() : 'ووکامرس یا EDD نصب نشده است.');
}
add_shortcode('spotplayer_courses', 'spot_shortcode');

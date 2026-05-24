<?php
if (!defined('ABSPATH')) exit;

add_action('wp_dashboard_setup', function () {
	wp_add_dashboard_widget('spot_dashboard_widget', '🎬 اسپات پلیر', 'spot_dashboard_widget_render');
});

function spot_dashboard_widget_render() {
	$data = get_transient('spot_dashboard_stats');

	if ($data === false) {
		$data = spot_dashboard_compute_stats();
		set_transient('spot_dashboard_stats', $data, 15 * MINUTE_IN_SECONDS);
	}

	$settings_url = admin_url('admin.php?page=spotplayer');
	?>
	<style>
	#spot_dashboard_widget .spot-stat { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f0f0f1; }
	#spot_dashboard_widget .spot-stat:last-of-type { border-bottom:none; }
	#spot_dashboard_widget .spot-stat-label { color:#50575e; font-size:13px; }
	#spot_dashboard_widget .spot-stat-value { font-weight:700; font-size:18px; color:#1d2327; }
	#spot_dashboard_widget .spot-stat-value.warn { color:#b32d2e; }
	#spot_dashboard_widget .spot-footer { margin-top:12px; padding-top:10px; border-top:1px solid #f0f0f1; font-size:12px; color:#8c8f94; display:flex; justify-content:space-between; align-items:center; }
	</style>

	<div class="spot-stat">
		<span class="spot-stat-label">لایسنس‌های ساخته‌شده (۷ روز گذشته)</span>
		<span class="spot-stat-value"><?= number_format_i18n($data['recent']) ?></span>
	</div>
	<div class="spot-stat">
		<span class="spot-stat-label">سفارشات بدون لایسنس</span>
		<span class="spot-stat-value <?= $data['missing'] > 0 ? 'warn' : '' ?>"><?= number_format_i18n($data['missing']) ?></span>
	</div>
	<div class="spot-footer">
		<span>آخرین بروزرسانی: <?= current_time('H:i') ?></span>
		<a href="<?= esc_url($settings_url) ?>">تنظیمات اسپات پلیر ←</a>
	</div>
	<?php
}

function spot_dashboard_compute_stats() {
	$recent  = 0;
	$missing = 0;

	if (function_exists('wc_get_orders')) {
		$week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));

		// لایسنس‌های ساخته‌شده در ۷ روز گذشته
		$recent_orders = wc_get_orders([
			'limit'      => -1,
			'status'     => ['processing', 'completed'],
			'date_after' => $week_ago,
			'meta_query' => [['key' => '_spotplayer_data', 'compare' => 'EXISTS']],
			'return'     => 'ids',
		]);
		$recent += count($recent_orders);

		// سفارشات تکمیل‌شده بدون لایسنس که محصول اسپات پلیر دارند
		$all_orders = wc_get_orders([
			'limit'      => -1,
			'status'     => ['processing', 'completed'],
			'meta_query' => [['key' => '_spotplayer_data', 'compare' => 'NOT EXISTS']],
			'return'     => 'ids',
		]);
		foreach ($all_orders as $oid) {
			$ord = wc_get_order($oid);
			if ($ord && count(spot_woo_order_items($ord))) $missing++;
		}
	}

	return ['recent' => $recent, 'missing' => $missing];
}

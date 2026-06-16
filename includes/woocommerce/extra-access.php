<?php
if (!defined('ABSPATH')) exit;

// ── Endpoint registration ────────────────────────────────────────────────────

function spot_extra_endpoint_init(): void {
	add_rewrite_endpoint('license-request', EP_PAGES);
}
add_action('init', 'spot_extra_endpoint_init');

// ── Account menu item ────────────────────────────────────────────────────────

function spot_extra_menu_item(array $links): array {
	$new = ['license-request' => 'درخواست دسترسی اضافه'];
	if (isset($links['licenses'])) {
		$pos = array_search('licenses', array_keys($links), true);
		return array_slice($links, 0, $pos + 1, true)
			+ $new
			+ array_slice($links, $pos + 1, null, true);
	}
	return array_slice($links, 0, 1, true) + $new + array_slice($links, 1, null, true);
}
add_filter('woocommerce_account_menu_items', 'spot_extra_menu_item', 51);

// ── Endpoint content ─────────────────────────────────────────────────────────

function spot_extra_render_page(): void {
	if (!is_user_logged_in()) {
		wp_safe_redirect(wc_get_account_endpoint_url('dashboard'));
		exit;
	}
	?>
	<style>
	.spot-extra-wrap { max-width:640px; direction:rtl; }
	.spot-extra-form {
		background:#fff; border:1px solid #e2e8f0; border-radius:8px;
		padding:28px; margin-bottom:28px;
		box-shadow:0 1px 4px rgba(0,0,0,.07);
	}
	.spot-extra-form .form-row { margin-bottom:18px; }
	.spot-extra-form .form-row > label {
		display:block; font-weight:600; margin-bottom:6px;
		color:#374151; font-size:14px;
	}
	.spot-extra-form .input-text,
	.spot-extra-form select {
		border:1px solid #d1d5db; border-radius:5px;
		padding:9px 12px; font-size:14px;
		width:100%; max-width:360px; box-sizing:border-box;
	}
	.spot-extra-form .input-text:focus,
	.spot-extra-form select:focus {
		border-color:#6366f1; outline:none;
		box-shadow:0 0 0 3px rgba(99,102,241,.15);
	}
	#spot-extra-price-row {
		background:#f0fdf4; border:1px solid #bbf7d0;
		border-radius:6px; padding:14px 16px; margin-bottom:18px;
	}
	#spot-extra-price-row > label { color:#166534; }
	#spot-extra-price { font-size:18px; font-weight:700; color:#15803d; }
	#spot-extra-stage { font-size:12px; color:#6b7280; margin-right:6px; }
	#spot-extra-submit-btn {
		background:#4f46e5; border-color:#4338ca; color:#fff;
		padding:10px 24px; font-size:15px; border-radius:6px; cursor:pointer;
	}
	#spot-extra-submit-btn:hover:not(:disabled) { background:#4338ca; }
	#spot-extra-submit-btn:disabled {
		background:#9ca3af; border-color:#9ca3af; cursor:not-allowed;
	}
	.spot-extra-history { width:100%; border-collapse:collapse; font-size:13px; }
	.spot-extra-history th {
		background:#f9fafb; padding:8px 12px;
		border:1px solid #e5e7eb; font-weight:600;
	}
	.spot-extra-history td { padding:8px 12px; border:1px solid #e5e7eb; }
	</style>
	<?php

	$uid      = get_current_user_id();
	$customer = new WC_Customer($uid);

	// Licensed orders for this customer (not extra-request orders themselves)
	$licensed_orders = wc_get_orders([
		'customer'   => $uid,
		'limit'      => -1,
		'status'     => ['processing', 'completed'],
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => [
			'relation' => 'AND',
			['key' => '_spotplayer_data',    'compare' => 'EXISTS'],
			['key' => '_spot_extra_request', 'compare' => 'NOT EXISTS'],
		],
	]);

	// Batch-load all extra request orders for this user in ONE query
	$all_extra = wc_get_orders([
		'customer'   => $uid,
		'limit'      => -1,
		'status'     => ['processing', 'completed', 'pending', 'on-hold'],
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => [['key' => '_spot_extra_request', 'value' => '1']],
	]);
	$paid_counts   = [];   // origin_id => count of paid requests
	$pending_set   = [];   // origin_id => true if a pending request exists
	$past_requests = [];   // paid extra orders for history table
	foreach ($all_extra as $ex) {
		$oid    = (int) $ex->get_meta('_spot_extra_origin_order');
		$status = $ex->get_status();
		if (in_array($status, ['processing', 'completed'], true)) {
			$paid_counts[$oid] = ($paid_counts[$oid] ?? 0) + 1;
			$past_requests[]   = $ex;
		} elseif (in_array($status, ['pending', 'on-hold'], true)) {
			$pending_set[$oid] = true;
		}
	}

	// Build origin_map reusing already-loaded licensed orders;
	// batch-fetch any origins needed for past_requests that aren't in licensed_orders
	$origin_map = [];
	foreach ($licensed_orders as $ord) $origin_map[$ord->get_id()] = $ord;
	$missing = array_unique(array_filter(array_map(function ($r) use ($origin_map) {
		$oid = (int) $r->get_meta('_spot_extra_origin_order');
		return isset($origin_map[$oid]) ? null : $oid;
	}, $past_requests)));
	if (!empty($missing)) {
		foreach (wc_get_orders(['include' => $missing, 'limit' => count($missing)]) as $o)
			$origin_map[$o->get_id()] = $o;
	}

	$error = get_transient('spot_extra_error_' . $uid);
	if ($error) delete_transient('spot_extra_error_' . $uid);

	$full_name     = trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
	$billing_phone = $customer->get_billing_phone();

	// Build dropdown options and JS price map using pre-computed data (no per-order queries)
	$order_options = [];
	$price_map     = [];
	foreach ($licensed_orders as $ord) {
		$oid        = $ord->get_id();
		$products   = spot_woo_order_items($ord, true);
		$names      = array_map(function ($p) { return $p->get_name(); }, $products);
		$base_label = implode('، ', $names) ?: 'سفارش #' . $oid;

		$has_pending = isset($pending_set[$oid]);
		$calc        = spot_extra_calc_price_from_count($oid, $paid_counts[$oid] ?? 0, $ord, spot_extra_resolve_course_config($ord));
		$is_blocked  = $calc['blocked'] || $has_pending;

		if ($has_pending) {
			$suffix = ' (در انتظار پرداخت)';
		} elseif ($calc['blocked']) {
			$suffix = ' (سقف درخواست)';
		} else {
			$suffix = '';
		}

		$order_options[] = [
			'id'      => $oid,
			'label'   => $base_label . ' — #' . $oid . $suffix,
			'blocked' => $is_blocked,
		];
		$price_map[$oid] = [
			'price'   => $calc['price'],
			'stage'   => $calc['stage'],
			'blocked' => $is_blocked,
		];
	}

	$has_options = !empty($order_options);
	?>
	<div class="spot-extra-wrap">

		<?php if ($error): ?>
			<ul class="woocommerce-error"><li><?= esc_html($error) ?></li></ul>
		<?php endif ?>

		<form method="post" class="spot-extra-form">
			<?php wp_nonce_field('spot_extra_submit', 'spot_extra_nonce') ?>
			<input type="hidden" name="spot_extra_submit" value="1">

			<p class="form-row">
				<label>نام مشتری</label>
				<strong><?= esc_html($full_name ?: '—') ?></strong>
			</p>

			<p class="form-row">
				<label for="spot_extra_phone">شماره موبایل</label>
				<input type="tel" id="spot_extra_phone" name="spot_extra_phone"
				       value="<?= esc_attr($billing_phone) ?>"
				       placeholder="09XXXXXXXXX" maxlength="11" class="input-text">
			</p>

			<p class="form-row">
				<label for="spot_extra_origin_order">لایسنس مورد نظر</label>
				<?php if (!$has_options): ?>
					<span>هیچ سفارش لایسنس‌داری یافت نشد.</span>
				<?php else: ?>
					<select id="spot_extra_origin_order" name="spot_extra_origin_order" class="input-text">
						<option value="">-- انتخاب کنید --</option>
						<?php foreach ($order_options as $opt): ?>
							<option value="<?= esc_attr($opt['id']) ?>"
							        <?php disabled($opt['blocked']) ?>>
								<?= esc_html($opt['label']) ?>
							</option>
						<?php endforeach ?>
					</select>
				<?php endif ?>
			</p>

			<p class="form-row" id="spot-extra-price-row" style="display:none">
				<label>مبلغ پرداختی</label>
				<strong id="spot-extra-price"></strong>
				<em id="spot-extra-stage" style="margin-right:6px;color:#888"></em>
			</p>

			<?php if ($has_options): ?>
				<p class="form-row">
					<button type="submit" id="spot-extra-submit-btn" class="button" disabled>
						پرداخت و ثبت درخواست
					</button>
				</p>
			<?php endif ?>
		</form>

		<?php if (!empty($past_requests)): ?>
			<h3 style="margin-top:2rem">درخواست‌های قبلی</h3>
			<table class="shop_table spot-extra-history">
				<thead>
					<tr>
						<th>شماره</th>
						<th>لایسنس</th>
						<th>مرحله</th>
						<th>مبلغ</th>
						<th>تاریخ</th>
						<th>وضعیت</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($past_requests as $req):
						$origin_id    = (int) $req->get_meta('_spot_extra_origin_order');
						$stage        = (int) $req->get_meta('_spot_extra_stage');
						$origin_order = $origin_map[$origin_id] ?? null;
						$c_names      = [];
						if ($origin_order instanceof WC_Order) {
							foreach (spot_woo_order_items($origin_order, true) as $p)
								$c_names[] = $p->get_name();
						}
					?>
					<tr>
						<td>#<?= $req->get_id() ?></td>
						<td><?= esc_html(implode('، ', $c_names) ?: '#' . $origin_id) ?></td>
						<td><?= esc_html($stage) ?></td>
						<td><?= wc_price($req->get_total()) ?></td>
						<td><?= esc_html(wc_format_datetime($req->get_date_created())) ?></td>
						<td><?= esc_html(wc_get_order_status_name($req->get_status())) ?></td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		<?php endif ?>

	</div>
	<script>
	(function () {
		var map     = <?= wp_json_encode($price_map) ?>;
		var sel     = document.getElementById('spot_extra_origin_order');
		var priceRow = document.getElementById('spot-extra-price-row');
		var priceEl  = document.getElementById('spot-extra-price');
		var stageEl  = document.getElementById('spot-extra-stage');
		var btn      = document.getElementById('spot-extra-submit-btn');
		if (!sel) return;
		sel.addEventListener('change', function () {
			var oid = this.value;
			var d   = oid ? map[oid] : null;
			var ok  = d && !d.blocked;
			if (priceRow) priceRow.style.display = ok ? '' : 'none';
			if (btn)      btn.disabled = !ok;
			if (ok) {
				priceEl.textContent = Number(d.price).toLocaleString('fa-IR') + ' تومان';
				stageEl.textContent = '(مرحله ' + d.stage + ')';
			}
		});
	})();
	</script>
	<?php
}
add_action('woocommerce_account_license-request_endpoint', 'spot_extra_render_page');

// ── Form submit handler ──────────────────────────────────────────────────────

function spot_extra_handle_submit(): void {
	if (!isset($_POST['spot_extra_submit'])) return;
	if (!is_user_logged_in()) {
		wp_safe_redirect(wc_get_account_endpoint_url('dashboard'));
		exit;
	}

	$uid      = get_current_user_id();
	$page_url = wc_get_account_endpoint_url('license-request');

	$set_error = static function (string $msg) use ($uid, $page_url): void {
		set_transient('spot_extra_error_' . $uid, $msg, 60);
		wp_safe_redirect($page_url);
		exit;
	};

	if (!isset($_POST['spot_extra_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['spot_extra_nonce'])), 'spot_extra_submit')) {
		$set_error('خطای امنیتی. لطفاً دوباره تلاش کنید.');
	}

	$origin_id = absint($_POST['spot_extra_origin_order'] ?? 0);
	$phone     = spot_sms_normalize_phone(sanitize_text_field(wp_unslash($_POST['spot_extra_phone'] ?? '')));

	if (!$origin_id) {
		$set_error('لطفاً یک سفارش انتخاب کنید.');
	}

	$origin_order = wc_get_order($origin_id);
	if (!($origin_order instanceof WC_Order) || (int) $origin_order->get_customer_id() !== $uid) {
		$set_error('سفارش مورد نظر یافت نشد.');
	}

	$spot_data = $origin_order->get_meta('_spotplayer_data');
	if (empty($spot_data['_id'])) {
		$set_error('این سفارش دارای لایسنس اسپات نیست.');
	}

	if (spot_extra_has_pending($origin_id)) {
		$set_error('یک درخواست پرداخت‌نشده برای این لایسنس در انتظار است. لطفاً ابتدا آن را تکمیل یا لغو کنید.');
	}

	$calc = spot_extra_calc_price($origin_id);
	if ($calc['blocked']) {
		$set_error('به سقف درخواست دسترسی اضافه رسیده‌اید.');
	}

	// Copy billing address from the original order
	$customer = new WC_Customer($uid);
	$new_order = wc_create_order(['customer_id' => $uid]);

	$new_order->set_billing_first_name($customer->get_billing_first_name());
	$new_order->set_billing_last_name($customer->get_billing_last_name());
	$new_order->set_billing_email($customer->get_billing_email());
	$new_order->set_billing_phone($phone ?: $customer->get_billing_phone());
	$new_order->set_billing_address_1($customer->get_billing_address_1());
	$new_order->set_billing_city($customer->get_billing_city());
	$new_order->set_billing_country($customer->get_billing_country());

	$fee = new WC_Order_Item_Fee();
	$fee->set_name('درخواست دسترسی اضافه — لایسنس #' . $origin_id);
	$fee->set_amount($calc['price']);
	$fee->set_total($calc['price']);
	$fee->set_tax_status('none');
	$new_order->add_item($fee);

	$new_order->update_meta_data('_spot_extra_request',      '1');
	$new_order->update_meta_data('_spot_extra_origin_order', (string) $origin_id);
	$new_order->update_meta_data('_spot_extra_stage',        (string) $calc['stage']);

	$new_order->calculate_totals();
	$new_order->save();

	wp_safe_redirect($new_order->get_checkout_payment_url());
	exit;
}
add_action('template_redirect', 'spot_extra_handle_submit');

// ── Helper: count paid extra requests for a given origin order ───────────────

function spot_extra_count_paid_requests(int $origin_order_id): int {
	return count(wc_get_orders([
		'limit'      => -1,
		'return'     => 'ids',
		'status'     => ['processing', 'completed'],
		'meta_query' => [
			'relation' => 'AND',
			['key' => '_spot_extra_request',      'value' => '1'],
			['key' => '_spot_extra_origin_order', 'value' => (string) $origin_order_id],
		],
	]));
}

// ── Helper: check if there's already a pending/on-hold extra request ─────────

function spot_extra_has_pending(int $origin_order_id): bool {
	return count(wc_get_orders([
		'limit'      => 1,
		'return'     => 'ids',
		'status'     => ['pending', 'on-hold'],
		'meta_query' => [
			'relation' => 'AND',
			['key' => '_spot_extra_request',      'value' => '1'],
			['key' => '_spot_extra_origin_order', 'value' => (string) $origin_order_id],
		],
	])) > 0;
}

// ── Helper: sum current prices of products in origin order ───────────────────

function spot_extra_origin_total(int $origin_order_id): float {
	$order = wc_get_order($origin_order_id);
	if (!($order instanceof WC_Order)) return 0.0;
	$total = 0.0;
	foreach ($order->get_items() as $item) {
		if (!($item instanceof WC_Order_Item_Product)) continue;
		$product = $item->get_product();
		if (!($product instanceof WC_Product)) continue;
		$total += floatval($product->get_price()) * $item->get_quantity();
	}
	return $total;
}

// ── Helper: resolve per-course pricing config ─────────────────────────────────

/**
 * Returns ['stages' => [...], 'end_mode' => 'max'|'repeat_last'] for the
 * most specific match: course-specific → global fallback.
 */
function spot_extra_resolve_course_config(?WC_Order $origin_order): array {
	$sp  = get_option('spotplayer', []);
	$map = (array) ($sp['extra_course_stages'] ?? []);

	if ($origin_order && !empty($map)) {
		foreach (spot_woo_order_items($origin_order) as $cid) {
			if (!empty($map[$cid]['stages'])) {
				return [
					'stages'   => array_values($map[$cid]['stages']),
					'end_mode' => $map[$cid]['end_mode'] ?? 'max',
				];
			}
		}
	}

	return [
		'stages'   => array_values((array) ($sp['extra_stages'] ?? [])),
		'end_mode' => $sp['extra_end_mode'] ?? 'max',
	];
}

// ── Helper: calculate price and stage — core logic ───────────────────────────

/**
 * Compute price/stage given a pre-known paid count, optionally a pre-loaded
 * WC_Order, and optionally a pre-resolved config (avoids repeated option reads).
 */
function spot_extra_calc_price_from_count(int $origin_order_id, int $paid_count, ?WC_Order $origin_order = null, ?array $config = null): array {
	if ($config === null) {
		$config = spot_extra_resolve_course_config($origin_order);
	}
	$stages = $config['stages'];
	$mode   = $config['end_mode'];
	$stage  = $paid_count + 1;

	if (empty($stages)) return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];

	if ($stage > count($stages)) {
		if ($mode === 'max') return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];
		$def = end($stages);
	} else {
		$def = $stages[$stage - 1];
	}

	$type  = $def['type']  ?? 'fixed';
	$value = floatval($def['value'] ?? 0);

	if ($type === 'percent') {
		if (!($origin_order instanceof WC_Order)) $origin_order = wc_get_order($origin_order_id);
		$total = 0.0;
		if ($origin_order instanceof WC_Order) {
			foreach ($origin_order->get_items() as $item) {
				if (!($item instanceof WC_Order_Item_Product)) continue;
				$product = $item->get_product();
				if (!($product instanceof WC_Product)) continue;
				$total += floatval($product->get_price()) * $item->get_quantity();
			}
		}
		$price = round($value / 100 * $total);
	} else {
		$price = $value;
	}

	return ['blocked' => false, 'stage' => $stage, 'price' => $price];
}

// ── Helper: calculate price and stage for an origin order ────────────────────

function spot_extra_calc_price(int $origin_order_id): array {
	$origin_order = wc_get_order($origin_order_id);
	$order        = ($origin_order instanceof WC_Order) ? $origin_order : null;
	return spot_extra_calc_price_from_count(
		$origin_order_id,
		spot_extra_count_paid_requests($origin_order_id),
		$order,
		spot_extra_resolve_course_config($order)
	);
}

// ── Daily report: next fire timestamp ────────────────────────────────────────

function spot_extra_next_report_timestamp(string $time): int {
	$tz  = wp_timezone();
	$now = new DateTime('now', $tz);
	$dt  = new DateTime('today ' . $time, $tz);
	if ($dt <= $now) $dt->modify('+1 day');
	return $dt->getTimestamp();
}

// ── Daily report: schedule registration ──────────────────────────────────────

function spot_extra_schedule_daily_report(): void {
	$sp          = get_option('spotplayer', []);
	$report_time = (string) ($sp['extra_report_time'] ?? '08:00');
	if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $report_time)) $report_time = '08:00';

	if (function_exists('as_next_scheduled_action')) {
		if (!as_next_scheduled_action('spot_extra_daily_report')) {
			as_schedule_recurring_action(
				spot_extra_next_report_timestamp($report_time),
				DAY_IN_SECONDS,
				'spot_extra_daily_report'
			);
		}
		return;
	}

	// WP-Cron fallback — no exact-time guarantee
	if (!wp_next_scheduled('spot_extra_daily_report'))
		wp_schedule_event(time(), 'daily', 'spot_extra_daily_report');
}
add_action('init', 'spot_extra_schedule_daily_report');

// ── Daily report: reschedule when report time setting changes ─────────────────

function spot_extra_reschedule_report(array $old_value, array $new_value): void {
	if (($old_value['extra_report_time'] ?? '') === ($new_value['extra_report_time'] ?? '')) return;

	if (function_exists('as_unschedule_all_actions')) {
		as_unschedule_all_actions('spot_extra_daily_report');
	} else {
		$ts = wp_next_scheduled('spot_extra_daily_report');
		if ($ts) wp_unschedule_event($ts, 'spot_extra_daily_report');
	}
	// spot_extra_schedule_daily_report() re-registers on the next init
}
add_action('update_option_spotplayer', 'spot_extra_reschedule_report', 10, 2);

// ── Daily report: handler ─────────────────────────────────────────────────────

add_action('spot_extra_daily_report', 'spot_extra_handle_daily_report');
function spot_extra_handle_daily_report(): void {
	if (!spot_sms_is_enabled()) return;

	$sp          = get_option('spotplayer', []);
	$admin_phone = spot_sms_normalize_phone((string) ($sp['extra_admin_phone'] ?? ''));
	if (!$admin_phone) return;

	$count = count(wc_get_orders([
		'limit'        => -1,
		'return'       => 'ids',
		'status'       => ['processing', 'completed'],
		'date_created' => (time() - DAY_IN_SECONDS) . '...' . time(),
		'meta_query'   => [['key' => '_spot_extra_request', 'value' => '1']],
	]));

	if ($count === 0) return;

	$site = get_bloginfo('name');
	spot_sms_send_raw(
		$admin_phone,
		'سلام. در ۲۴ ساعت گذشته ' . $count . ' درخواست دسترسی اضافه لایسنس در ' . $site . ' ثبت شد. لطفاً داشبورد را بررسی کنید.'
	);
}

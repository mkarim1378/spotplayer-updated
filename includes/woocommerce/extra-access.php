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

	// Previous paid extra requests by this user
	$past_requests = wc_get_orders([
		'customer'   => $uid,
		'limit'      => -1,
		'status'     => ['processing', 'completed'],
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => [['key' => '_spot_extra_request', 'value' => '1']],
	]);

	$error = get_transient('spot_extra_error_' . $uid);
	if ($error) delete_transient('spot_extra_error_' . $uid);

	$full_name     = trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
	$billing_phone = $customer->get_billing_phone();

	// Build dropdown options and JS price map
	$order_options = [];
	$price_map     = [];
	foreach ($licensed_orders as $ord) {
		$oid        = $ord->get_id();
		$products   = spot_woo_order_items($ord, true);
		$names      = array_map(fn($p) => $p->get_name(), $products);
		$base_label = implode('، ', $names) ?: 'سفارش #' . $oid;

		$has_pending = spot_extra_has_pending($oid);
		$calc        = spot_extra_calc_price($oid);
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
						$origin_order = $origin_id ? wc_get_order($origin_id) : null;
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

// ── Helper: calculate price and stage for an origin order ────────────────────

function spot_extra_calc_price(int $origin_order_id): array {
	$sp     = get_option('spotplayer', []);
	$stages = array_values((array) ($sp['extra_stages'] ?? []));
	$mode   = $sp['extra_end_mode'] ?? 'max';
	$stage  = spot_extra_count_paid_requests($origin_order_id) + 1;

	if (empty($stages)) return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];

	if ($stage > count($stages)) {
		if ($mode === 'max') return ['blocked' => true, 'stage' => $stage, 'price' => 0.0];
		$def = end($stages); // repeat_last
	} else {
		$def = $stages[$stage - 1];
	}

	$type  = $def['type']  ?? 'fixed';
	$value = floatval($def['value'] ?? 0);
	$price = ($type === 'percent')
		? round($value / 100 * spot_extra_origin_total($origin_order_id))
		: $value;

	return ['blocked' => false, 'stage' => $stage, 'price' => $price];
}

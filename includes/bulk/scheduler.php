<?php
if (!defined('ABSPATH')) exit;

// ─── Action handlers ─────────────────────────────────────────────────────────

add_action('spot_bulk_create_order_row',    'spot_bulk_create_order_row_handler',    10, 1);
add_action('spot_bulk_disable_license_row', 'spot_bulk_disable_license_row_handler', 10, 1);

function spot_bulk_create_order_row_handler(array $args) {
	$product = wc_get_product($args['product_id']);
	if (!$product) return;

	$user_id = spot_find_or_create_user($args['phone'], $args['fname'] ?? '', $args['lname'] ?? '');
	if (!$user_id) return;

	$order = wc_create_order(['customer_id' => $user_id]);
	$order->add_product($product, 1);
	$order->set_billing_phone($args['phone']);
	if (!empty($args['fname'])) $order->set_billing_first_name($args['fname']);
	if (!empty($args['lname'])) $order->set_billing_last_name($args['lname']);
	if (!empty($args['limit_raw'])) $order->update_meta_data('_spot_limit', $args['limit_raw']);
	$order->calculate_totals();
	$order->save();
	$order->update_status('completed', 'ایجاد شده توسط اکسل اسپات پلیر (ناهمزمان)');
}

function spot_bulk_disable_license_row_handler(array $args) {
	$id = $args['id'] ?? '';
	if (!preg_match('/^[0-9a-f]{24}$/i', $id)) return;

	spot_request('https://panel.spotplayer.ir/license/edit/' . $id, [
		'device' => ['p0' => 0, 'p1' => 0, 'p2' => 0, 'p3' => 0, 'p4' => 0, 'p5' => 0, 'p6' => 0],
	]);
}

// ─── Scheduling ───────────────────────────────────────────────────────────────

function spot_bulk_schedule_create_orders(int $product_id, string $limit_raw, string $csv_path): array {
	$result  = ['queued' => 0, 'errors' => []];
	$product = wc_get_product($product_id);

	if (!$product) { $result['errors'][] = 'محصول انتخاب شده معتبر نیست.'; return $result; }
	if (($handle = fopen($csv_path, 'r')) === false) { $result['errors'][] = 'فایل CSV باز نشد.'; return $result; }

	$header  = fgetcsv($handle, 0, ',');
	$col_map = [];
	foreach ($header as $i => $col) {
		$c = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $col)));
		if (in_array($c, ['name', 'first_name', 'firstname', 'نام']))                     $col_map['fname'] = $i;
		if (in_array($c, ['family', 'last_name', 'lastname', 'surname', 'نام خانوادگی'])) $col_map['lname'] = $i;
		if (in_array($c, ['phone', 'mobile', 'number', 'موبایل', 'تلفن', 'شماره']))      $col_map['phone'] = $i;
	}

	if (!isset($col_map['phone'])) {
		$result['errors'][] = 'فایل اکسل باید حتماً ستون موبایل (phone) داشته باشد.';
		fclose($handle);
		return $result;
	}

	while (($row = fgetcsv($handle, 0, ',')) !== false) {
		$phone = trim($row[$col_map['phone']] ?? '');
		if (empty($phone)) continue;

		as_schedule_single_action(time(), 'spot_bulk_create_order_row', [[
			'product_id' => $product_id,
			'limit_raw'  => $limit_raw,
			'fname'      => trim($row[$col_map['fname'] ?? -1] ?? ''),
			'lname'      => trim($row[$col_map['lname'] ?? -1] ?? ''),
			'phone'      => $phone,
		]], 'spotplayer-bulk');

		$result['queued']++;
	}

	fclose($handle);
	return $result;
}

function spot_bulk_schedule_disable_licenses(string $csv_path): array {
	$result    = ['queued' => 0, 'errors' => []];
	$header_lc = [];

	if (($handle = fopen($csv_path, 'r')) === false) { $result['errors'][] = 'فایل CSV باز نشد.'; return $result; }

	while (($header = fgetcsv($handle, 0, ',')) !== false) {
		if (!is_array($header) || implode('', array_map('trim', $header)) === '') continue;
		foreach ($header as $idx => $col) {
			$col = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$col)));
			if ($col !== '') $header_lc[$col] = $idx;
		}
		break;
	}

	if (!isset($header_lc['id'])) {
		$result['errors'][] = 'در ردیف اول فایل باید ستونی با نام id وجود داشته باشد.';
		fclose($handle);
		return $result;
	}

	while (($row = fgetcsv($handle, 0, ',')) !== false) {
		$id = trim($row[$header_lc['id']] ?? '');
		if (!preg_match('/^[0-9a-f]{24}$/i', $id)) continue;

		as_schedule_single_action(time(), 'spot_bulk_disable_license_row', [['id' => $id]], 'spotplayer-bulk');
		$result['queued']++;
	}

	fclose($handle);
	return $result;
}

// ─── Queue status ─────────────────────────────────────────────────────────────

function spot_bulk_queue_status(): array {
	$hooks = [
		'create'  => 'spot_bulk_create_order_row',
		'disable' => 'spot_bulk_disable_license_row',
	];
	$statuses = [
		'pending'  => ActionScheduler_Store::STATUS_PENDING,
		'running'  => ActionScheduler_Store::STATUS_RUNNING,
		'failed'   => ActionScheduler_Store::STATUS_FAILED,
		'complete' => ActionScheduler_Store::STATUS_COMPLETE,
	];

	$out = [];
	foreach ($hooks as $key => $hook) {
		$out[$key] = [];
		foreach ($statuses as $label => $status) {
			$out[$key][$label] = count(as_get_scheduled_actions(
				['hook' => $hook, 'status' => $status, 'per_page' => -1], 'ids'
			));
		}
	}
	return $out;
}

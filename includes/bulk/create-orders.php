<?php
if (!defined('ABSPATH')) exit;

function spot_find_or_create_user($phone, $fname, $lname) {
	$phone_clean      = preg_replace('/[^0-9]/', '', $phone);
	$normalized_phone = substr($phone_clean, -10);

	if (strlen($normalized_phone) < 10) return false;

	$user = get_user_by('login', '0' . $normalized_phone) ?: get_user_by('login', $normalized_phone);
	if ($user) return $user->ID;

	$user_query = new WP_User_Query([
		'meta_query' => [
			'relation' => 'OR',
			['key' => 'billing_phone', 'value' => $normalized_phone, 'compare' => 'LIKE'],
			['key' => 'digits_phone_no', 'value' => $normalized_phone, 'compare' => 'LIKE'],
		],
		'number' => 1,
		'fields' => 'ID',
	]);
	$results = $user_query->get_results();
	if (!empty($results)) return $results[0];

	$username = '0' . $normalized_phone;
	$email    = "u{$normalized_phone}@" . $_SERVER['HTTP_HOST'];
	$password = wp_generate_password();
	$user_id  = wc_create_new_customer($email, $username, $password);

	if (is_wp_error($user_id)) return false;

	update_user_meta($user_id, 'billing_phone', $phone);
	update_user_meta($user_id, 'digits_phone_no', $phone);
	if (!empty($fname)) update_user_meta($user_id, 'first_name', $fname);
	if (!empty($lname)) update_user_meta($user_id, 'last_name', $lname);

	$display_name = trim($fname . ' ' . $lname);
	if (!empty($display_name)) wp_update_user(['ID' => $user_id, 'display_name' => $display_name]);

	return $user_id;
}

function spot_bulk_create_orders_3col($product_id, $limit_raw, $csv_path): array {
	if (function_exists('set_time_limit')) set_time_limit(0);

	$result  = ['success' => [], 'errors' => []];
	$product = wc_get_product($product_id);

	if (!$product) {
		$result['errors'][] = 'محصول انتخاب شده معتبر نیست.';
		return $result;
	}

	if (($handle = fopen($csv_path, 'r')) === false) {
		$result['errors'][] = 'فایل CSV باز نشد.';
		return $result;
	}

	$header  = fgetcsv($handle, 0, ',');
	$col_map = [];
	foreach ($header as $index => $col) {
		$c = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $col)));
		if (in_array($c, ['name', 'first_name', 'firstname', 'نام']))                          $col_map['fname'] = $index;
		if (in_array($c, ['family', 'last_name', 'lastname', 'surname', 'نام خانوادگی']))      $col_map['lname'] = $index;
		if (in_array($c, ['phone', 'mobile', 'number', 'موبایل', 'تلفن', 'شماره']))           $col_map['phone'] = $index;
	}

	if (!isset($col_map['phone'])) {
		$result['errors'][] = 'فایل اکسل باید حتماً ستون موبایل (phone) داشته باشد.';
		fclose($handle);
		return $result;
	}

	$row_num = 1;
	while (($data = fgetcsv($handle, 0, ',')) !== false) {
		$row_num++;
		$phone = isset($col_map['phone']) ? trim($data[$col_map['phone']] ?? '') : '';
		$fname = isset($col_map['fname']) ? trim($data[$col_map['fname']] ?? '') : '';
		$lname = isset($col_map['lname']) ? trim($data[$col_map['lname']] ?? '') : '';

		if (empty($phone)) continue;

		try {
			$user_id = spot_find_or_create_user($phone, $fname, $lname);
			if (!$user_id) throw new Exception("خطا در ساخت/یافتن کاربر برای {$phone}");

			$order = wc_create_order(['customer_id' => $user_id]);
			$order->add_product($product, 1);
			$order->set_billing_phone($phone);
			if (!empty($fname)) $order->set_billing_first_name($fname);
			if (!empty($lname)) $order->set_billing_last_name($lname);
			if (!empty($limit_raw)) $order->update_meta_data('_spot_limit', $limit_raw);
			$order->calculate_totals();
			$order->save();
			$order->update_status('completed', 'ایجاد شده توسط اکسل اسپات پلیر');

			$spot_data = $order->get_meta('_spotplayer_data');
			if (!empty($spot_data['_id'])) {
				$result['success'][] = [
					'row'        => $row_num,
					'phone'      => $phone,
					'name'       => "$fname $lname",
					'order_id'   => $order->get_id(),
					'license_id' => $spot_data['_id'],
				];
			} else {
				$result['errors'][] = "ردیف {$row_num}: سفارش #{$order->get_id()} ساخته شد اما لایسنس دریافت نشد.";
			}
		} catch (Exception $e) {
			$result['errors'][] = "ردیف {$row_num} ($phone): " . $e->getMessage();
		}
	}

	fclose($handle);
	return $result;
}

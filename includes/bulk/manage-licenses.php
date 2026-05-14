<?php
if (!defined('ABSPATH')) exit;

function spot_bulk_disable_licenses_from_csv($csv_path): array {
	$result = ['success' => [], 'errors' => []];

	if (!file_exists($csv_path) || !is_readable($csv_path)) {
		$result['errors'][] = 'فایل آپلود شده قابل خواندن نیست.';
		return $result;
	}

	if (($handle = fopen($csv_path, 'r')) === false) {
		$result['errors'][] = 'امکان باز کردن فایل برای خواندن وجود ندارد.';
		return $result;
	}

	$header    = null;
	$header_lc = [];
	$row       = 0;

	while (($header = fgetcsv($handle, 0, ',')) !== false) {
		$row++;
		if (!is_array($header) || implode('', array_map('trim', $header)) === '') continue;

		foreach ($header as $idx => $col_name) {
			$col_name = trim((string)$col_name);
			if ($idx === 0) $col_name = preg_replace('/^\xEF\xBB\xBF/', '', $col_name);
			$col_name = strtolower($col_name);
			if ($col_name !== '') $header_lc[$col_name] = $idx;
		}
		break;
	}

	if ($header === null) {
		$result['errors'][] = 'فایل CSV خالی است.';
		fclose($handle);
		return $result;
	}

	if (!isset($header_lc['id'])) {
		$result['errors'][] = 'در ردیف اول فایل باید ستونی با نام id برای شناسه لایسنس‌ها وجود داشته باشد.';
		fclose($handle);
		return $result;
	}

	while (($data = fgetcsv($handle, 0, ',')) !== false) {
		$row++;
		if (!$data || !is_array($data)) continue;

		$id = isset($data[$header_lc['id']]) ? trim($data[$header_lc['id']]) : '';
		if ($id === '') { $result['errors'][] = "ردیف {$row}: مقدار id خالی است و رد شد."; continue; }
		if (!preg_match('/^[0-9a-f]{24}$/i', $id)) { $result['errors'][] = "ردیف {$row}: مقدار id «{$id}» معتبر نیست."; continue; }

		try {
			spot_request('https://panel.spotplayer.ir/license/edit/' . $id, [
				'device' => ['p0' => 0, 'p1' => 0, 'p2' => 0, 'p3' => 0, 'p4' => 0, 'p5' => 0, 'p6' => 0],
			]);
			$result['success'][] = ['row' => $row, 'id' => $id];
		} catch (Exception $e) {
			$result['errors'][] = "ردیف {$row}: خطا در غیرفعال‌سازی لایسنس «{$id}» - " . $e->getMessage();
		}
	}

	fclose($handle);
	return $result;
}

function spot_bulk_create_restricted_licenses_from_csv($course_id, $limit_raw, $csv_path): array {
	$result    = ['success' => [], 'errors' => []];
	$course_id = trim((string)$course_id);

	if (!preg_match('/^[0-9a-f]{24}$/i', $course_id)) {
		$result['errors'][] = 'شناسه دوره وارد شده معتبر نیست (باید یک رشته هگز ۲۴ کاراکتری باشد).';
		return $result;
	}

	if (!file_exists($csv_path) || !is_readable($csv_path)) {
		$result['errors'][] = 'فایل آپلود شده قابل خواندن نیست.';
		return $result;
	}

	if (($handle = fopen($csv_path, 'r')) === false) {
		$result['errors'][] = 'امکان باز کردن فایل برای خواندن وجود ندارد.';
		return $result;
	}

	$header    = null;
	$header_lc = [];
	$row       = 0;

	while (($header = fgetcsv($handle, 0, ',')) !== false) {
		$row++;
		if (!is_array($header) || implode('', array_map('trim', $header)) === '') continue;

		foreach ($header as $idx => $col_name) {
			$col_name = trim((string)$col_name);
			if ($idx === 0) $col_name = preg_replace('/^\xEF\xBB\xBF/', '', $col_name);
			$col_name = strtolower($col_name);
			if ($col_name !== '') $header_lc[$col_name] = $idx;
		}
		break;
	}

	if ($header === null) { $result['errors'][] = 'فایل CSV خالی است.'; fclose($handle); return $result; }
	if (!isset($header_lc['name']) || !isset($header_lc['phone'])) {
		$result['errors'][] = 'در ردیف اول فایل باید ستون‌های name و phone وجود داشته باشد.';
		fclose($handle);
		return $result;
	}

	while (($data = fgetcsv($handle, 0, ',')) !== false) {
		$row++;
		if (!$data || !is_array($data)) continue;

		$name  = isset($data[$header_lc['name']])  ? trim($data[$header_lc['name']])  : '';
		$phone = isset($data[$header_lc['phone']]) ? trim($data[$header_lc['phone']]) : '';

		if ($name === '' && $phone === '') { $result['errors'][] = "ردیف {$row}: ستون‌های name و phone خالی بودند."; continue; }
		if ($name  === '') { $result['errors'][] = "ردیف {$row}: مقدار name خالی است."; continue; }
		if ($phone === '') { $result['errors'][] = "ردیف {$row}: مقدار phone خالی است."; continue; }

		$phone_sanitized = preg_replace('/[^0-9+]/', '', $phone);
		if ($phone_sanitized === '') { $result['errors'][] = "ردیف {$row}: شماره «{$phone}» معتبر نبود."; continue; }

		$payload = [
			'name'      => $name,
			'watermark' => ['texts' => [['text' => $phone_sanitized]]],
			'course'    => [$course_id],
			'data'      => ['confs' => 0],
		];

		$limit_raw = trim((string)$limit_raw);
		if ($limit_raw !== '') $payload['data']['limit'] = [$course_id => $limit_raw];

		try {
			$rep = spot_request_license_put($payload);
			if (!empty($rep['_id'])) {
				$result['success'][] = ['row' => $row, 'phone' => $phone_sanitized, 'id' => $rep['_id']];
			} else {
				$result['errors'][] = "ردیف {$row}: لایسنس برای «{$phone_sanitized}» ایجاد نشد.";
			}
		} catch (Exception $e) {
			$result['errors'][] = "ردیف {$row}: خطا برای «{$phone_sanitized}» - " . $e->getMessage();
		}
	}

	fclose($handle);
	return $result;
}

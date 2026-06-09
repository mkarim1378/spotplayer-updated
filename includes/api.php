<?php
if (!defined('ABSPATH')) exit;

/** @throws Exception */
function spot_request(string $url, $data = null) {
	$method = $data === null ? 'GET' : 'POST';
	$body   = $data === null ? [] : json_encode($data, JSON_UNESCAPED_UNICODE);

	$ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

	$rep = json_decode(
		Requests::request(
			$url,
			['Content-Type' => 'application/json', '$Level' => '-1', '$API' => get_option('spotplayer')['api'], 'X-WpSpot' => 12],
			$body,
			$method,
			['verify' => file_exists($ca_bundle) ? $ca_bundle : true]
		)->body,
		true
	);

	if (is_array($rep) && isset($rep['ex'])) {
		$ex   = $rep['ex'];
		$msg  = is_array($ex) && isset($ex['msg'])  ? $ex['msg']          : 'خطای نامشخصی از سرور اسپات پلیر دریافت شد.';
		$code = is_array($ex) && isset($ex['code']) ? intval($ex['code']) : 0;
		throw new Exception($msg, $code);
	}

	return $rep;
}

/** @throws Exception */
function spot_request_license_get($id) {
	return spot_request('https://panel.spotplayer.ir/license/edit/' . $id . '?d=1');
}

add_action('wp_ajax_spot_test_api', 'spot_ajax_test_api');
function spot_ajax_test_api(): void {
	if (!current_user_can('manage_options')) wp_send_json_error('unauthorized');
	check_ajax_referer('spot_test_api', 'nonce');

	$api = sanitize_text_field($_POST['api'] ?? '');
	if (!$api) { wp_send_json_error('کلید API وارد نشده است.'); return; }

	$ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
	try {
		$body = Requests::request(
			'https://panel.spotplayer.ir/license/edit/000000000000000000000000?d=1',
			['Content-Type' => 'application/json', '$Level' => '-1', '$API' => $api, 'X-WpSpot' => 12],
			[],
			'GET',
			['verify' => file_exists($ca_bundle) ? $ca_bundle : true]
		)->body;
		$rep = json_decode($body, true);
		if (!is_array($rep)) { wp_send_json_error('پاسخ نامفهوم از سرور اسپات پلیر.'); return; }
		// Any valid JSON from SpotPlayer means the key is authenticated
		wp_send_json_success('اتصال به اسپات پلیر برقرار است.');
	} catch (Exception $e) {
		wp_send_json_error('خطا در اتصال: ' . $e->getMessage());
	}
}

/** @throws Exception */
function spot_request_license_put($j, ?WC_Order $order = null) {
	if (!$j['name']) throw new Exception('نام لایسنس خالی بود.', 999);
	if (!$j['watermark']['texts'][0]['text']) throw new Exception('واترمارک لایسنس خالی بود.', 999);
	$raw_test = $order ? $order->get_meta('_spot_test') : '';
	$is_test  = $raw_test !== '' ? (bool) $raw_test : (bool) @get_option('spotplayer')['test'];
	return spot_request('https://panel.spotplayer.ir/license/edit/', array_merge($j, ['test' => $is_test ? 1 : 0]));
}

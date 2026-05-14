<?php
if (!defined('ABSPATH')) exit;

/** @throws Exception */
function spot_request(string $url, $data = null) {
	$method = $data === null ? 'GET' : 'POST';
	$body   = $data === null ? [] : json_encode($data, JSON_UNESCAPED_UNICODE);

	$rep = json_decode(
		Requests::request(
			$url,
			['Content-Type' => 'application/json', '$Level' => '-1', '$API' => get_option('spotplayer')['api'], 'X-WpSpot' => 12],
			$body,
			$method,
			['verify' => false, 'verifyname' => false]
		)->body,
		true
	);

	if (is_array($rep) && isset($rep['ex'])) {
		$ex  = $rep['ex'];
		$msg = is_array($ex) && isset($ex['msg']) ? $ex['msg'] : 'خطای نامشخصی از سرور اسپات پلیر دریافت شد.';
		throw new Exception($msg);
	}

	return $rep;
}

/** @throws Exception */
function spot_request_license_get($id) {
	return spot_request('https://panel.spotplayer.ir/license/edit/' . $id . '?d=1');
}

/** @throws Exception */
function spot_request_license_put($j) {
	if (!$j['name']) throw new Exception('نام لایسنس خالی بود.', 999);
	if (!$j['watermark']['texts'][0]['text']) throw new Exception('واترمارک لایسنس خالی بود.', 999);
	return spot_request('https://panel.spotplayer.ir/license/edit/', array_merge($j, ['test' => @get_option('spotplayer')['test'] ? 1 : 0]));
}

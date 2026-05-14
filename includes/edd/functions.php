<?php
if (!defined('ABSPATH')) exit;

function spot_edd_payment_items(?EDD_Payment $pay, $downloads = false): array {
	$r = [];
	if ($pay) foreach (edd_get_payment_meta_cart_details($pay->ID) as $i) {
		$c = get_post_meta($i['id'], '_spot_course', true);
		if (!$downloads) $r = array_merge($r, $c ?: []);
		else if ($i['course'] = join(',', $c)) $r[] = $i;
	}
	return $r;
}

/** @throws Exception */
function spot_edd_payment_license_request(EDD_Payment $pay, $admin = false): ?array {
	if (@($data = spot_edd_license_data($pay))['_id']) return $data;
	if (!count($courses = spot_edd_payment_items($pay))) return null;
	if (!$admin && (strtotime(edd_get_payment_completed_date($pay->ID)) < (get_option('spotplayer')['time'] ?: 0))) return null;

	try {
		$rep = spot_request_license_put(array_merge($data, ['course' => $courses, 'payload' => strval($pay->ID)]));
		if (!($id = @$rep['_id'])) throw new Exception('پاسخ نامعتبر از سرور', 999);
		$pay->update_meta('_spot_data', $data = array_merge($data, $rep));
		edd_insert_payment_note($pay->ID, sprintf('لایسنس  با شناسه %s برای این سفارش ایجاد شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>'));
		return $data;

	} catch (Exception $ex) {
		$err = sprintf('خطای %s هنگام ایجاد لایسنس روی داد.', '<b>«' . $ex->getMessage() . '»</b>');
		edd_insert_payment_note($pay->ID, $err . (($ex->getCode() == 999) ? ' <a target="_blank" href="' . parse_url(get_home_url(), PHP_URL_PATH) . '/spdeb?id=' . $pay->ID . '">اطلاعات دیباگ</a>' : ''));
		spot_admin_notice($err . ' <a href="' . get_edit_post_link($pay->ID) . '">سفارش ' . $pay->ID . '</a>');
		throw new Exception($err);
	}
}

function spot_edd_license_data(EDD_Payment $pay): array {
	if ($data = $pay->get_meta('_spot_data') ?: []) return $data;
	return spot_edd_license_data_eval($pay);
}

function spot_edd_license_data_eval(?EDD_Payment $payment) { // dont rename $payment & $user
	if (!$payment) return null;
	/** @noinspection PhpUnusedLocalVariableInspection */
	$user = get_userdata(edd_get_payment_user_id($payment->ID));
	return eval('return ' . spot_license_code() . ';');
}

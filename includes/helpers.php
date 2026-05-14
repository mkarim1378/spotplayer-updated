<?php
if (!defined('ABSPATH')) exit;

function spot_woo_or_edd(): int {
	return function_exists('wc_get_orders') ? 1 : (function_exists('edd_get_payments') ? 2 : 0);
}

function spot_license_code() {
	$dgts = function_exists('digits_version') ? "\$user->get('digits_phone')" : null;
	return get_option('spotplayer')['code'] ?: (spot_woo_or_edd() === 1
		? "[\n\t'name' => \$order->get_formatted_billing_full_name(), \n\t'watermark' => ['texts' => [['text' => " . ($dgts ?: '$order->get_billing_phone()') . "]]]\n]"
		: "[\n\t'name' => \$payment->first_name . ' ' . \$payment->last_name, \n\t'watermark' => ['texts' => [['text' => " . ($dgts ?: '$payment->email') . "]]]\n]");
}

function spot_hex2rgba($h, $o = 1): string {
	$h   = substr($h, 1);
	$h   = [$h[0] . $h[1], $h[2] . $h[3], $h[4] . $h[5]];
	$rgb = array_map('hexdec', $h);
	return 'rgba(' . implode(',', $rgb) . ',' . min($o, 1) . ')';
}

<?php
if (!defined('ABSPATH')) exit;

function spot_shop_css() {
	wp_enqueue_style('spot-shop', SPOTPLAYER_URL . 'assets/css/shop.css');
	$c = get_option('spotplayer')['color'] ?: '#6611DD';
	if (!preg_match('/^#[0-9A-F]{6}$/i', $c)) $c = '#6611DD';
	wp_add_inline_style('spot-shop', "#sp_license > BUTTON {background: $c} #sp B {color: $c} #sp_players > DIV {background: " . spot_hex2rgba($c, 0.05) . "} .sp_page_btn:hover {background: $c; border-color: $c} .spot_pending_spinner {border-top-color: $c}");
}
add_action('wp_enqueue_scripts', 'spot_shop_css');

function spot_admin_css() {
	wp_enqueue_style('spot-admin', SPOTPLAYER_URL . 'assets/css/admin.css');
}
add_action('admin_enqueue_scripts', 'spot_admin_css');

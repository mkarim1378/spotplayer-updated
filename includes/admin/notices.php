<?php
if (!defined('ABSPATH')) exit;

function spot_admin_notice($notice = '', $type = 'error', $dismissible = true) {
	$notices   = get_option('spotplayer_notices', []);
	$notices[] = ['notice' => $notice, 'type' => $type, 'dismissible' => $dismissible ? 'is-dismissible' : ''];
	update_option('spotplayer_notices', $notices);
}

function spot_admin_notices() {
	$notices = get_option('spotplayer_notices', []);
	foreach ($notices as $n)
		printf('<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>', $n['type'], $n['dismissible'], $n['notice']);
	if (!empty($notices)) delete_option('spotplayer_notices');
}
add_action('admin_notices', 'spot_admin_notices', 10);

<?php
/**
 * Plugin Name: اسپات پلیر
 * Version: 21.7
 * Description: نسخه جدیدی از افزونه اسپات پلیر با قابلیت های جدید. توسعه داده شده توسط نیازهای آکادمی کارنو
 * Author: محمد کریم قصبه
 * Author URI: https://m-karim.ir/
 * Requires PHP: 7.1
 */

if (!defined('ABSPATH')) exit;

define('SPOTPLAYER_DIR', plugin_dir_path(__FILE__));
define('SPOTPLAYER_URL', plugin_dir_url(__FILE__));

// Core utilities
require_once SPOTPLAYER_DIR . 'includes/helpers.php';
require_once SPOTPLAYER_DIR . 'includes/api.php';

// Admin shared components
require_once SPOTPLAYER_DIR . 'includes/admin/notices.php';
require_once SPOTPLAYER_DIR . 'includes/admin/order-box.php';

// WooCommerce
require_once SPOTPLAYER_DIR . 'includes/woocommerce/functions.php';
require_once SPOTPLAYER_DIR . 'includes/woocommerce/admin-product.php';
require_once SPOTPLAYER_DIR . 'includes/woocommerce/admin-order.php';
require_once SPOTPLAYER_DIR . 'includes/woocommerce/shop.php';

// Easy Digital Downloads
require_once SPOTPLAYER_DIR . 'includes/edd/functions.php';
require_once SPOTPLAYER_DIR . 'includes/edd/admin.php';
require_once SPOTPLAYER_DIR . 'includes/edd/shop.php';

// Frontend
require_once SPOTPLAYER_DIR . 'includes/frontend/css.php';
require_once SPOTPLAYER_DIR . 'includes/frontend/display.php';

// Bulk operations
require_once SPOTPLAYER_DIR . 'includes/bulk/create-orders.php';
require_once SPOTPLAYER_DIR . 'includes/bulk/manage-licenses.php';
require_once SPOTPLAYER_DIR . 'includes/bulk/scheduler.php';

// Admin settings page (loaded last — depends on all modules above)
require_once SPOTPLAYER_DIR . 'includes/admin/settings.php';

register_activation_hook(__FILE__, function () {
	add_rewrite_endpoint('licenses', EP_PAGES);
	flush_rewrite_rules();
});

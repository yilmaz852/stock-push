<?php
/*
Plugin Name: WC Stock Pusher
Description: Belirli kategorilerdeki ürünlerin stok bilgilerini diğer sitelere push eden, kategori filtresi ve sayfalama özellikli WooCommerce eklentisi. Loglama ve raporlama dahil.
Version: 1.2
Author: ChatGPT
*/

if (!defined('ABSPATH')) exit;

define('WCSP_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WCSP_PLUGIN_DIR . 'admin/settings-page.php';
require_once WCSP_PLUGIN_DIR . 'admin/report-page.php';
require_once WCSP_PLUGIN_DIR . 'includes/push-functions.php';
require_once WCSP_PLUGIN_DIR . 'includes/logger.php';

add_action('admin_menu', function () {
    add_submenu_page(
        'options-general.php',
        'WC Stock Pusher Ayarları',
        'Stock Pusher Ayarları',
        'manage_options',
        'wc-stock-pusher-settings',
        'wcsp_settings_page'
    );
});


// Cron tetikleme
add_action('wcsp_cron_hook', 'wcsp_push_all_products');

// Stok değişince tetikleme
add_action('woocommerce_update_product', function($product_id) {
    if (get_option('wcsp_trigger_type') === 'stock_change') {
        wcsp_push_product($product_id);
    }
});

// Cron planlama
register_activation_hook(__FILE__, function () {
    global $wpdb;
    // Log tablosu oluştur
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $table_name = $wpdb->prefix . 'wcsp_push_logs';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        product_id BIGINT(20) NOT NULL,
        sku VARCHAR(100) NOT NULL,
        site_url VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL,
        message TEXT NULL,
        log_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql);

    if (!wp_next_scheduled('wcsp_cron_hook')) {
        wp_schedule_event(time(), 'hourly', 'wcsp_cron_hook');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('wcsp_cron_hook');
});

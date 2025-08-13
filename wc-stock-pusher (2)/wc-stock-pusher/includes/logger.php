<?php
if (!defined('ABSPATH')) exit;

function wcsp_log_push($product_id, $sku, $site_url, $status, $message) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcsp_push_logs';
    $wpdb->insert($table_name, [
        'product_id' => $product_id,
        'sku' => $sku,
        'site_url' => $site_url,
        'status' => $status,
        'message' => $message,
        'log_time' => current_time('mysql', 1)
    ]);
}

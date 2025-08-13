<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page('options-general.php', 'WC Stock Pusher Rapor', 'Stock Pusher Rapor', 'manage_options', 'wc-stock-pusher-report', 'wcsp_report_page');
});

function wcsp_report_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcsp_push_logs';

    $selected_category = intval($_GET['category'] ?? 0);
    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);

    // Filtre için kategori seçimi
    echo '<div class="wrap"><h1>Stock Pusher Gönderim Raporu</h1><form method="get">';
    echo '<input type="hidden" name="page" value="wc-stock-pusher-report">';
    echo '<label>Kategori: <select name="category"><option value="0">Tümü</option>';
    foreach ($categories as $cat) {
        $sel = ($selected_category === $cat->term_id) ? 'selected' : '';
        echo "<option value='{$cat->term_id}' {$sel}>{$cat->name}</option>";
    }
    echo '</select></label> <input type="submit" class="button" value="Filtrele">';
    echo '</form><br>';

    // Ürünleri kategoriye göre çek
    $product_ids = [];
    if ($selected_category > 0) {
        $args = [
            'limit' => -1,
            'category' => [$selected_category],
            'return' => 'ids',
        ];
        $product_ids = wc_get_products($args);
    } else {
        $args = [
            'limit' => -1,
            'return' => 'ids',
        ];
        $product_ids = wc_get_products($args);
    }
    $product_ids = is_array($product_ids) ? $product_ids : [];

    // Logları çek (son 1000 kayıt)
    $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
    $logs = [];
    if (!empty($product_ids)) {
        global $wpdb;
        $query = $wpdb->prepare("SELECT * FROM $table_name WHERE product_id IN ($placeholders) ORDER BY log_time DESC LIMIT 1000", ...$product_ids);
        $logs = $wpdb->get_results($query, OBJECT_K);
    }

    // Logları ürün bazında grupla
    $logs_by_product = [];
    foreach ($logs as $log) {
        if (!isset($logs_by_product[$log->product_id])) $logs_by_product[$log->product_id] = [];
        $logs_by_product[$log->product_id][$log->site_url] = $log;
    }

    echo '<table class="widefat fixed" cellspacing="0">
        <thead>
            <tr><th>Ürün ID</th><th>Ürün</th><th>SKU</th><th>Site</th><th>Durum</th><th>Mesaj</th><th>Zaman</th></tr>
        </thead><tbody>';

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) continue;
        $sku = $product->get_sku();
        $name = $product->get_name();

        // Loglar
        $logs_for_product = $logs_by_product[$product_id] ?? [];

        foreach (get_option('wcsp_sites', []) as $site) {
            list($url, ) = explode('|', $site);
            $status = 'Gönderilmedi';
            $message = '';
            $time = '';
            if (isset($logs_for_product[$url])) {
                $log = $logs_for_product[$url];
                $status = $log->status === 'success' ? 'Başarılı' : 'Hata';
                $message = esc_html($log->message);
                $time = $log->log_time;
            }
            $color = ($status === 'Başarılı') ? 'style="color:green;"' : (($status === 'Hata') ? 'style="color:red;"' : '');

            echo "<tr>
                <td>{$product_id}</td>
                <td>" . esc_html($name) . "</td>
                <td>" . esc_html($sku) . "</td>
                <td>" . esc_html($url) . "</td>
                <td {$color}>{$status}</td>
                <td>{$message}</td>
                <td>{$time}</td>
            </tr>";
        }
    }

    echo '</tbody></table></div>';
}

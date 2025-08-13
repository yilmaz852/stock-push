<?php
if (!defined('ABSPATH')) exit;

function wcsp_push_product($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) return;

    $stock = $product->get_stock_quantity();
    $sku = $product->get_sku();

    $min_stock = get_option('wcsp_min_stock_threshold', 0);
    if ($stock !== null && $stock < $min_stock) return;

    $selected_categories = get_option('wcsp_selected_categories', []);
    if (!empty($selected_categories)) {
        $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (empty(array_intersect($selected_categories, $product_cats))) return;
    }

    $sites = get_option('wcsp_sites', []);
    foreach ($sites as $site_line) {
        list($url, $consumer_key, $consumer_secret) = array_map('trim', explode('|', $site_line));

        if (empty($url) || empty($consumer_key) || empty($consumer_secret)) continue;

        // Ürünü SKU ile bul
        $get_url = $url . "/wp-json/wc/v3/products?sku=" . urlencode($sku) .
            "&consumer_key=$consumer_key&consumer_secret=$consumer_secret";

        $response = wp_remote_get($get_url, [
            'timeout' => 15, // Timeout süresini düşür
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking' => true
        ]);

        if (is_wp_error($response)) {
            wcsp_log_push($product_id, $sku, $url, 'error', $response->get_error_message());
            continue;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wcsp_log_push($product_id, $sku, $url, 'error', "HTTP {$response_code} hatası");
            continue;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body) || !isset($body[0]['id'])) {
            wcsp_log_push($product_id, $sku, $url, 'error', 'Ürün bulunamadı');
            continue;
        }

        $remote_product_id = $body[0]['id'];
        $update_url = $url . "/wp-json/wc/v3/products/$remote_product_id" .
            "?consumer_key=$consumer_key&consumer_secret=$consumer_secret";

        $data = [
            'stock_quantity' => $stock,
            'manage_stock' => true
        ];

        $response = wp_remote_request($update_url, [
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'WC-Stock-Pusher/1.2'
            ],
            'body' => json_encode($data),
            'timeout' => 15, // Timeout düşürüldü
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking' => true
        ]);

        if (is_wp_error($response)) {
            wcsp_log_push($product_id, $sku, $url, 'error', $response->get_error_message());
            continue;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            wcsp_log_push($product_id, $sku, $url, 'success', 'Başarılı');
        } else {
            $body = wp_remote_retrieve_body($response);
            wcsp_log_push($product_id, $sku, $url, 'error', "HTTP $code: $body");
        }
    }
}

function wcsp_push_all_products() {
    // Execution time ve memory limit artır
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    // Son işlenen sayfa bilgisini al
    $last_processed_page = get_option('wcsp_last_processed_page', 1);
    $last_processed_count = get_option('wcsp_last_processed_count', 0);

    $paged = $last_processed_page;
    $per_page = 20;
    $processed = 0;
    $max_per_batch = get_option('wcsp_max_batch_size', 500);

    // Eğer önceki işlemden kalan ürün varsa, bu üründen başla
    if ($last_processed_count > 0 && $paged > 1) {
        // Önceki sayfadaki kalan ürün sayısını bulmak için bir mekanizma gerekir.
        // Bu örnekte basitçe bir sonraki üründen devam ettiğimizi varsayıyoruz.
        // Daha gelişmiş bir çözüm, her ürünün işlenip işlenmediğini kaydetmek olabilir.
    }

    do {
        $args = [
            'limit' => $per_page,
            'page' => $paged,
            'return' => 'ids'
        ];
        $products = wc_get_products($args);

        // Eğer mevcut sayfada hiç ürün yoksa ve daha önce işlenmiş bir sayfa varsa döngüden çık
        if (empty($products) && $paged > $last_processed_page) {
            break;
        }
        // Eğer mevcut sayfada ürün yoksa ve bu ilk sayfa ise döngüden çık
        if (empty($products) && $paged == 1) {
            break;
        }

        $current_page_processed = 0;
        foreach ($products as $product_id) {
            // Eğer bu sayfa daha önce işlenmişse ve bu ürün daha önce işlenmişse atla
            if ($paged == $last_processed_page && $current_page_processed < $last_processed_count) {
                $current_page_processed++;
                continue;
            }

            wcsp_push_product($product_id);
            $processed++;
            $current_page_processed++;

            // Her 10 üründe bir kısa bir mola
            if ($processed % 10 == 0) {
                usleep(100000); // 0.1 saniye bekle

                // Memory temizliği
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            // Maksimum batch size'a ulaşıldıysa dur ve ilerlemeyi kaydet
            if ($processed >= $max_per_batch) {
                update_option('wcsp_last_processed_page', $paged);
                update_option('wcsp_last_processed_count', $current_page_processed);
                wcsp_log_push(0, 'BATCH', 'SYSTEM', 'info', "Batch tamamlandı: {$processed} ürün işlendi. Kaldığı yer: Sayfa {$paged}, Ürün {$current_page_processed}");
                return $processed;
            }
        }

        // Sayfa bittiğinde, bir sonraki sayfanın işlemeye başlayacağını belirtmek için sayfa bilgisini kaydet
        // Ancak sadece eğer bu sayfa daha önce işlenmişse ve bu döngüde tekrar işleniyorsa
        // Eğer bu yeni bir sayfa ise, sayfa sayısını artırıp devam edeceğiz.
        if ($paged == $last_processed_page && $current_page_processed >= $last_processed_count) {
             // Bu sayfanın tamamı işlendi, bir sonraki sayfaya geçebiliriz.
             update_option('wcsp_last_processed_page', $paged + 1);
             update_option('wcsp_last_processed_count', 0); // Yeni sayfa için sıfırla
        } elseif ($paged > $last_processed_page) {
             // Bu yeni bir sayfa, ilerlemeyi kaydet
             update_option('wcsp_last_processed_page', $paged);
             update_option('wcsp_last_processed_count', $current_page_processed);
        }

        $paged++;
    } while (count($products) > 0);

    // Tüm ürünler tamamlandığında, ilerleme bilgilerini temizle
    delete_option('wcsp_last_processed_page');
    delete_option('wcsp_last_processed_count');
    wcsp_log_push(0, 'BATCH', 'SYSTEM', 'info', "Tüm ürünler tamamlandı: {$processed} ürün işlendi");
    return $processed;
}


// AJAX handler
add_action('wp_ajax_wcsp_ajax_push', 'wcsp_ajax_push_handler');

function wcsp_ajax_push_handler() {
    check_ajax_referer('wcsp_push_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Yetkisiz erişim');
    }
    
    $processed = wcsp_push_all_products();
    $total_products = wp_count_posts('product')->publish;
    $last_count = get_option('wcsp_last_processed_count', 0);
    $remaining = $total_products - $last_count;
    
    wp_send_json_success([
        'processed' => $processed,
        'remaining' => $remaining,
        'total' => $total_products
    ]);
}

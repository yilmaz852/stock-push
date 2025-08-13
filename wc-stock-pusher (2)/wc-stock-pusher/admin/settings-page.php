<?php
if (!defined('ABSPATH')) exit;

function wcsp_settings_page() {
    if (isset($_POST['wcsp_save_settings'])) {
        update_option('wcsp_sites', array_map('sanitize_text_field', explode("\n", $_POST['wcsp_sites'])));
        update_option('wcsp_trigger_type', sanitize_text_field($_POST['wcsp_trigger_type']));
        update_option('wcsp_min_stock_threshold', intval($_POST['min_stock_threshold']));
        update_option('wcsp_batch_size', intval($_POST['batch_size']));
        update_option('wcsp_selected_categories', array_map('intval', $_POST['selected_categories'] ?? []));
        echo '<div class="updated"><p>Ayarlar kaydedildi.</p></div>';
    }

    if (isset($_POST['wcsp_reset_progress'])) {
        delete_option('wcsp_last_processed_page');
        delete_option('wcsp_last_processed_count');
        echo '<div class="updated"><p>İlerleme sıfırlandı. Ürünler baştan gönderilecektir.</p></div>';
    }

    $selected_categories = get_option('wcsp_selected_categories', []);
    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    ?>
    <div class="wrap">
        <h1>WC Stock Pusher Ayarları</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">Hedef Siteler</th>
                    <td>
                        <textarea name="wcsp_sites" rows="7" cols="70"><?php echo esc_textarea(implode("\n", get_option('wcsp_sites', []))); ?></textarea>
                        <p class="description">Her satıra hedef site bilgisi ekleyin. Format: https://site.com|consumer_key|consumer_secret</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Tetikleme Türü</th>
                    <td>
                        <select name="wcsp_trigger_type">
                            <option value="cron" <?php selected(get_option('wcsp_trigger_type'), 'cron'); ?>>Zamanlanmış</option>
                            <option value="stock_change" <?php selected(get_option('wcsp_trigger_type'), 'stock_change'); ?>>Stok Değişince</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Minimum Stok Eşiği</th>
                    <td>
                        <input type="number" name="min_stock_threshold" value="<?php echo esc_attr(get_option('wcsp_min_stock_threshold', 0)); ?>" min="0">
                        <p class="description">Bu değerin altında kalan stoklar push edilmez.</p>
                    </td>
                </tr>
                <tr>
                    <th>Batch Boyutu</th>
                    <td>
                        <input type="number" name="batch_size" value="<?php echo esc_attr(get_option('wcsp_batch_size', 25)); ?>" min="5" max="100">
                        <p class="description">Tek seferde işlenecek ürün sayısı (timeout önlemek için). Önerilen: 25-50</p>
                    </td>
                </tr>
                <tr>
                    <th>Kategoriler</th>
                    <td>
                        <select name="selected_categories[]" multiple style="height:200px; width:300px;">
                            <?php foreach ($categories as $cat) : ?>
                                <option value="<?php echo $cat->term_id; ?>" <?php echo in_array($cat->term_id, $selected_categories) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Yalnızca seçilen kategorilerdeki ürünler gönderilir.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Kaydet', 'primary', 'wcsp_save_settings'); ?>
        </form>

        <?php
        $last_page = get_option('wcsp_last_processed_page', 1);
        $last_count = get_option('wcsp_last_processed_count', 0);
        $total_products = wp_count_posts('product')->publish;
        ?>

        <?php
        // Kalan ürün sayısını hesapla
        $remaining_products = $total_products - $last_count;
        $progress_percentage = $total_products > 0 ? round(($last_count / $total_products) * 100, 2) : 0;
        ?>

        <div style="background: #f1f1f1; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <h3>İşlem Durumu</h3>
            <p><strong>Son işlenen sayfa:</strong> <?php echo $last_page; ?></p>
            <p><strong>Toplam işlenen ürün:</strong> <?php echo $last_count; ?> / <?php echo $total_products; ?></p>
            <p><strong>Kalan ürün:</strong> <span style="color: red;"><?php echo $remaining_products; ?></span></p>
            <p><strong>İlerleme:</strong> %<?php echo $progress_percentage; ?></p>
            
            <?php if ($remaining_products > 0): ?>
                <div style="background: #ddd; height: 20px; border-radius: 10px; margin: 10px 0;">
                    <div style="background: #0073aa; height: 100%; width: <?php echo $progress_percentage; ?>%; border-radius: 10px;"></div>
                </div>
                <p style="color: orange;"><strong>Not:</strong> Sistem kaldığı yerden devam edecek.</p>
            <?php else: ?>
                <p style="color: green;"><strong>✓ Tüm ürünler gönderildi!</strong></p>
            <?php endif; ?>
        </div>

        <?php if ($remaining_products > 0): ?>
            <form method="post" style="margin-top: 20px;">
                <?php submit_button('Kalan ' . $remaining_products . ' Ürünü Gönder', 'secondary', 'wcsp_manual_push'); ?>
            </form>
            
            <form method="post" style="margin-top: 10px;">
                <label>
                    <input type="checkbox" name="auto_continue" value="1"> 
                    Otomatik devam et (her batch sonrası sayfayı yenile)
                </label>
                <?php submit_button('Otomatik Push Başlat', 'primary', 'wcsp_auto_push'); ?>
            </form>
        <?php endif; ?>

        <form method="post" style="margin-top: 10px;">
            <?php submit_button('İlerlemeyi Sıfırla ve Baştan Başla', 'delete', 'wcsp_reset_progress'); ?>
        </form>


<script type="text/javascript">
jQuery(document).ready(function($) {
    let isAutoPushing = false;
    
    $('#wcsp_auto_push').click(function(e) {
        if ($('input[name="auto_continue"]').is(':checked')) {
            e.preventDefault();
            startAutoPush();
        }
    });
    
    function startAutoPush() {
        if (isAutoPushing) return;
        isAutoPushing = true;
        
        $('#wcsp_auto_push').prop('disabled', true).val('Push devam ediyor...');
        
        function pushBatch() {
            $.post(ajaxurl, {
                action: 'wcsp_ajax_push',
                nonce: '<?php echo wp_create_nonce("wcsp_push_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    if (response.data.remaining > 0) {
                        // Durumu güncelle
                        location.reload(); // Sayfayı yenile ve devam et
                    } else {
                        // Tamamlandı
                        alert('Tüm ürünler başarıyla gönderildi!');
                        location.reload();
                    }
                } else {
                    alert('Hata: ' + response.data.message);
                    isAutoPushing = false;
                    $('#wcsp_auto_push').prop('disabled', false).val('Otomatik Push Başlat');
                }
            });
        }
        
        pushBatch();
    }
});
</script>

    </div>
    <?php

    if (isset($_POST['wcsp_manual_push'])) {
        wcsp_push_all_products();
        echo '<div class="updated"><p>Manuel push işlemi tamamlandı.</p></div>';
    }
}
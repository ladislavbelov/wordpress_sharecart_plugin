<?php
/*
Plugin Name: ShareCart Pro
Description: Advanced cart sharing solution for WooCommerce with referral tracking and analytics
Version: 2.0
Author: Vladislav Belov
*/

defined('ABSPATH') or die('Direct access forbidden!');

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>ShareCart requires WooCommerce to be installed and active.</p></div>';
    });
    return;
}

/**
 * Database setup and maintenance
 */
register_activation_hook(__FILE__, 'sharecart_pro_install');
function sharecart_pro_install() {
    global $wpdb;
    
    error_log('ShareCart: Starting installation...');
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Main table for shared carts
    $table_shared = $wpdb->prefix . 'sharecart_shared';
    
    // Проверяем существование таблицы
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_shared'");
    
    if ($table_exists) {
        // Проверяем структуру поля cart_key
        $column_info = $wpdb->get_row("SHOW COLUMNS FROM $table_shared LIKE 'cart_key'");
        if ($column_info && $column_info->Type === 'varchar(32)') {
            // Изменяем размер поля
            $wpdb->query("ALTER TABLE $table_shared MODIFY cart_key varchar(36) NOT NULL");
            error_log('ShareCart: Updated cart_key column size');
        }
    } else {
        // Создаем новую таблицу
        $sql_shared = "CREATE TABLE IF NOT EXISTS $table_shared (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cart_key varchar(36) NOT NULL,
            cart_data longtext NOT NULL,
            referrer_name varchar(100) NOT NULL,
            referrer_id bigint(20) DEFAULT NULL,
            note text DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY (cart_key)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_shared);
    }
    
    // Stats table for tracking
    $table_stats = $wpdb->prefix . 'sharecart_stats';
    $sql_stats = "CREATE TABLE IF NOT EXISTS $table_stats (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        shared_id bigint(20) NOT NULL,
        visitor_ip varchar(45) DEFAULT NULL,
        visited_at datetime NOT NULL,
        order_id bigint(20) DEFAULT NULL,
        converted_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY (shared_id)
    ) $charset_collate;";
    
    error_log('ShareCart: Creating stats table...');
    $result_stats = dbDelta($sql_stats);
    error_log('ShareCart: Stats table creation result: ' . print_r($result_stats, true));
    
    // Проверяем, создалась ли таблица статистики
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_stats'");
    if (!$table_exists) {
        error_log('ShareCart: Failed to create stats table');
        return false;
    }
    
    // Setup custom endpoint
    error_log('ShareCart: Adding rewrite rules...');
    add_rewrite_endpoint('shared-cart', EP_ROOT);
    flush_rewrite_rules();
    
    // Schedule daily cleanup
    if (!wp_next_scheduled('sharecart_daily_cleanup')) {
        error_log('ShareCart: Scheduling daily cleanup...');
        wp_schedule_event(time(), 'daily', 'sharecart_daily_cleanup');
    }
    
    error_log('ShareCart: Installation completed successfully');
    return true;
}

// Cleanup expired carts
add_action('sharecart_daily_cleanup', 'sharecart_cleanup_old_carts');
function sharecart_cleanup_old_carts() {
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}sharecart_shared WHERE expires_at < %s",
            current_time('mysql')
        )
    );
}

// Plugin deactivation
register_deactivation_hook(__FILE__, 'sharecart_pro_deactivate');
function sharecart_pro_deactivate() {
    wp_clear_scheduled_hook('sharecart_daily_cleanup');
    flush_rewrite_rules();
}

/**
 * Cart sharing functionality - Кнопка и форма на странице корзины
 */
add_action('woocommerce_after_cart_table', 'sharecart_add_share_section');
function sharecart_add_share_section() {
    ?>
    <div class="sharecart-section">
        <h2><?php _e('Share Your Cart', 'sharecart'); ?></h2>
        <div class="sharecart-form">
            <div class="form-group">
                <label for="sharecart-name"><?php _e('Your Name:', 'sharecart'); ?></label>
                <input type="text" id="sharecart-name" class="form-control" placeholder="<?php _e('Enter your name', 'sharecart'); ?>" required>
            </div>
            <div class="form-group">
                <label for="sharecart-note"><?php _e('Optional Message:', 'sharecart'); ?></label>
                <textarea id="sharecart-note" class="form-control" placeholder="<?php _e('Add a personal message (optional)', 'sharecart'); ?>"></textarea>
            </div>
            <button id="sharecart-generate-btn" class="button alt">
                <?php _e('Generate Share Link', 'sharecart'); ?>
            </button>
            <div id="sharecart-result" style="display:none; margin-top:15px;">
                <p><?php _e('Your cart has been shared! Copy this link:', 'sharecart'); ?></p>
                <div class="input-group">
                    <input type="text" id="sharecart-link" class="form-control" readonly>
                    <button id="sharecart-copy-btn" class="button">
                        <?php _e('Copy', 'sharecart'); ?>
                    </button>
                </div>
                <p class="sharecart-success-msg" style="display:none;">
                    <?php _e('Link copied to clipboard!', 'sharecart'); ?>
                </p>
            </div>
        </div>
    </div>
    <?php
}

// AJAX handler for link generation
add_action('wp_ajax_sharecart_generate_link', 'sharecart_generate_link');
add_action('wp_ajax_nopriv_sharecart_generate_link', 'sharecart_generate_link');
function sharecart_generate_link() {
    global $wpdb;
    
    error_log('ShareCart: Starting link generation...');
    
    check_ajax_referer('sharecart_nonce', 'security');
    
    $cart = WC()->cart->get_cart();
    if (empty($cart)) {
        error_log('ShareCart: Empty cart - cannot generate link');
        wp_send_json_error(__('Your cart is empty!', 'sharecart'));
    }
    
    $referrer_name = sanitize_text_field($_POST['name'] ?? '');
    $note = sanitize_textarea_field($_POST['note'] ?? '');
    
    error_log('ShareCart: Received data - name: ' . $referrer_name . ', note: ' . $note);
    
    if (empty($referrer_name)) {
        error_log('ShareCart: No referrer name provided');
        wp_send_json_error(__('Please enter your name', 'sharecart'));
    }
    
    // Prepare cart data
    $cart_data = array_map(function($item) {
        return [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'variation_id' => $item['variation_id'] ?? 0,
            'variation' => $item['variation'] ?? []
        ];
    }, $cart);
    
    error_log('ShareCart: Prepared cart data: ' . print_r($cart_data, true));
    
    // Generate unique key
    $cart_key = wp_generate_uuid4();
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    error_log('ShareCart: Generated cart key: ' . $cart_key);
    
    // Проверяем существование таблицы
    $table_name = $wpdb->prefix . 'sharecart_shared';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        error_log('ShareCart: Table does not exist: ' . $table_name);
        wp_send_json_error(__('Database error. Please contact administrator.', 'sharecart'));
    }
    
    $insert_data = [
        'cart_key' => $cart_key,
        'cart_data' => json_encode($cart_data),
        'referrer_name' => $referrer_name,
        'referrer_id' => get_current_user_id(),
        'note' => $note,
        'created_at' => current_time('mysql'),
        'expires_at' => $expires
    ];
    
    error_log('ShareCart: Attempting to insert data: ' . print_r($insert_data, true));
    
    $inserted = $wpdb->insert(
        $table_name,
        $insert_data,
        ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
    );
    
    if ($inserted === false) {
        error_log('ShareCart: Failed to insert shared cart - ' . $wpdb->last_error);
        error_log('ShareCart: Last query: ' . $wpdb->last_query);
        wp_send_json_error(__('Failed to generate link. Please try again.', 'sharecart'));
    }
    
    error_log('ShareCart: Successfully inserted cart with ID: ' . $wpdb->insert_id);
    
    $url = home_url("/shared-cart/{$cart_key}/");
    error_log('ShareCart: Generated URL: ' . $url);
    
    wp_send_json_success([
        'url' => $url,
        'message' => __('Link generated successfully!', 'sharecart')
    ]);
}

/**
 * Shared cart page handling
 */
add_action('init', 'sharecart_add_rewrite_rules');
function sharecart_add_rewrite_rules() {
    add_rewrite_rule(
        '^shared-cart/([a-f0-9\-]{36})/?$',
        'index.php?shared_cart=$matches[1]',
        'top'
    );
    add_rewrite_tag('%shared_cart%', '([a-f0-9\-]{36})');
}


add_action('template_redirect', 'sharecart_handle_shared_page');
function sharecart_handle_shared_page() {
    $cart_key = get_query_var('shared_cart');
    
    // Добавляем отладочную информацию
    error_log('ShareCart: Handling shared page with key: ' . $cart_key);
    
    if (empty($cart_key)) {
        error_log('ShareCart: No cart key provided');
        return;
    }

    $shared_cart = sharecart_get_shared_cart($cart_key);
    
    if (!$shared_cart) {
        error_log('ShareCart: Redirecting to cart page - no shared cart found');
        wp_redirect(wc_get_cart_url());
        exit;
    }
    
    // Record visit
    sharecart_record_visit($shared_cart->id);
    
    // Display shared cart page
    error_log('ShareCart: Including shared cart page template');
    include plugin_dir_path(__FILE__) . 'templates/shared-cart-page.php';
    exit;
}

// AJAX handler for adding items
add_action('wp_ajax_sharecart_add_items', 'sharecart_add_items_handler');
add_action('wp_ajax_nopriv_sharecart_add_items', 'sharecart_add_items_handler');
function sharecart_add_items_handler() {
    check_ajax_referer('sharecart_nonce', 'security');
    
    $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
    $shared_cart = sharecart_get_shared_cart($cart_key);
    
    if (!$shared_cart) {
        wp_send_json_error(__('Invalid cart', 'sharecart'));
    }
    
    $items = json_decode($shared_cart->cart_data, true);
    $added = 0;
    
    foreach ($items as $item) {
        $added += (int) WC()->cart->add_to_cart(
            $item['product_id'],
            $item['quantity'],
            $item['variation_id'],
            $item['variation']
        );
    }
    
    // Set referral data in session
    WC()->session->set('sharecart_referral', [
        'shared_id' => $shared_cart->id,
        'referrer_name' => $shared_cart->referrer_name,
        'note' => $shared_cart->note
    ]);
    
    wp_send_json_success([
        'added' => $added,
        'cart_url' => wc_get_cart_url()
    ]);
}

/**
 * Order tracking and conversion
 */
add_action('woocommerce_checkout_order_processed', 'sharecart_track_conversion', 10, 3);
function sharecart_track_conversion($order_id, $posted_data, $order) {
    $referral_data = WC()->session->get('sharecart_referral');
    
    if (!$referral_data) {
        return;
    }
    
    global $wpdb;
    
    // Update stats table with order information
    $wpdb->update(
        "{$wpdb->prefix}sharecart_stats",
        array(
            'order_id' => $order_id,
            'converted_at' => current_time('mysql')
        ),
        array(
            'shared_id' => $referral_data['shared_id'],
            'order_id' => null
        ),
        array('%d', '%s'),
        array('%d', '%d')
    );
    
    // Add order note with referral information
    $order->add_order_note(
        sprintf(
            __('Order placed via shared cart by %s', 'sharecart'),
            $referral_data['referrer_name']
        )
    );
    
    // Clear referral data from session
    WC()->session->__unset('sharecart_referral');
}

/**
 * Helper functions
 */
function sharecart_get_shared_cart($cart_key) {
    global $wpdb;
    
    // Добавляем отладочную информацию
    error_log('ShareCart: Searching for cart with key: ' . $cart_key);
    
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sharecart_shared 
         WHERE cart_key = %s AND expires_at > %s",
        $cart_key, current_time('mysql')
    ));
    
    if (!$result) {
        error_log('ShareCart: No cart found with key: ' . $cart_key);
    } else {
        error_log('ShareCart: Found cart with ID: ' . $result->id);
    }
    
    return $result;
}

function sharecart_record_visit($shared_id) {
    global $wpdb;
    $wpdb->insert(
        "{$wpdb->prefix}sharecart_stats",
        [
            'shared_id' => $shared_id,
            'visitor_ip' => $_SERVER['REMOTE_ADDR'],
            'visited_at' => current_time('mysql')
        ],
        ['%d', '%s', '%s']
    );
}

/**
 * Admin interface
 */
add_action('admin_menu', 'sharecart_add_admin_menu');
function sharecart_add_admin_menu() {
    add_menu_page(
        __('ShareCart', 'sharecart'),
        __('ShareCart', 'sharecart'),
        'manage_options',
        'sharecart',
        'sharecart_admin_page',
        'dashicons-cart'
    );
    
    add_submenu_page(
        'sharecart',
        __('Analytics', 'sharecart'),
        __('Analytics', 'sharecart'),
        'manage_options',
        'sharecart-analytics',
        'sharecart_analytics_page'
    );
}

function sharecart_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Handle settings save
    if (isset($_POST['sharecart_save_settings'])) {
        check_admin_referer('sharecart_save_settings');
        update_option('sharecart_expiry_days', absint($_POST['expiry_days']));
        echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'sharecart') . '</p></div>';
    }
    
    $expiry_days = get_option('sharecart_expiry_days', 7);
    ?>
    <div class="wrap">
        <h1><?php _e('ShareCart Settings', 'sharecart'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('sharecart_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="expiry_days"><?php _e('Link Expiration (days)', 'sharecart'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="expiry_days" name="expiry_days" 
                               value="<?php echo esc_attr($expiry_days); ?>" min="1">
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'sharecart')); ?>
        </form>
    </div>
    <?php
}

function sharecart_analytics_page() {
    global $wpdb;
    
    // Get statistics
    $stats = $wpdb->get_results("
        SELECT 
            sc.referrer_name,
            COUNT(DISTINCT ss.id) as total_visits,
            COUNT(DISTINCT CASE WHEN ss.order_id IS NOT NULL THEN ss.id END) as total_conversions,
            COUNT(DISTINCT ss.order_id) as total_orders
        FROM {$wpdb->prefix}sharecart_shared sc
        LEFT JOIN {$wpdb->prefix}sharecart_stats ss ON sc.id = ss.shared_id
        GROUP BY sc.id
        ORDER BY sc.created_at DESC
    ");
    
    ?>
    <div class="wrap">
        <h1><?php _e('ShareCart Analytics', 'sharecart'); ?></h1>
        
        <div class="sharecart-stats-grid">
            <?php foreach ($stats as $stat) : ?>
                <div class="sharecart-stat-card">
                    <h3><?php echo esc_html($stat->referrer_name); ?></h3>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Total Visits:', 'sharecart'); ?></span>
                        <span class="stat-value"><?php echo $stat->total_visits; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Total Conversions:', 'sharecart'); ?></span>
                        <span class="stat-value"><?php echo $stat->total_conversions; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Total Orders:', 'sharecart'); ?></span>
                        <span class="stat-value"><?php echo $stat->total_orders; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Conversion Rate:', 'sharecart'); ?></span>
                        <span class="stat-value">
                            <?php 
                            echo $stat->total_visits > 0 
                                ? round(($stat->total_conversions / $stat->total_visits) * 100, 2) . '%'
                                : '0%';
                            ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/**
 * Assets
 */
add_action('wp_enqueue_scripts', 'sharecart_enqueue_assets');
function sharecart_enqueue_assets() {
    wp_enqueue_style('sharecart-styles', plugins_url('assets/css/sharecart.css', __FILE__));
    wp_enqueue_script('sharecart-script', plugins_url('assets/js/sharecart.js', __FILE__), array('jquery'), '1.0', true);
    
    wp_localize_script('sharecart-script', 'sharecart_params', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('sharecart_nonce'),
        'i18n' => array(
            'name_required' => __('Please enter your name', 'sharecart'),
            'error' => __('An error occurred. Please try again.', 'sharecart')
        )
    ));
}

add_action('admin_enqueue_scripts', 'sharecart_admin_assets');
function sharecart_admin_assets($hook) {
    if ('toplevel_page_sharecart-analytics' !== $hook) {
        return;
    }
    
    wp_enqueue_style('sharecart-admin', plugins_url('assets/css/admin.css', __FILE__));
}

// Add single item to cart
add_action('wp_ajax_sharecart_add_single_item', 'sharecart_add_single_item');
add_action('wp_ajax_nopriv_sharecart_add_single_item', 'sharecart_add_single_item');
function sharecart_add_single_item() {
    check_ajax_referer('sharecart_nonce', 'security');
    
    $product = $_POST['product'] ?? null;
    if (!$product) {
        wp_send_json_error(__('Invalid product data', 'sharecart'));
    }
    
    $added = WC()->cart->add_to_cart(
        $product['product_id'],
        $product['quantity'],
        $product['variation_id'],
        $product['variation']
    );
    
    if ($added) {
        // Set referral data in session
        WC()->session->set('sharecart_referral', [
            'shared_id' => $product['shared_id'] ?? null,
            'referrer_name' => $product['referrer_name'] ?? null,
            'note' => $product['note'] ?? null
        ]);
        
        wp_send_json_success([
            'cart_url' => wc_get_cart_url()
        ]);
    } else {
        wp_send_json_error(__('Failed to add item to cart', 'sharecart'));
    }
}


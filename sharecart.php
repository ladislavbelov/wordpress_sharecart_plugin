<?php
/*
Plugin Name: ShareCart
Description: Plugin for creating unique cart sharing links in WooCommerce.
Version: 0.1
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

// Create database table on plugin activation
register_activation_hook(__FILE__, 'sharecart_create_table');

/**
 * Creates the database table for shared carts
 */
function sharecart_create_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'sharecart_links';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        ref_id varchar(64) NOT NULL,
        cart_data longtext NOT NULL,
        referrer_name varchar(100) NOT NULL,
        note text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY (ref_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Schedule cleanup of old carts
    if (!wp_next_scheduled('sharecart_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'sharecart_daily_cleanup');
    }

    flush_rewrite_rules();
}

// Cleanup old shared carts
add_action('sharecart_daily_cleanup', 'sharecart_cleanup_old_carts');

/**
 * Cleans up expired shared carts
 */
function sharecart_cleanup_old_carts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sharecart_links';
    $wpdb->query(
        $wpdb->prepare("DELETE FROM $table_name WHERE expires_at < %s", current_time('mysql'))
    );
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'sharecart_deactivate');

/**
 * Plugin deactivation cleanup
 */
function sharecart_deactivate() {
    wp_clear_scheduled_hook('sharecart_daily_cleanup');
    flush_rewrite_rules();
}

// Add share button to cart page
add_action('woocommerce_cart_actions', 'sharecart_add_share_button');

/**
 * Adds share button and popup to cart page
 */
function sharecart_add_share_button() {
    echo '<div class="sharecart-container">';
    echo '<button type="button" id="sharecart-button" class="button">' . __('Share Cart', 'sharecart') . '</button>';
    echo '<div id="sharecart-popup" style="display:none;">';
    echo '<p>' . __('Enter your name and optional note:', 'sharecart') . '</p>';
    echo '<input type="text" id="sharecart-name" placeholder="' . __('Your Name', 'sharecart') . '" required>';
    echo '<textarea id="sharecart-note" placeholder="' . __('Optional Note', 'sharecart') . '"></textarea>';
    echo '<button type="button" id="sharecart-generate" class="button">' . __('Generate Link', 'sharecart') . '</button>';
    echo '<div id="sharecart-result" style="margin-top:10px; display:none;">';
    echo '<p>' . __('Your cart has been shared! Copy this link:', 'sharecart') . '</p>';
    echo '<input type="text" id="sharecart-link" readonly style="width:100%;">';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// AJAX handler for generating share link
add_action('wp_ajax_sharecart_generate_link', 'sharecart_generate_link');
add_action('wp_ajax_nopriv_sharecart_generate_link', 'sharecart_generate_link');

/**
 * Generates shareable cart link (AJAX handler)
 */
function sharecart_generate_link() {
    global $wpdb;

    check_ajax_referer('sharecart_nonce', 'security');

    $table_name = $wpdb->prefix . 'sharecart_links';
    $cart = WC()->cart->get_cart();

    if (empty($cart)) {
        wp_send_json_error(__('Your cart is empty!', 'sharecart'));
    }

    // Get data from AJAX request
    $referrer_name = isset($_POST['referrer_name']) ? sanitize_text_field($_POST['referrer_name']) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field($_POST['note']) : '';

    if (empty($referrer_name)) {
        wp_send_json_error(__('Please enter your name', 'sharecart'));
    }

    // Prepare cart data
    $cart_data = array();
    foreach ($cart as $item_key => $item) {
        $cart_data[] = array(
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'variation_id' => $item['variation_id'] ?? 0,
            'variation' => $item['variation'] ?? array()
        );
    }

    // Generate unique reference ID
    $ref_id = wp_generate_uuid4();

    // Save to database
    $wpdb->insert(
        $table_name,
        array(
            'ref_id' => $ref_id,
            'cart_data' => json_encode($cart_data),
            'referrer_name' => $referrer_name,
            'note' => $note,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))
        ),
        array('%s', '%s', '%s', '%s', '%s')
    );

    // Generate share URL
    $share_url = add_query_arg('ref', $ref_id, wc_get_cart_url());

    wp_send_json_success(array(
        'url' => $share_url,
        'message' => __('Link generated successfully!', 'sharecart')
    ));
}

// Setup rewrite rules for shared cart links
add_action('init', 'sharecart_rewrite_rules');

/**
 * Sets up rewrite rules for shared cart links
 */
function sharecart_rewrite_rules() {
    add_rewrite_rule('^sharecart/([^/]+)/?', 'index.php?sharecart_key=$matches[1]', 'top');
    add_rewrite_tag('%sharecart_key%', '([^&]+)');
}

// Load shared cart when visiting shared link
add_action('wp_loaded', 'sharecart_load_shared_cart');

/**
 * Loads shared cart when visiting shared link
 */
function sharecart_load_shared_cart() {
    if (!isset($_GET['ref'])) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'sharecart_links';
    $ref_id = sanitize_text_field($_GET['ref']);

    // Get shared cart data
    $shared_cart = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE ref_id = %s AND expires_at > %s",
            $ref_id,
            current_time('mysql')
        )
    );

    if (!$shared_cart) {
        wc_add_notice(__('This cart link is invalid or has expired.', 'sharecart'), 'error');
        return;
    }

    // Store referral data in WooCommerce session
    WC()->session->set('sharecart_referral', array(
        'referrer_name' => $shared_cart->referrer_name,
        'note' => $shared_cart->note,
        'ref_id' => $ref_id
    ));

    // Decode cart items
    $cart_items = json_decode($shared_cart->cart_data, true);

    // Clear current cart
    WC()->cart->empty_cart();

    // Add shared items to cart
    foreach ($cart_items as $item) {
        WC()->cart->add_to_cart(
            $item['product_id'],
            $item['quantity'],
            $item['variation_id'],
            $item['variation']
        );
    }

    // Show success message
    wc_add_notice(sprintf(
        __('You are viewing a cart shared by %s.', 'sharecart'),
        $shared_cart->referrer_name
    ), 'success');
}

// Add referral fields to checkout
add_action('woocommerce_after_order_notes', 'sharecart_add_referral_fields_to_checkout');

/**
 * Adds hidden referral fields to checkout page
 */
function sharecart_add_referral_fields_to_checkout() {
    $referral_data = WC()->session->get('sharecart_referral');

    if (!$referral_data) {
        return;
    }

    echo '<div id="sharecart-referral-fields">';
    echo '<input type="hidden" name="sharecart_referrer_name" value="' . esc_attr($referral_data['referrer_name']) . '">';
    echo '<input type="hidden" name="sharecart_note" value="' . esc_attr($referral_data['note']) . '">';
    echo '<input type="hidden" name="sharecart_ref_id" value="' . esc_attr($referral_data['ref_id']) . '">';
    echo '</div>';
}

// Save referral data to order meta
add_action('woocommerce_checkout_create_order', 'sharecart_save_referral_to_order');

/**
 * Saves referral data to order meta
 */
function sharecart_save_referral_to_order($order) {
    if (isset($_POST['sharecart_referrer_name'])) {
        $order->update_meta_data('_sharecart_referrer_name', sanitize_text_field($_POST['sharecart_referrer_name']));
    }
    if (isset($_POST['sharecart_note'])) {
        $order->update_meta_data('_sharecart_note', sanitize_textarea_field($_POST['sharecart_note']));
    }
    if (isset($_POST['sharecart_ref_id'])) {
        $order->update_meta_data('_sharecart_ref_id', sanitize_text_field($_POST['sharecart_ref_id']));
    }

    // Clear session data
    WC()->session->set('sharecart_referral', null);
}

// Display referral info in admin order page
add_action('woocommerce_admin_order_data_after_billing_address', 'sharecart_display_referral_in_admin');

/**
 * Displays referral info in admin order page
 */
function sharecart_display_referral_in_admin($order) {
    $referrer_name = $order->get_meta('_sharecart_referrer_name');
    $note = $order->get_meta('_sharecart_note');

    if ($referrer_name) {
        echo '<p><strong>' . __('Shared Cart Referrer:', 'sharecart') . '</strong> ' . esc_html($referrer_name) . '</p>';
    }
    if ($note) {
        echo '<p><strong>' . __('Referrer Note:', 'sharecart') . '</strong> ' . esc_html($note) . '</p>';
    }
}

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', 'sharecart_enqueue_assets');

/**
 * Enqueues plugin assets
 */
function sharecart_enqueue_assets() {
    // CSS
    wp_enqueue_style(
        'sharecart-styles',
        plugins_url('assets/sharecart.css', __FILE__),
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'assets/sharecart.css')
    );

    // JS
    wp_enqueue_script(
        'sharecart-script',
        plugins_url('assets/sharecart.js', __FILE__),
        array('jquery'),
        filemtime(plugin_dir_path(__FILE__) . 'assets/sharecart.js'),
        true
    );

    wp_localize_script('sharecart-script', 'sharecart_vars', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('sharecart_nonce'),
        'i18n' => array(
            'empty_name' => __('Please enter your name', 'sharecart'),
            'generating' => __('Generating link...', 'sharecart')
        )
    ));
}

// Add admin menu
add_action('admin_menu', 'sharecart_add_admin_menu');

/**
 * Adds admin menu for plugin settings
 */
function sharecart_add_admin_menu() {
    add_options_page(
        __('ShareCart Settings', 'sharecart'),
        __('ShareCart', 'sharecart'),
        'manage_options',
        'sharecart-settings',
        'sharecart_settings_page'
    );
}

/**
 * Displays plugin settings page
 */
function sharecart_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Save settings if form submitted
    if (isset($_POST['sharecart_save_settings'])) {
        check_admin_referer('sharecart_save_settings');

        // Save settings here if needed
        update_option('sharecart_expiry_days', absint($_POST['expiry_days']));

        echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'sharecart') . '</p></div>';
    }

    $expiry_days = get_option('sharecart_expiry_days', 7);
    ?>
    <div class="wrap">
        <h1><?php _e('ShareCart Settings', 'sharecart'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('sharecart_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="expiry_days"><?php _e('Link Expiration (days)', 'sharecart'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="expiry_days" name="expiry_days" value="<?php echo esc_attr($expiry_days); ?>" min="1">
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'sharecart')); ?>
        </form>
    </div>
    <?php
}
<?php
/**
 * Шаблон публичной страницы /share-cart/{key}/
 * Здесь показываем список товаров и кнопку "Добавить".
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$key = get_query_var( 'sharecart_key' );

$table = $wpdb->prefix . 'sharecart_shared';
$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_key = %s", $key ) );

$now      = current_time( 'mysql' );
$is_valid = $row && $row->expires_at >= $now;

// Сохраняем имя врача в Session/куку ДО любого вывода: setcookie() тихо не
// сработает, если вызвать его после того, как get_header() уже начал печатать HTML.
if ( $is_valid ) {
    try {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'sharecart_doctor_name', $row->doctor_name );
        }
        // cookie на 1 год (резервный канал на случай проблем с сессией)
        setcookie( 'sharecart_doctor_name', $row->doctor_name, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
    } catch ( Throwable $e ) {
        error_log( '[ShareCart] set doctor session/cookie error: ' . $e->getMessage() );
    }
}

// Страница персональная — у разных ссылок разное содержимое, поэтому её нельзя
// отдавать из общего кэша (page cache хостинга/плагинов кэширования и т.п.).
if ( function_exists( 'nocache_headers' ) ) {
    nocache_headers();
}
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
    define( 'DONOTCACHEPAGE', true );
}

// Простейший хедер WP темы
get_header();

// noindex, чтобы не светить
echo '<meta name="robots" content="noindex,nofollow">';

echo '<div class="sharecart-page" style="max-width:960px;margin:24px auto;padding:0 12px;">';
echo '<h1 style="margin-bottom:12px;">Поделиться корзиной</h1>';

if ( ! $row ) {
    echo '<div class="woocommerce-error">Ссылка не найдена.</div>';
    echo '</div>';
    get_footer();
    return;
}

if ( ! $is_valid ) {
    echo '<div class="woocommerce-error">Срок действия ссылки истёк.</div>';
    echo '</div>';
    get_footer();
    return;
}

// Распарсим снапшот
$items = json_decode( $row->snapshot, true );
if ( ! is_array( $items ) ) {
    echo '<div class="woocommerce-error">Ошибка данных корзины.</div>';
    echo '</div>';
    get_footer();
    return;
}

// Вывод врача
// echo '<p><strong>Врач:</strong> ' . esc_html( $row->doctor_name ) . '</p>';

// Список товаров
if ( empty( $items ) ) {
    echo '<div class="woocommerce-info">Список товаров пуст.</div>';
} else {
    echo '<table class="shop_table shop_table_responsive" style="width:100%;margin-top:12px;">';
    echo '<thead><tr><th style="text-align:left;">Товар</th><th>Кол-во</th></tr></thead><tbody>';

    foreach ( $items as $entry ) {
        $product_id   = isset( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;
        $variation_id = isset( $entry['variation_id'] ) ? absint( $entry['variation_id'] ) : 0;
        $qty          = isset( $entry['quantity'] ) ? absint( $entry['quantity'] ) : 1;

        $product      = wc_get_product( $variation_id ?: $product_id );
        $title        = $product ? $product->get_name() : 'Товар #' . $product_id;
        echo '<tr>';
        echo '<td>' . esc_html( $title ) . '</td>';
        echo '<td style="text-align:center;">' . esc_html( (string) $qty ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Кнопка "Добавить товары" (AJAX)
    echo '<div style="margin-top:14px;">';
    echo '  <button id="sharecart-add" class="button button-primary" data-cart-key="' . esc_attr( $row->cart_key ) . '">Добавить товары в корзину</button>';
    echo '</div>';
}

echo '</div>';

get_footer();

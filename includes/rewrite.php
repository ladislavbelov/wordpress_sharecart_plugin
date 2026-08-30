<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Регистрируем rewrite-тег и правило:
 *   /share-cart/{cart_key}/  →  index.php?sharecart_key=$1
 */
function sharecart_min_register_rewrite() {
    add_rewrite_tag( '%sharecart_key%', '([^&]+)' );
    add_rewrite_rule( '^share-cart/([^/]+)/?$', 'index.php?sharecart_key=$matches[1]', 'top' );
}

/**
 * Поддержка query var
 */
add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'sharecart_key';
    return $vars;
} );

/**
 * Подмена шаблона: если есть sharecart_key, рендерим наш файл
 */
add_filter( 'template_include', function( $template ) {
    $key = get_query_var( 'sharecart_key' );
    if ( ! empty( $key ) ) {
        // Подключим наш шаблон
        $custom = SHARECART_MIN_DIR . 'includes/template-share-page.php';
        if ( file_exists( $custom ) ) {
            // Скрипт нужен на странице шаринга
            wp_enqueue_script( 'sharecart-min' );
            return $custom;
        }
    }
    return $template;
} );
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Вспомогательное: имя таблицы
 */
function sharecart_min_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'sharecart_shared';
}

/**
 * Сериализация текущей корзины в снапшот
 */
function sharecart_min_build_snapshot(): array {
    // Убедимся, что корзина доступна
    if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
        wc_load_cart();
    }

    $items = array();
    if ( WC()->cart && ! empty( WC()->cart->get_cart() ) ) {
        foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
            $product_id   = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
            $qty          = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
            $variation    = isset( $item['variation'] ) && is_array( $item['variation'] ) ? array_map( 'sanitize_text_field', $item['variation'] ) : array();

            $items[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => $qty,
                'attributes'   => $variation, // формат: attribute_pa_color => 'blue' и т.п.
            );
        }
    }
    return $items;
}

/**
 * Нормализация кодов купонов перед сохранением/применением.
 */
function sharecart_min_normalize_coupon_codes( $coupon_codes ): array {
    if ( ! is_array( $coupon_codes ) ) {
        return array();
    }

    $normalized = array();

    foreach ( $coupon_codes as $code ) {
        if ( ! is_scalar( $code ) ) {
            continue;
        }

        $code = sanitize_text_field( (string) $code );
        $code = function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $code ) : strtolower( trim( $code ) );

        if ( $code !== '' ) {
            $normalized[] = $code;
        }
    }

    return array_values( array_unique( $normalized ) );
}

/**
 * Список купонов, уже применённых в текущей корзине автора ссылки.
 */
function sharecart_min_get_applied_coupon_codes(): array {
    if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
        wc_load_cart();
    }

    if ( ! WC()->cart ) {
        return array();
    }

    return sharecart_min_normalize_coupon_codes( WC()->cart->get_applied_coupons() );
}

/**
 * Безопасный разбор сохранённых купонов из БД.
 */
function sharecart_min_parse_coupon_snapshot( $value ): array {
    if ( empty( $value ) ) {
        return array();
    }

    if ( is_string( $value ) ) {
        $decoded = json_decode( $value, true );
        return sharecart_min_normalize_coupon_codes( is_array( $decoded ) ? $decoded : array() );
    }

    return sharecart_min_normalize_coupon_codes( is_array( $value ) ? $value : array() );
}

/**
 * Применение сохранённых купонов к корзине получателя ссылки.
 */
function sharecart_min_apply_saved_coupons( array $coupon_codes ): array {
    $result = array(
        'applied' => array(),
        'skipped' => array(),
    );

    $coupon_codes = sharecart_min_normalize_coupon_codes( $coupon_codes );
    if ( empty( $coupon_codes ) ) {
        return $result;
    }

    if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
        wc_load_cart();
    }

    if ( ! WC()->cart ) {
        foreach ( $coupon_codes as $code ) {
            $result['skipped'][] = array( 'code' => $code, 'reason' => 'Корзина WooCommerce недоступна' );
        }
        return $result;
    }

    foreach ( $coupon_codes as $code ) {
        if ( WC()->cart->has_discount( $code ) ) {
            $result['applied'][] = array( 'code' => $code, 'reason' => 'Уже применён' );
            continue;
        }

        $applied = WC()->cart->apply_coupon( $code );

        if ( $applied ) {
            $result['applied'][] = array( 'code' => $code );
        } else {
            $result['skipped'][] = array( 'code' => $code, 'reason' => 'WooCommerce отклонил купон' );
        }
    }

    WC()->cart->calculate_totals();

    return $result;
}

/**
 * AJAX: генерация ссылки (для гостей и авторизованных)
 */
function sharecart_min_ajax_generate_link() {
    // Защита nonce
    check_ajax_referer( 'sharecart_nonce', 'nonce' );

    try {
        // Имя врача
        $doctor = isset( $_POST['doctor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['doctor_name'] ) ) : '';
        if ( empty( $doctor ) ) {
            wp_send_json_error( array( 'message' => 'Укажите имя врача.' ), 400 );
        }

        // Снимок корзины
        $snapshot_arr = sharecart_min_build_snapshot();
        if ( empty( $snapshot_arr ) ) {
            wp_send_json_error( array( 'message' => 'Корзина пуста.' ), 400 );
        }

        // JSON и хэш
        $snapshot_json = wp_json_encode( $snapshot_arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $snapshot_hash = wp_hash( $snapshot_json );

        // Сохраняем купоны, которые уже были применены в корзине перед созданием ссылки.
        $coupons_arr  = sharecart_min_get_applied_coupon_codes();
        $coupons_json = wp_json_encode( $coupons_arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        // Ключ и даты
        $cart_key  = wp_generate_uuid4();
        $created   = current_time( 'mysql' );
        $expires   = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + YEAR_IN_SECONDS ); // 1 год

        // Автор
        $author_id = get_current_user_id() ?: 0;

        global $wpdb;
        $table = sharecart_min_table();

        // Вставка
        $inserted = $wpdb->insert(
            $table,
            array(
                'cart_key'      => $cart_key,
                'snapshot'      => $snapshot_json,
                'snapshot_hash' => $snapshot_hash,
                'doctor_name'   => $doctor,
                'author_id'     => $author_id,
                'order_id'      => null,
                'coupons'       => $coupons_json,
                'created_at'    => $created,
                'expires_at'    => $expires,
            ),
            array( '%s','%s','%s','%s','%d','%d','%s','%s','%s' )
        );

        if ( false === $inserted ) {
            error_log( '[ShareCart] DB insert failed: ' . $wpdb->last_error );
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( 'DB insert failed: ' . $wpdb->last_error, array( 'source' => 'sharecart' ) );
            }
            wp_send_json_error( array( 'message' => 'Ошибка сохранения в БД.' ), 500 );
        }

        $url = home_url( '/share-cart/' . rawurlencode( $cart_key ) . '/' );
        wp_send_json_success( array(
            'url'         => $url,
            'cart_key'      => $cart_key,
            'doctor_name'   => $doctor,
            'coupons_count' => count( $coupons_arr ),
        ) );

    } catch ( Throwable $e ) {
        error_log( '[ShareCart] generate_link exception: ' . $e->getMessage() );
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->error( 'generate_link exception: ' . $e->getMessage(), array( 'source' => 'sharecart' ) );
        }
        wp_send_json_error( array( 'message' => 'Внутренняя ошибка.' ), 500 );
    }
}
add_action( 'wp_ajax_sharecart_generate_link', 'sharecart_min_ajax_generate_link' );
add_action( 'wp_ajax_nopriv_sharecart_generate_link', 'sharecart_min_ajax_generate_link' );

/**
 * AJAX: импорт товаров в корзину по ключу
 */
function sharecart_min_ajax_add_items() {
    check_ajax_referer( 'sharecart_nonce', 'nonce' );

    $key = isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '';
    if ( empty( $key ) ) {
        wp_send_json_error( array( 'message' => 'Пустой ключ.' ), 400 );
    }

    global $wpdb;
    $table = sharecart_min_table();

    // Найдём запись
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_key = %s", $key ) );
    if ( ! $row ) {
        wp_send_json_error( array( 'message' => 'Ссылка не найдена.' ), 404 );
    }

    // Срок действия
    $now  = current_time( 'mysql' );
    if ( $row->expires_at < $now ) {
        wp_send_json_error( array( 'message' => 'Срок действия ссылки истёк.' ), 410 );
    }

    
    // Инициализируем корзину
    if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
        wc_load_cart();
    }

// Убедимся, что сессия Woo доступна (корзина уже инициализируется выше)
if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
    wc_load_cart();
}

if ( WC()->session ) {
    // Берём имя врача из строки «карты»
    $doctor_name = isset( $row->doctor_name ) ? trim( wp_strip_all_tags( (string) $row->doctor_name ) ) : '';

    if ( $doctor_name !== '' ) {
        // Кладём в сессию, чтобы дочехлить до checkout и заказа
        WC()->session->set( 'sharecart_doctor_name', $doctor_name );

        // (опционально) продублируем в cookie на всякий случай
        // NB: куки из AJAX могут не всегда прилипать в некоторых конфигурациях; сессии достаточно
        @setcookie(
            'sharecart_doctor_name',
            $doctor_name,
            time() + DAY_IN_SECONDS,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
    } else {
        // Если в карте пусто — чистим старое значение
        WC()->session->__unset( 'sharecart_doctor_name' );
        @setcookie( 'sharecart_doctor_name', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
    }
}

    $snapshot = json_decode( $row->snapshot, true );
    if ( ! is_array( $snapshot ) ) {
        wp_send_json_error( array( 'message' => 'Некорректный снапшот.' ), 500 );
    }

    $added   = array();
    $skipped = array();

    foreach ( $snapshot as $entry ) {
        $product_id   = isset( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;
        $variation_id = isset( $entry['variation_id'] ) ? absint( $entry['variation_id'] ) : 0;
        $qty          = isset( $entry['quantity'] ) ? max( 1, absint( $entry['quantity'] ) ) : 1;
        $attrs        = isset( $entry['attributes'] ) && is_array( $entry['attributes'] ) ? $entry['attributes'] : array();

        if ( ! $product_id ) {
            $skipped[] = array( 'product_id' => 0, 'reason' => 'Некорректный товар' );
            continue;
        }

        $product = wc_get_product( $variation_id ?: $product_id );
        if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            $skipped[] = array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'reason' => 'Недоступен для покупки' );
            continue;
        }

        // Добавляем
        $cart_key = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $attrs );
        if ( $cart_key ) {
            $added[] = array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $qty );
        } else {
            $skipped[] = array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'reason' => 'Не удалось добавить' );
        }
    }

    $saved_coupons = sharecart_min_parse_coupon_snapshot( $row->coupons ?? '' );
    $coupon_result = sharecart_min_apply_saved_coupons( $saved_coupons );

    wp_send_json_success( array(
        'added'           => $added,
        'skipped'         => $skipped,
        'coupons_applied' => $coupon_result['applied'],
        'coupons_skipped' => $coupon_result['skipped'],
        'redirect'        => wc_get_cart_url(),
    ) );
}
add_action( 'wp_ajax_sharecart_add_items', 'sharecart_min_ajax_add_items' );
add_action( 'wp_ajax_nopriv_sharecart_add_items', 'sharecart_min_ajax_add_items' );
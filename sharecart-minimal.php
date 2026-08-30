<?php
/**
 * Plugin Name: Share Cart – Minimal (Ref Doctor)
 * Description: Простая реферальная поделиться-корзиной для WooCommerce с именем врача.
 * Version: 1.1.1
 * Author: Vladislav Belov
 * Requires PHP: 7.3
 * Requires at least: 6.2
 * WC requires at least: 8.0
 * License: GPLv2 or later
 * Text Domain: sharecart-minimal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ГЛОБАЛЫ: пути/версии
 */
define( 'SHARECART_MIN_VER', '1.1.1' );
define( 'SHARECART_MIN_DB_VER', '1.1.0' );
define( 'SHARECART_MIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHARECART_MIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Проверка зависимостей: WooCommerce
 */
function sharecart_min_requirements_ok(): bool {
    if ( ! function_exists( 'WC' ) ) return false;
    // Допзащита: версия WC
    if ( defined( 'WC_VERSION' ) ) {
        return version_compare( WC_VERSION, '8.0', '>=' );
    }
    return true;
}

/**
 * Подключение модулей
 */
require_once SHARECART_MIN_DIR . 'includes/install.php';
require_once SHARECART_MIN_DIR . 'includes/rewrite.php';
require_once SHARECART_MIN_DIR . 'includes/ajax.php';



/**
 * SEO: Кастомные Open Graph теги для публичных ссылок /share-cart/{key}/
 *
 * Здесь мы правим og:title (и при желании description / url) именно для страниц
 * с query var sharecart_key, чтобы AIOSEO не подставлял «левый» заголовок.
 */
add_filter( 'aioseo_facebook_tags', function( $facebookMeta ) {

    // Определяем, что мы на странице шаринга
    $share_key = get_query_var( 'sharecart_key' );

    if ( empty( $share_key ) ) {
        // Не наша страница — ничего не трогаем
        return $facebookMeta;
    }

    // Заголовок, который увидит пользователь в превью ссылки
    // Можно поменять текст под свой проект
    $facebookMeta['og:title'] = 'Все для вашего здоровья';

    // Описание для превью (опционально, но обычно полезно)
    $facebookMeta['og:description'] = 'Персональная подборка товаров. Нажмите «Добавить в корзину», чтобы оформить заказ.';

    // Подстраховка на случай, если AIOSEO по каким-то причинам ставит не тот URL
    // (например, домашнюю страницу). Берём фактический текущий URL.
    if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
        $current_url = home_url( $_SERVER['REQUEST_URI'] );
        $facebookMeta['og:url'] = $current_url;
    }

    // При желании, можно задать и картинку превью (логотип клиники и т.п.)
    // Раскомментируй и поставь свой файл:
    //
    // $facebookMeta['og:image'] = 'https://example.com/path/to/share-image.jpg';

    return $facebookMeta;
} );


/**
 * TITLE: Кастомный <title> для публичных ссылок /share-cart/{key}/
 *
 * Перехватываем заголовок документа и подменяем его только на страницах
 * с query var sharecart_key.
 */
add_filter( 'pre_get_document_title', function( $title ) {

    // Проверяем, что мы на странице шаринга корзины
    $share_key = get_query_var( 'sharecart_key' );

    if ( empty( $share_key ) ) {
        // Обычные страницы / записи — не трогаем
        return $title;
    }

    // Здесь задаём ЧТО именно будет в <title></title>
    // Можно менять текст под задачу / бренд / врача
    $custom_title = 'Все для вашего здоровья';

    return $custom_title;
} );

/**
 * TITLE: Кастомный заголовок для AIOSEO на страницах /share-cart/{key}/
 *
 * Если AIOSEO активен и использует фильтр aioseo_title, мы ему подсунем
 * тот же самый заголовок, что и через pre_get_document_title.
 */
add_filter( 'aioseo_title', function( $title ) {

    $share_key = get_query_var( 'sharecart_key' );

    if ( empty( $share_key ) ) {
        return $title;
    }

    // Тот же текст, что и в pre_get_document_title,
    // чтобы не было рассинхрона.
    $custom_title = 'Все для вашего здоровья';

    return $custom_title;
} );


/**
 * Активация: создаём таблицу, регистрируем rewrite и флашим правила
 */
function sharecart_min_activate() {
    if ( ! sharecart_min_requirements_ok() ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'WooCommerce 8+ обязателен для работы плагина Share Cart – Minimal.' );
    }
    sharecart_min_install_table();
    update_option( 'sharecart_min_db_version', SHARECART_MIN_DB_VER );
    sharecart_min_register_rewrite(); // зарегистрировать правила до flush
    flush_rewrite_rules( false );
}
register_activation_hook( __FILE__, 'sharecart_min_activate' );

/**
 * Деактивация: только сброс rewrite (ничего не удаляем)
 */
function sharecart_min_deactivate() {
    flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'sharecart_min_deactivate' );

/**
 * Инициализация: регистрируем скрипты/локализацию, шорткоды, хуки Woo
 */
add_action( 'init', function() {
    if ( sharecart_min_requirements_ok() ) {
        sharecart_min_maybe_upgrade_schema();
    }

    // регистрируем rewrite (на случай, если кто-то отключал- включал плагины)
    sharecart_min_register_rewrite();

    // Шорткод для кнопки "Поделиться корзиной"
    add_shortcode( 'sharecart_button', 'sharecart_min_render_share_button' );
});

/**
 * Подключаем JS на фронте
 */
add_action( 'wp_enqueue_scripts', function() {
    // Скрипт для корзины и страницы шаринга
    wp_register_script(
        'sharecart-min',
        SHARECART_MIN_URL . 'assets/sharecart.js',
        array(), // без jQuery; можно добавить 'jquery' при желании
        SHARECART_MIN_VER,
        true
    );

    // Передача ajax_url и nonce
    wp_localize_script( 'sharecart-min', 'ShareCartVars', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'sharecart_nonce' ),
        'cart_url' => wc_get_cart_url(),
    ) );
});

/**
 * Внутренний флаг: панель уже была выведена на текущей странице.
 * Нужен, чтобы fallback для WooCommerce Cart Block не дублировал панель
 * на классической корзине, где работает хук woocommerce_cart_actions.
 */
function sharecart_min_panel_was_rendered(): bool {
    return ! empty( $GLOBALS['sharecart_min_panel_rendered'] );
}

function sharecart_min_mark_panel_rendered(): void {
    $GLOBALS['sharecart_min_panel_rendered'] = true;
}

/**
 * Вставка UI-тоггла на классической странице корзины.
 * Работает только там, где шаблон WooCommerce вызывает woocommerce_cart_actions.
 */
add_action( 'woocommerce_cart_actions', function() {
    wp_enqueue_script( 'sharecart-min' );
    echo sharecart_min_render_share_button( array(), '', false );
}, 9 );

/**
 * Fallback для новой WooCommerce Cart Block корзины.
 * В block-based корзине хук woocommerce_cart_actions не вызывается,
 * поэтому по умолчанию панель не появится ни в одной теме.
 */
add_filter( 'the_content', function( $content ) {
    if ( is_admin() || ! function_exists( 'is_cart' ) || ! is_cart() ) {
        return $content;
    }

    if ( sharecart_min_panel_was_rendered() ) {
        return $content;
    }

    if ( ! sharecart_min_requirements_ok() ) {
        return $content;
    }

    return $content . sharecart_min_render_share_button( array(), '', true );
}, 20 );

/**
 * Рендер кнопки/панели "Поделиться корзиной" (используется и как шорткод)
 *
 * @param array $atts
 * @param string $content
 * @param bool $echo  По умолчанию в шорткоде return, а в хуке мы выводим сами
 * @return string
 */
function sharecart_min_render_share_button( $atts = array(), $content = '', $echo = true ) {
    $html  = '<div id="sharecart-panel" class="sharecart-panel" style="border:1px solid #ddd;padding:12px;border-radius:6px;">';
    $html .= '  <button type="button" id="sharecart-toggle" class="button" style="margin-bottom:10px;">Поделиться корзиной</button>';
    $html .= '  <div id="sharecart-form" style="display:none;">';
    $html .= '    <label for="sharecart_doctor" style="display:block;margin:6px 0 4px;">Имя и ID врача (ФИО):</label>';
    $html .= '    <input type="text" id="sharecart_doctor" style="width:100%;max-width:420px;" placeholder="Введите ФИО и ID врача">';
    $html .= '    <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
    $html .= '      <button type="button" id="sharecart-generate" class="button button-primary">Создать ссылку</button>';
    $html .= '      <span id="sharecart-message" style="display:none;font-size:13px;"></span>';
    $html .= '    </div>';
    $html .= '    <div id="sharecart-result" style="margin-top:10px;display:none;">';
    $html .= '      <input type="text" id="sharecart-url" readonly style="width:100%;max-width:540px;margin-bottom:6px;">';
    $html .= '      <button type="button" id="sharecart-copy" class="button">Скопировать</button>';
    $html .= '    </div>';
    $html .= '  </div>';
    $html .= '</div>';

    // Подключаем скрипт
    wp_enqueue_script( 'sharecart-min' );
    sharecart_min_mark_panel_rendered();

    if ( $echo ) {
        return $html;
    } else {
        echo $html; // для хука cart_actions
        return '';
    }
}

/**
 * Сохранение имени врача в заказ (из Session/Cookie) при создании заказа.
 *
 * Вешаем на два хука, потому что за создание заказа отвечают два разных пути:
 * - woocommerce_checkout_create_order — классический чекаут ([woocommerce_checkout]);
 * - woocommerce_store_api_checkout_update_order_meta — блочный чекаут (Cart & Checkout blocks).
 * Оба передают $order первым (и единственным нужным нам) аргументом.
 */
function sharecart_min_write_doctor_meta_to_order( $order ) {
    try {
        $doctor = null;
        $session_available = function_exists( 'WC' ) && WC()->session;

        if ( $session_available ) {
            $doctor = WC()->session->get( 'sharecart_doctor_name' );
        }
        if ( empty( $doctor ) && isset( $_COOKIE['sharecart_doctor_name'] ) ) {
            $doctor = sanitize_text_field( wp_unslash( $_COOKIE['sharecart_doctor_name'] ) );
        }
        if ( ! empty( $doctor ) ) {
            $order->update_meta_data( '_ref_doctor_name', $doctor );
        }
    } catch ( Throwable $e ) {
        error_log( '[ShareCart] write doctor meta error: ' . $e->getMessage() );
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->error( 'write doctor meta: ' . $e->getMessage(), array( 'source' => 'sharecart' ) );
        }
    }
}
add_action( 'woocommerce_checkout_create_order', 'sharecart_min_write_doctor_meta_to_order', 10, 1 );
add_action( 'woocommerce_store_api_checkout_update_order_meta', 'sharecart_min_write_doctor_meta_to_order', 10, 1 );



/**
 * Страхуем наличие стандартных полей имени в checkout
 */
add_filter('woocommerce_checkout_fields', function( $fields ) {

    if ( empty( $fields['billing']['billing_first_name'] ) ) {
        $fields['billing']['billing_first_name'] = [
            'type'     => 'text',
            'label'    => __('First name', 'woocommerce'),
            'required' => true,
            'priority' => 10,
            'class'    => ['form-row-first'],
        ];
    }

    if ( empty( $fields['billing']['billing_last_name'] ) ) {
        $fields['billing']['billing_last_name'] = [
            'type'     => 'text',
            'label'    => __('Last name', 'woocommerce'),
            'required' => true,
            'priority' => 20,
            'class'    => ['form-row-last'],
        ];
    }

    return $fields;
});

/**
 * Гарантированная запись имени в заказ при создании
 */
add_action('woocommerce_checkout_create_order', function( $order, $data ) {
    $billing_first = isset($_POST['billing_first_name']) ? sanitize_text_field( wp_unslash($_POST['billing_first_name']) ) : '';
    $billing_last  = isset($_POST['billing_last_name'])  ? sanitize_text_field( wp_unslash($_POST['billing_last_name']) )  : '';

    if ( empty($billing_first) && WC()->session ) {
        $maybe_first = WC()->session->get('billing_first_name');
        if ( ! empty($maybe_first) ) {
            $billing_first = sanitize_text_field( $maybe_first );
        }
    }

    if ( $billing_first !== '' ) {
        $order->set_billing_first_name( $billing_first );
    }
    if ( $billing_last !== '' ) {
        $order->set_billing_last_name( $billing_last );
    }

    // Запишем в мету заказа для твоего бокса
    if ( isset($_POST['doctor']) ) {
        $doctor = sanitize_text_field( wp_unslash($_POST['doctor']) );
        if ( $doctor !== '' ) {
            $order->update_meta_data( '_ref_doctor_name', $doctor );
        }
    }
}, 20, 2);


/**
 * Вывод в email админа: добавляем строку "Имя врача"
 */
add_filter( 'woocommerce_email_order_meta_fields', function( $fields, $sent_to_admin, $order ) {

    // Показываем "Имя врача" ТОЛЬКО в письмах админу
    if ( ! $sent_to_admin ) {
        return $fields;
    }

    $doctor = $order->get_meta( '_ref_doctor_name' );

    if ( ! empty( $doctor ) ) {
        $fields['sharecart_doctor_name'] = array(
            'label' => 'Имя врача',
            'value' => $doctor,
        );
    }

    return $fields;

}, 10, 3 );

/**
 * Метабокс в админке заказа: крупно показываем имя врача
 */
add_action( 'add_meta_boxes', function() {
    // wc_get_page_screen_id() возвращает верный ID экрана как для классического
    // хранения заказов (шапка 'shop_order'), так и для HPOS ('woocommerce_page_wc-orders').
    $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

    add_meta_box(
        'sharecart_doctor_box',
        'Имя врача (ShareCart)',
        function( $post_or_order ) {
            // wc_get_order() сам понимает и WP_Post, и WC_Order, и ID — и всегда
            // отдаёт свежий объект из актуального хранилища (HPOS или postmeta).
            $order  = wc_get_order( $post_or_order );
            $doctor = $order ? $order->get_meta( '_ref_doctor_name' ) : '';

            echo '<div style="padding:8px 4px;">';
            if ( $doctor ) {
                echo '<strong style="font-size:14px;">' . esc_html( $doctor ) . '</strong>';
            } else {
                echo '<em>Не задано</em>';
            }
            echo '</div>';
        },
        $screen,
        'side',
        'high'
    );
});

/**
 * Простая страница в админке для очистки старых записей
 */
add_action( 'admin_menu', function() {
    add_menu_page(
        'ShareCart Tools',
        'ShareCart',
        'manage_options',
        'sharecart-tools',
        'sharecart_min_tools_page',
        'dashicons-cart',
        56
    );
});
function sharecart_min_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'sharecart_shared';

    // Обработка удаления
    if ( isset( $_POST['sharecart_cleanup'] ) && check_admin_referer( 'sharecart_cleanup_action', 'sharecart_cleanup_nonce' ) ) {
        $days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 365;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        // Удалим по expires_at < NOW или created_at < threshold (на всякий случай)
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at < %s OR created_at < %s",
                current_time( 'mysql' ),
                $threshold
            )
        );
        echo '<div class="notice notice-success"><p>Удалено записей: ' . esc_html( (string) ( $deleted ?: 0 ) ) . '</p></div>';
    }

    echo '<div class="wrap"><h1>ShareCart – инструменты</h1>';
    echo '<form method="post">';
    wp_nonce_field( 'sharecart_cleanup_action', 'sharecart_cleanup_nonce' );
    echo '<p>Очистить записи старше (дней): <input type="number" name="days" value="365" min="1" max="3650" /></p>';
    echo '<p><button class="button button-primary" name="sharecart_cleanup" value="1">Очистить</button></p>';
    echo '</form>';

    // Короткая статистика
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    echo '<h2>Статистика</h2>';
    echo '<p>Всего ссылок: ' . esc_html( (string) $count ) . '</p>';
    echo '</div>';
}
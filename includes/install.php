<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Создание таблицы БД для хранения ссылок/снапшотов.
 * Таблица: {prefix}sharecart_shared
 */
function sharecart_min_install_table() {
    global $wpdb;

    $table = $wpdb->prefix . 'sharecart_shared';
    $charset_collate = $wpdb->get_charset_collate();

    // Важно: dbDelta чувствителен к форматированию. Ключевые слова и индексы — с новой строки.
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        cart_key varchar(64) NOT NULL,
        snapshot longtext NOT NULL,
        snapshot_hash varchar(64) NOT NULL,
        doctor_name varchar(255) NOT NULL,
        author_id bigint(20) unsigned NOT NULL DEFAULT 0,
        order_id bigint(20) unsigned DEFAULT NULL,
        coupons longtext DEFAULT NULL,
        created_at datetime NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY cart_key (cart_key),
        KEY snapshot_hash (snapshot_hash),
        KEY author_id (author_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Лёгкая миграция схемы при обновлении плагина.
 * Нужна, чтобы на уже установленном сайте dbDelta добавил новые поля,
 * например coupons, без ручной переактивации плагина.
 */
function sharecart_min_maybe_upgrade_schema() {
    $current = get_option( 'sharecart_min_db_version' );

    if ( defined( 'SHARECART_MIN_DB_VER' ) && $current !== SHARECART_MIN_DB_VER ) {
        sharecart_min_install_table();
        update_option( 'sharecart_min_db_version', SHARECART_MIN_DB_VER );
    }
}

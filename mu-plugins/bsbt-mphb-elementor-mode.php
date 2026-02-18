<?php
/**
 * Plugin Name: BSBT – MotoPress HB: Elementor Mode + Default & Bulk Template
 * Description: Force Elementor mode for MotoPress HB; set default template ID 2289 for new room types; bulk-apply to all existing ones.
 * Author: BS Business Travelling
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * 0) Убедимся, что интеграция плагинов доступна (эту строчку WP подхватит даже если тема молчит)
 */
add_action( 'after_setup_theme', function () {
    add_theme_support( 'mphb-elementor' );
}, 5);

/**
 * 1) Принудительно включаем Elementor Template Mode
 *    (UI может не показать третий пункт, но режим всё равно будет элементор)
 */
add_filter( 'mphb_template_mode', function( $mode ) {
    return 'elementor';
}, 999);

/**
 * 2) Ставим шаблон по умолчанию для НОВЫХ Accommodation Types
 */
add_filter( 'mphb_default_template_id', function( $template_id, $post_type ) {
    if ( $post_type === 'mphb_room_type' ) {
        return 2289; // <-- ID вашего Horizontal Card Template
    }
    return $template_id;
}, 10, 2);

/**
 * 3) ОДНОРАЗОВО применяем шаблон 2289 ко ВСЕМ существующим Accommodation Types
 *    Помечаем флагом опции, чтобы не гонять при каждом заходе.
 */
add_action( 'admin_init', function () {

    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('bsbt_mphb_templates_migrated') ) return;

    $room_type = 'mphb_room_type';
    $target_id = 2289;

    // Попробуем вычислить, в какой мета-ключ MotoPress пишет выбранный template id
    $meta_key = null; $meta_value = (string)$target_id;

    // Ищем любой объект, где уже проставлен этот шаблон вручную
    $ref = get_posts([
        'post_type'      => $room_type,
        'posts_per_page' => 1,
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'mphb_template_id',  'value' => $target_id ],
            [ 'key' => '_mphb_template_id', 'value' => $target_id ],
            [ 'key' => '_wp_page_template', 'value' => $target_id ],
        ],
        'fields' => 'ids',
    ]);

    if ( ! empty($ref) ) {
        $all_meta = get_post_meta( $ref[0] );
        foreach ( $all_meta as $k => $vals ) {
            foreach ( (array)$vals as $v ) {
                if ( (string)$v === $meta_value ) { $meta_key = $k; break 2; }
            }
        }
    }
    if ( ! $meta_key ) {
        // дефолтные варианты на разных версиях
        $meta_key = 'mphb_template_id';
    }

    // Обновляем ВСЕ существующие объекты
    $all = get_posts([
        'post_type'      => $room_type,
        'posts_per_page' => -1,
        'post_status'    => ['publish','draft','pending','future','private'],
        'fields'         => 'ids',
    ]);
    foreach ( $all as $post_id ) {
        $current = get_post_meta( $post_id, $meta_key, true );
        if ( (string)$current !== $meta_value ) {
            update_post_meta( $post_id, $meta_key, $meta_value );
        }
    }

    update_option( 'bsbt_mphb_templates_migrated', 1, true );
});

/**
 * 4) Админ-уведомление: режим форсирован и шаблон применён
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can('manage_options') ) return;
    echo '<div class="notice notice-success is-dismissible"><p><strong>BSBT:</strong> MotoPress HB работает в <em>Elementor mode</em>. Шаблон ID 2289 задан по умолчанию и применён ко всем существующим объектам.</p></div>';
});

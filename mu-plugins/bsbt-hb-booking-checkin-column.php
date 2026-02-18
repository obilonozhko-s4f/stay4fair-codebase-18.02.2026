<?php
/**
 * Plugin Name: BSBT – HB Booking: Check-in time column (robust v2)
 */
if ( ! defined('ABSPATH') ) exit;

/** Универсальный поиск времени заезда в мете брони */
function bsbt_get_booking_checkin( $booking_id ) {
    // 1) прямое зеркало
    $direct = get_post_meta( $booking_id, 'checkin_time', true );
    if ( $direct !== '' ) return (string) $direct;

    // 2) известные массивы плагина
    $containers = array(
        'mphb_customer_fields',
        'mphb_checkout_fields',
        'mphb_additional_customer_fields',
        'mphb_extra_fields',
    );
    foreach ( $containers as $key ) {
        $data = get_post_meta( $booking_id, $key, true );
        if ( is_array($data) ) {
            // явные ключи
            foreach ( array('checkin_time','customer_checkin_time','arrival_time','check_in_time') as $k ) {
                if ( isset($data[$k]) ) {
                    $v = is_array($data[$k]) ? reset($data[$k]) : $data[$k];
                    return (string) $v;
                }
            }
            // эвристика по подстроке
            foreach ( $data as $k => $v ) {
                if ( stripos($k,'checkin') !== false || stripos($k,'arrival') !== false ) {
                    return is_array($v) ? (string) reset($v) : (string) $v;
                }
            }
        } elseif ( is_string($data) && $data ) {
            // иногда плагины кладут JSON
            $maybe = json_decode($data, true);
            if ( is_array($maybe) ) {
                foreach ( array('checkin_time','customer_checkin_time','arrival_time','check_in_time') as $k ) {
                    if ( isset($maybe[$k]) ) {
                        $v = is_array($maybe[$k]) ? reset($maybe[$k]) : $maybe[$k];
                        return (string) $v;
                    }
                }
            }
        }
    }

    // 3) бэкап: перебор всей меты и поиск по ключу/значению
    $all = get_post_meta( $booking_id );
    foreach ( $all as $k => $vals ) {
        if ( stripos($k,'checkin') !== false || stripos($k,'arrival') !== false ) {
            $v = is_array($vals) ? reset($vals) : $vals;
            if ( is_array($v) ) $v = reset($v);
            if ( is_string($v) && $v !== '' ) return $v;
        }
        // если значение — сериализованный массив
        $first = is_array($vals) ? reset($vals) : $vals;
        if ( is_string($first) && strpos($first,'{') !== false ) {
            $maybe = json_decode($first, true);
            if ( is_array($maybe) ) {
                foreach ( $maybe as $kk => $vv ) {
                    if ( stripos($kk,'checkin') !== false || stripos($kk,'arrival') !== false ) {
                        return is_array($vv) ? (string) reset($vv) : (string) $vv;
                    }
                }
            }
        }
    }

    return '';
}

/** Колонка */
add_filter( 'manage_mphb_booking_posts_columns', function( $cols ){
    $new = [];
    foreach ( $cols as $key => $label ) {
        $new[$key] = $label;
        if ( in_array($key, array('mphb_phone','phone','title'), true) ) {
            $new['bsbt_checkin_time'] = __( 'Ankunftszeit', 'bsbt' );
        }
    }
    if ( ! isset($new['bsbt_checkin_time']) ) $new['bsbt_checkin_time'] = __( 'Ankunftszeit', 'bsbt' );
    return $new;
});
add_action( 'manage_mphb_booking_posts_custom_column', function( $col, $post_id ){
    if ( 'bsbt_checkin_time' !== $col ) return;
    $val = bsbt_get_booking_checkin( $post_id );
    echo $val !== '' ? esc_html($val) : '—';
}, 10, 2);

/** Сортировка по зеркальной мете (когда появится) */
add_filter( 'manage_edit-mphb_booking_sortable_columns', function( $cols ){
    $cols['bsbt_checkin_time'] = 'bsbt_checkin_time'; return $cols;
});
add_action( 'pre_get_posts', function( $q ){
    if ( ! is_admin() || ! $q->is_main_query() ) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( empty($screen) || 'edit-mphb_booking' !== $screen->id ) return;
    if ( $q->get('orderby') === 'bsbt_checkin_time' ) {
        $q->set( 'meta_key', 'checkin_time' );
        $q->set( 'orderby', 'meta_value' );
    }
});

/** Зеркалим в checkin_time при сохранении (любой из ключей) */
add_action( 'save_post_mphb_booking', function( $post_id ){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;

    // Из POST админки
    if ( isset($_POST['mphb_customer_fields']) && is_array($_POST['mphb_customer_fields']) ) {
        $arr = wp_unslash($_POST['mphb_customer_fields']);
        foreach ( array('checkin_time','customer_checkin_time','arrival_time','check_in_time') as $k ) {
            if ( isset($arr[$k]) && $arr[$k] !== '' ) {
                $val = is_array($arr[$k]) ? reset($arr[$k]) : $arr[$k];
                update_post_meta( $post_id, 'checkin_time', sanitize_text_field($val) );
                break;
            }
        }
    }

    // Если пришло из фронта и поле уже лежит в массивах — подхватим
    if ( '' === get_post_meta($post_id, 'checkin_time', true) ) {
        $val = bsbt_get_booking_checkin( $post_id );
        if ( $val !== '' ) update_post_meta( $post_id, 'checkin_time', sanitize_text_field($val) );
    }
}, 20);

/** Разовая миграция значений для уже созданных броней (при заходе в список) */
add_action( 'load-edit.php', function(){
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( empty($screen) || 'edit-mphb_booking' !== $screen->id ) return;
    if ( get_transient('bsbt_checkin_migrated_v2') ) return;

    $q = new WP_Query(array(
        'post_type'      => 'mphb_booking',
        'posts_per_page' => 500,
        'post_status'    => array('publish','pending','draft','future','private'),
        'fields'         => 'ids',
    ));
    foreach ( $q->posts as $bid ) {
        if ( get_post_meta($bid, 'checkin_time', true) !== '' ) continue;
        $val = bsbt_get_booking_checkin( $bid );
        if ( $val !== '' ) update_post_meta( $bid, 'checkin_time', sanitize_text_field($val) );
    }
    set_transient('bsbt_checkin_migrated_v2', 1, HOUR_IN_SECONDS);
});

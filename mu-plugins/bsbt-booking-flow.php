<?php
/**
 * Plugin Name: BSBT – Booking Flow Mode & Channel
 * Description: Добавляет к брони MotoPress Hotel Booking режим flow_mode (auto/manual) и канал (Booking.com, Airbnb, Direct и т.д.) с метабоксом и колонкой в админке. Для flow_mode=manual блокирует авто-письма MPHB при работе с бронью в админке.
 * Author: BS Business Travelling / Stay4Fair.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==========================================
// Константы мета-ключей
// ==========================================

if ( ! defined( 'BSBT_FLOW_META' ) ) {
    define( 'BSBT_FLOW_META', '_bsbt_flow_mode' ); // 'auto' | 'manual'
}

if ( ! defined( 'BSBT_CHANNEL_META' ) ) {
    define( 'BSBT_CHANNEL_META', '_bsbt_channel' ); // booking_com | airbnb | direct | phone | email | whatsapp | other
}

// Глобальный флаг для suppression писем в рамках текущего запроса
$GLOBALS['bsbt_suppress_booking_email_for'] = 0;

// ==========================================
// 1) Дефолтный flow_mode при создании брони
// ==========================================
//
// Логика:
// - Бронь создаётся через фронт → is_admin() == false → flow_mode = 'auto'
// - Бронь создаётся/правится в админке → is_admin() == true → flow_mode = 'manual'
// - Если meta уже есть, не трогаем (чтоб не перезаписать руками выбранное значение)
//

add_action( 'save_post_mphb_booking', 'bsbt_set_default_flow_mode', 10, 3 );
function bsbt_set_default_flow_mode( $post_id, $post, $update ) {

    // Не работаем с ревизиями
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Если meta уже есть — выходим
    $existing = get_post_meta( $post_id, BSBT_FLOW_META, true );
    if ( ! empty( $existing ) ) {
        return;
    }

    // Определяем дефолт
    $mode = is_admin() ? 'manual' : 'auto';

    update_post_meta( $post_id, BSBT_FLOW_META, $mode );
}

// ==========================================
// 2) Метабокс "BSBT Booking Flow"
// ==========================================
//
// Показывается на экране редактирования брони (Bookings → конкретная бронь).
//

add_action( 'add_meta_boxes_mphb_booking', 'bsbt_add_flow_metabox' );
function bsbt_add_flow_metabox() {
    add_meta_box(
        'bsbt-booking-flow',
        'BSBT – Booking Flow',
        'bsbt_render_flow_metabox',
        'mphb_booking',
        'side',
        'high'
    );
}

function bsbt_render_flow_metabox( $post ) {

    // Значения из меты
    $flow_mode = get_post_meta( $post->ID, BSBT_FLOW_META, true );
    $channel   = get_post_meta( $post->ID, BSBT_CHANNEL_META, true );

    if ( empty( $flow_mode ) ) {
        $flow_mode = is_admin() ? 'manual' : 'auto';
    }

    // nonce для безопасности
    wp_nonce_field( 'bsbt_save_booking_flow', 'bsbt_booking_flow_nonce' );

    ?>
    <p><strong>Flow mode</strong></p>
    <p>
        <select name="bsbt_flow_mode" style="width:100%;">
            <option value="auto"   <?php selected( $flow_mode, 'auto' ); ?>>Auto (online booking)</option>
            <option value="manual" <?php selected( $flow_mode, 'manual' ); ?>>Manual (admin / OTA)</option>
        </select>
    </p>

    <hr/>

    <p><strong>Channel</strong></p>
    <p>
        <select name="bsbt_channel" style="width:100%;">
            <option value=""                  <?php selected( $channel, '' ); ?>>— Not set —</option>
            <option value="direct_site"       <?php selected( $channel, 'direct_site' ); ?>>Direct – Website</option>
            <option value="booking_com"       <?php selected( $channel, 'booking_com' ); ?>>Booking.com</option>
            <option value="airbnb"            <?php selected( $channel, 'airbnb' ); ?>>Airbnb</option>
            <option value="expedia"           <?php selected( $channel, 'expedia' ); ?>>Expedia / Others</option>
            <option value="phone"             <?php selected( $channel, 'phone' ); ?>>Phone</option>
            <option value="email"             <?php selected( $channel, 'email' ); ?>>Email request</option>
            <option value="whatsapp"          <?php selected( $channel, 'whatsapp' ); ?>>WhatsApp / Messenger</option>
            <option value="other"             <?php selected( $channel, 'other' ); ?>>Other</option>
        </select>
    </p>

    <hr/>

    <p style="font-size:11px; color:#666; line-height:1.4;">
        <strong>Hint:</strong> 
        <br/>Use <em>Manual</em> for OTAs (Booking.com, Airbnb, phone) where you send invoice / voucher manually.
        <br/>Use <em>Auto</em> for direct online bookings with automatic emails.
    </p>
    <?php
}

// ==========================================
// 3) Сохранение меты из метабокса
// ==========================================

add_action( 'save_post_mphb_booking', 'bsbt_save_booking_flow_meta', 20, 3 );
function bsbt_save_booking_flow_meta( $post_id, $post, $update ) {

    // Только mphb_booking
    if ( $post->post_type !== 'mphb_booking' ) {
        return;
    }

    // Проверка nonce
    if ( ! isset( $_POST['bsbt_booking_flow_nonce'] ) || 
         ! wp_verify_nonce( $_POST['bsbt_booking_flow_nonce'], 'bsbt_save_booking_flow' ) ) {
        return;
    }

    // Проверка прав
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Сохраняем Flow mode
    if ( isset( $_POST['bsbt_flow_mode'] ) ) {
        $mode = sanitize_text_field( wp_unslash( $_POST['bsbt_flow_mode'] ) );

        if ( ! in_array( $mode, array( 'auto', 'manual' ), true ) ) {
            $mode = 'auto';
        }

        update_post_meta( $post_id, BSBT_FLOW_META, $mode );
    }

    // Сохраняем Channel
    if ( isset( $_POST['bsbt_channel'] ) ) {
        $channel = sanitize_text_field( wp_unslash( $_POST['bsbt_channel'] ) );
        update_post_meta( $post_id, BSBT_CHANNEL_META, $channel );
    }
}

// ==========================================
// 4) Колонки в списке броней (Bookings)
// ==========================================
//
// Добавляем:
// - колонку Flow
// - колонку Channel
//

add_filter( 'manage_mphb_booking_posts_columns', 'bsbt_add_booking_flow_columns' );
function bsbt_add_booking_flow_columns( $columns ) {

    // Вставим после колонки с title
    $new = array();

    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;

        if ( $key === 'title' ) { // после названия
            $new['bsbt_flow_mode'] = 'Flow';
            $new['bsbt_channel']   = 'Channel';
        }
    }

    return $new;
}

add_action( 'manage_mphb_booking_posts_custom_column', 'bsbt_render_booking_flow_columns', 10, 2 );
function bsbt_render_booking_flow_columns( $column, $post_id ) {

    if ( $column === 'bsbt_flow_mode' ) {
        $mode = get_post_meta( $post_id, BSBT_FLOW_META, true );

        if ( $mode === 'manual' ) {
            echo '<span style="display:inline-block;padding:2px 6px;border-radius:4px;background:#e0b849;color:#222;font-size:11px;">Manual</span>';
        } elseif ( $mode === 'auto' ) {
            echo '<span style="display:inline-block;padding:2px 6px;border-radius:4px;background:#212f54;color:#fff;font-size:11px;">Auto</span>';
        } else {
            echo '<span style="color:#999;font-size:11px;">—</span>';
        }
    }

    if ( $column === 'bsbt_channel' ) {
        $channel = get_post_meta( $post_id, BSBT_CHANNEL_META, true );

        $labels = array(
            ''             => '—',
            'direct_site'  => 'Direct',
            'booking_com'  => 'Booking.com',
            'airbnb'       => 'Airbnb',
            'expedia'      => 'Expedia/Other OTA',
            'phone'        => 'Phone',
            'email'        => 'Email',
            'whatsapp'     => 'WhatsApp',
            'other'        => 'Other',
        );

        $label = isset( $labels[ $channel ] ) ? $labels[ $channel ] : $channel;

        if ( $label === '—' ) {
            echo '<span style="color:#999;font-size:11px;">—</span>';
        } else {
            echo '<span style="font-size:11px;">' . esc_html( $label ) . '</span>';
        }
    }
}

// ==========================================
// 5) ОТКЛЮЧЕНИЕ АВТО-ПИСЕМ ДЛЯ flow_mode = manual
// ==========================================
//
// Идея:
// - При сохранении/создании брони в админке смотрим, какой flow_mode будет у этой брони.
// - Если manual → запоминаем ID брони в глобале $bsbt_suppress_booking_email_for.
// - Фильтр pre_wp_mail проверяет: если глобал > 0 и в subject/body встречается этот booking_id
//   (как число, с или без #) → возвращаем WP_Error и блокируем отправку.
// - Для фронта (auto-брони) suppression не включается вообще.
//

add_action( 'save_post_mphb_booking', 'bsbt_flag_manual_booking_email_suppression', 1, 3 );
function bsbt_flag_manual_booking_email_suppression( $post_id, $post, $update ) {

    // Только админ, без автосейвов/ревизий
    if ( ! is_admin() ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Какой режим будет после сохранения?
    $mode_meta = get_post_meta( $post_id, BSBT_FLOW_META, true );

    // Смотрим, что пришло из формы (если пользователь руками сменил режим)
    $posted_mode = isset( $_POST['bsbt_flow_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['bsbt_flow_mode'] ) ) : '';

    if ( $posted_mode === 'auto' || $mode_meta === 'auto' ) {
        // Явно авто → suppression не нужен
        return;
    }

    // Если явно выбрали manual или мета пустая, но мы в админке → считаем бронь manual
    if ( $posted_mode === 'manual' || $mode_meta === 'manual' || ( empty( $mode_meta ) && is_admin() ) ) {
        $GLOBALS['bsbt_suppress_booking_email_for'] = (int) $post_id;
    }
}

// Глобальный фильтр wp_mail: мягко глушим письма для текущей manual-брони
add_filter( 'pre_wp_mail', 'bsbt_maybe_block_manual_booking_mail', 10, 2 );
function bsbt_maybe_block_manual_booking_mail( $null, $atts ) {

    $booking_id = isset( $GLOBALS['bsbt_suppress_booking_email_for'] )
        ? (int) $GLOBALS['bsbt_suppress_booking_email_for']
        : 0;

    // Если не в процессе сохранения manual-брони — выходим
    if ( $booking_id <= 0 ) {
        return $null;
    }

    if ( ! is_admin() ) {
        // На фронте ничего не режем
        return $null;
    }

    $subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
    $message = isset( $atts['message'] ) ? (string) $atts['message'] : '';

    if ( $subject === '' && $message === '' ) {
        return $null;
    }

    // Ищем booking_id как число, возможно с # перед ним.
    // Например: "Reservation 123", "Booking #123", "Buchung Nr. 123" и т.п.
    $id_str  = (string) $booking_id;
    $pattern = '/(?:#\s*)?' . preg_quote( $id_str, '/' ) . '\b/';

    if ( ! preg_match( $pattern, $subject . ' ' . $message ) ) {
        // В письме нет этого ID → не наше, пропускаем
        return $null;
    }

    // Блокируем авто-отправку
    return new WP_Error(
        'bsbt_manual_booking_no_auto_email',
        sprintf( 'Auto email suppressed for manual booking #%d', $booking_id ),
        $atts
    );
}

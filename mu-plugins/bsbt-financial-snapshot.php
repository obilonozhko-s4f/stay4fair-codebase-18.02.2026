<?php
/**
 * Plugin Name: BSBT – Financial Snapshot (Enterprise Stable)
 * Version: 2.6.0
 * Description: Замораживает финансовые показатели (Snapshot) при подтверждении брони. 
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Основной триггер на смену статуса через ядро WordPress
 */
add_action('transition_post_status', 'bsbt_snapshot_on_confirmed', 10, 3);

function bsbt_snapshot_on_confirmed($new_status, $old_status, $post) {

    // 1. Фильтр по типу поста
    if ( ! $post || $post->post_type !== 'mphb_booking' ) return;

    // 2. Определение перехода в "Confirmed" (учитываем системные префиксы)
    $is_confirmed  = ( $new_status === 'confirmed' || $new_status === 'mphb-confirmed' );
    $was_confirmed = ( $old_status === 'confirmed' || $old_status === 'mphb-confirmed' );

    if ( ! $is_confirmed || $was_confirmed ) return;

    $booking_id = $post->ID;

    // 3. Защита от дублей (Idempotency)
    if ( get_post_meta($booking_id, '_bsbt_snapshot_locked_at', true) ) return;

    if ( ! function_exists('MPHB') ) return;

    // 4. Получаем данные через репозиторий MPHB
    $booking = MPHB()->getBookingRepository()->findById($booking_id);
    if ( ! $booking ) return;

    $rooms = $booking->getReservedRooms();
    if ( empty($rooms) ) return;

    $room_type_id = $rooms[0]->getRoomTypeId();

    // 5. Валидация дат и расчет ночей
    $check_in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
    $check_out = get_post_meta($booking_id, 'mphb_check_out_date', true);

    if ( ! $check_in || ! $check_out ) return;
    
    $ts_in  = strtotime($check_in);
    $ts_out = strtotime($check_out);

    if ( ! $ts_in || ! $ts_out || $ts_out <= $ts_in ) return;

    $nights = max(1, ($ts_out - $ts_in) / DAY_IN_SECONDS);

    // 6. Получение актуальной цены за ночь (PPN)
    $ppn = (float) get_post_meta($room_type_id, 'owner_price_per_night', true);
    if ( ! $ppn && function_exists('get_field') ) {
        $ppn = (float) get_field('owner_price_per_night', $room_type_id);
    }

    if ( $ppn <= 0 ) return;

    // 7. Константы (Комиссии и НДС)
    $fee_rate = defined('BSBT_FEE') ? (float)BSBT_FEE : 0.0;
    $vat_rate = defined('BSBT_VAT_ON_FEE') ? (float)BSBT_VAT_ON_FEE : 0.0;
    $model    = get_post_meta($room_type_id, '_bsbt_business_model', true) ?: 'model_a';

    /**
     * =========================================================
     * NEW (v2.6.0): Фиксация payout entity
     * =========================================================
     */

    $manager_user_id = (int) get_post_meta($room_type_id, 'bsbt_owner_id', true);

    if ( $model === 'model_b' && $manager_user_id > 0 ) {
        $payout_type   = 'user';
        $payout_entity = $manager_user_id;
    } else {
        $payout_type   = 'apartment';
        $payout_entity = 0;
    }

    // 8. Расчеты (Round 2)
    $owner_payout = round($ppn * $nights, 2);
    $fee_net      = round($owner_payout * $fee_rate, 2);
    $fee_vat      = round($fee_net * $vat_rate, 2);
    $fee_gross    = round($fee_net + $fee_vat, 2);

    // 9. Атомарная запись Snapshot
    $snapshot = [
        '_bsbt_snapshot_room_type_id'    => $room_type_id,
        '_bsbt_snapshot_ppn'             => $ppn,
        '_bsbt_snapshot_nights'          => $nights,
        '_bsbt_snapshot_model'           => $model,

        // NEW fields (v2.6.0)
        '_bsbt_snapshot_manager_user_id' => $manager_user_id,
        '_bsbt_snapshot_payout_type'     => $payout_type,
        '_bsbt_snapshot_payout_entity'   => $payout_entity,

        '_bsbt_snapshot_owner_payout'    => $owner_payout,
        '_bsbt_snapshot_fee_rate'        => $fee_rate,
        '_bsbt_snapshot_fee_vat_rate'    => $vat_rate,
        '_bsbt_snapshot_fee_net_total'   => $fee_net,
        '_bsbt_snapshot_fee_vat_total'   => $fee_vat,
        '_bsbt_snapshot_fee_gross_total' => $fee_gross,
        '_bsbt_snapshot_locked_at'       => current_time('mysql'),
        '_bsbt_snapshot_version'         => '2.6.0', // Обновлена версия
    ];

    foreach ( $snapshot as $key => $val ) {
        update_post_meta($booking_id, $key, $val);
    }

    // 10. Синхронизация статуса решения (Decision)
    $current_decision = get_post_meta($booking_id, '_bsbt_owner_decision', true);
    if ( ! $current_decision ) {
        update_post_meta($booking_id, '_bsbt_owner_decision', 'approved');
    }
}

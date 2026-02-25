<?php
/**
 * Plugin Name: BSBT – Financial Snapshot (Enterprise Stable)
 * Version: 3.2.0
 * Description: Замораживает финансовые показатели (Snapshot) при подтверждении брони.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Основной триггер на смену статуса
 */
add_action( 'transition_post_status', 'bsbt_snapshot_on_confirmed', 10, 3 );

/**
 * Основная функция фиксации snapshot
 */
function bsbt_snapshot_on_confirmed( $new_status, $old_status, $post ) {

    // Проверка типа
    if ( ! $post || $post->post_type !== 'mphb_booking' ) return;

    // Переход в confirmed
    $is_confirmed  = in_array( (string) $new_status, [ 'confirmed', 'mphb-confirmed' ], true );
    $was_confirmed = in_array( (string) $old_status, [ 'confirmed', 'mphb-confirmed' ], true );

    if ( ! $is_confirmed || $was_confirmed ) return;

    $booking_id = (int) $post->ID;

    // Idempotency lock
    if ( get_post_meta( $booking_id, '_bsbt_snapshot_locked_at', true ) ) return;

    if ( ! function_exists( 'MPHB' ) ) return;

    $booking = MPHB()->getBookingRepository()->findById( $booking_id );
    if ( ! $booking ) return;

    $rooms = $booking->getReservedRooms();
    if ( empty( $rooms ) ) return;

    $room_type_id = (int) $rooms[0]->getRoomTypeId();
    if ( $room_type_id <= 0 ) return;

    // Даты
    $check_in  = (string) get_post_meta( $booking_id, 'mphb_check_in_date', true );
    $check_out = (string) get_post_meta( $booking_id, 'mphb_check_out_date', true );

    if ( ! $check_in || ! $check_out ) return;

    $ts_in  = strtotime( $check_in );
    $ts_out = strtotime( $check_out );

    if ( ! $ts_in || ! $ts_out || $ts_out <= $ts_in ) return;

    $nights = (int) max( 1, ( $ts_out - $ts_in ) / DAY_IN_SECONDS );

    // Модель
    $model = (string) ( get_post_meta( $room_type_id, '_bsbt_business_model', true ) ?: 'model_a' );

    // Константы
    $fee_rate = defined( 'BSBT_FEE' ) ? (float) BSBT_FEE : 0.0;
    $vat_rate = defined( 'BSBT_VAT_ON_FEE' ) ? (float) BSBT_VAT_ON_FEE : 0.0;

    // Payout entity
    $manager_user_id = (int) get_post_meta( $room_type_id, 'bsbt_owner_id', true );

    if ( $model === 'model_b' && $manager_user_id > 0 ) {
        $payout_type   = 'user';
        $payout_entity = $manager_user_id;
    } else {
        $payout_type   = 'apartment';
        $payout_entity = 0;
    }

    // =========================
    // SOURCE OF TRUTH: guest_total из MPHB (для A и B)
    // =========================
    $guest_total = 0.0;
    if ( method_exists( $booking, 'getTotalPrice' ) ) {
        $guest_total = (float) $booking->getTotalPrice();
    } else {
        $guest_total = (float) get_post_meta( $booking_id, 'mphb_booking_total_price', true );
    }

    $guest_total = round( max( 0.0, $guest_total ), 2 );
    if ( $guest_total <= 0 ) return;

    // =========================
    // Owner price (PPN) нужен как закупка для Model A
    // Для Model B может быть пустым (не блокируем snapshot!)
    // =========================
    $ppn = (float) get_post_meta( $room_type_id, 'owner_price_per_night', true );
    if ( ! $ppn && function_exists( 'get_field' ) ) {
        $ppn = (float) get_field( 'owner_price_per_night', $room_type_id );
    }
    $ppn = round( max( 0.0, $ppn ), 2 );

    // =========================
    // Итоговые поля
    // =========================
    $owner_payout     = 0.0;
    $commission_net   = 0.0;
    $commission_vat   = 0.0;
    $commission_gross = 0.0;
    $margin_total     = 0.0;

    // =========================
    // MODEL A (resell/operator)
    // guest_total = MPHB
    // owner_payout = owner_price_per_night * nights
    // margin_total = guest_total - owner_payout
    // =========================
    if ( $model === 'model_a' ) {

        // Для Model A закупка обязательна
        if ( $ppn <= 0 ) return;

        $owner_payout = round( $ppn * $nights, 2 );

        // RU: маржа платформы — разница между оплатой гостя и закупкой.
        $margin_total = round( $guest_total - $owner_payout, 2 );
        if ( $margin_total < 0 ) $margin_total = 0.0;

        $commission_net   = 0.0;
        $commission_vat   = 0.0;
        $commission_gross = 0.0;
    }

    // =========================
    // MODEL B (marketplace commission-inside)
    // guest_total = MPHB
    // commission_net = guest_total * BSBT_FEE
    // commission_vat = commission_net * BSBT_VAT_ON_FEE
    // owner_payout = guest_total - commission_net
    // =========================
    if ( $model === 'model_b' ) {

        $commission_net   = round( $guest_total * max( 0.0, $fee_rate ), 2 );
        $commission_vat   = round( $commission_net * max( 0.0, $vat_rate ), 2 );
        $commission_gross = round( $commission_net + $commission_vat, 2 );
        $owner_payout     = round( $guest_total - $commission_net, 2 );

        // RU: В Model B маржа как “разница” не используется — тут комиссия.
        $margin_total = 0.0;
    }

    $snapshot = [
        '_bsbt_snapshot_room_type_id'    => $room_type_id,
        '_bsbt_snapshot_ppn'             => $ppn,
        '_bsbt_snapshot_nights'          => $nights,
        '_bsbt_snapshot_model'           => $model,

        '_bsbt_snapshot_manager_user_id' => $manager_user_id,
        '_bsbt_snapshot_payout_type'     => $payout_type,
        '_bsbt_snapshot_payout_entity'   => $payout_entity,

        '_bsbt_snapshot_guest_total'     => $guest_total,
        '_bsbt_snapshot_owner_payout'    => $owner_payout,

        // RU: Для Model A = 0. Для Model B = комиссия платформы.
        '_bsbt_snapshot_fee_rate'        => $fee_rate,
        '_bsbt_snapshot_fee_vat_rate'    => $vat_rate,
        '_bsbt_snapshot_fee_net_total'   => $commission_net,
        '_bsbt_snapshot_fee_vat_total'   => $commission_vat,
        '_bsbt_snapshot_fee_gross_total' => $commission_gross,

        // RU: Замороженная маржа для Model A.
        '_bsbt_snapshot_margin_total'    => $margin_total,

        '_bsbt_snapshot_locked_at'       => current_time( 'mysql' ),
        '_bsbt_snapshot_version'         => '3.2.0',
    ];

    foreach ( $snapshot as $key => $val ) {
        update_post_meta( $booking_id, $key, $val );
    }

    // Snapshot не управляет owner decision
}

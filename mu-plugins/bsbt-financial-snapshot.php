<?php
/**
 * Plugin Name: BSBT – Financial Snapshot (Enterprise Stable)
 * Version: 2.8.0
 * Description: Замораживает финансовые показатели (Snapshot) при подтверждении брони.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Основной триггер на смену статуса через ядро WordPress
 */
add_action('transition_post_status', 'bsbt_snapshot_on_confirmed', 10, 3);

/**
 * RU: Резолвер комиссии для бизнес-моделей (A/B/C).
 */
function bsbt_snapshot_resolve_commission_mode( string $business_model ): string {
    $business_model = trim( strtolower( $business_model ) );

    if ( $business_model === 'model_b' ) {
        return 'over';
    }

    if ( $business_model === 'model_c' ) {
        return 'included';
    }

    return 'margin'; // Model A
}

/**
 * RU: Единый калькулятор snapshot-значений.
 * Без глубокого рефакторинга, сохраняет совместимость A/B.
 */
function bsbt_snapshot_calculate_totals(
    float $base_owner_amount,
    float $fee_rate,
    float $vat_rate,
    string $commission_mode
): array {

    $base_owner_amount = round( max( 0.0, $base_owner_amount ), 2 );
    $fee_rate          = max( 0.0, $fee_rate );
    $vat_rate          = max( 0.0, $vat_rate );

    // =========================
    // Model C (included)
    // =========================
    if ( $commission_mode === 'included' ) {

        $guest_total      = $base_owner_amount;
        $commission_gross = round( $guest_total * $fee_rate, 2 );

        $commission_net = ( $vat_rate > 0 )
            ? round( $commission_gross / ( 1 + $vat_rate ), 2 )
            : $commission_gross;

        $commission_vat = round( $commission_gross - $commission_net, 2 );

        $owner_payout = round( $guest_total - $commission_gross, 2 );

        return [
            'guest_total'      => $guest_total,
            'owner_payout'     => $owner_payout,
            'commission_net'   => $commission_net,
            'commission_vat'   => $commission_vat,
            'commission_gross' => $commission_gross,
            'vat_mode'         => 'commission_only',
        ];
    }

    // =========================
    // Legacy A / B
    // =========================
    $owner_payout = $base_owner_amount;

    $fee_net   = round( $owner_payout * $fee_rate, 2 );
    $fee_vat   = round( $fee_net * $vat_rate, 2 );
    $fee_gross = round( $fee_net + $fee_vat, 2 );

    if ( $commission_mode === 'over' ) {
        $guest_total = round( $owner_payout + $fee_gross, 2 );
        $vat_mode    = 'commission_only';
    } else {
        $guest_total = $owner_payout;
        $vat_mode    = 'included_total';
    }

    return [
        'guest_total'      => $guest_total,
        'owner_payout'     => $owner_payout,
        'commission_net'   => $fee_net,
        'commission_vat'   => $fee_vat,
        'commission_gross' => $fee_gross,
        'vat_mode'         => $vat_mode,
    ];
}

/**
 * Основная функция фиксации snapshot
 */
function bsbt_snapshot_on_confirmed($new_status, $old_status, $post) {

    // Проверка типа
    if ( ! $post || $post->post_type !== 'mphb_booking' ) return;

    // Переход в confirmed
    $is_confirmed  = ( $new_status === 'confirmed' || $new_status === 'mphb-confirmed' );
    $was_confirmed = ( $old_status === 'confirmed' || $old_status === 'mphb-confirmed' );

    if ( ! $is_confirmed || $was_confirmed ) return;

    $booking_id = $post->ID;

    // Idempotency lock
    if ( get_post_meta($booking_id, '_bsbt_snapshot_locked_at', true) ) return;

    if ( ! function_exists('MPHB') ) return;

    $booking = MPHB()->getBookingRepository()->findById($booking_id);
    if ( ! $booking ) return;

    $rooms = $booking->getReservedRooms();
    if ( empty($rooms) ) return;

    $room_type_id = $rooms[0]->getRoomTypeId();

    // Даты
    $check_in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
    $check_out = get_post_meta($booking_id, 'mphb_check_out_date', true);

    if ( ! $check_in || ! $check_out ) return;

    $ts_in  = strtotime($check_in);
    $ts_out = strtotime($check_out);

    if ( ! $ts_in || ! $ts_out || $ts_out <= $ts_in ) return;

    $nights = max(1, ($ts_out - $ts_in) / DAY_IN_SECONDS);

    // Цена владельца (PPN)
    $ppn = (float) get_post_meta($room_type_id, 'owner_price_per_night', true);

    if ( ! $ppn && function_exists('get_field') ) {
        $ppn = (float) get_field('owner_price_per_night', $room_type_id);
    }

    if ( $ppn <= 0 ) return;

    // Константы
    $fee_rate = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.0;
    $vat_rate = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.0;

    $model = get_post_meta($room_type_id, '_bsbt_business_model', true) ?: 'model_a';
    $commission_mode = bsbt_snapshot_resolve_commission_mode($model);

    // Payout entity
    $manager_user_id = (int) get_post_meta($room_type_id, 'bsbt_owner_id', true);

    if ( $model === 'model_b' && $manager_user_id > 0 ) {
        $payout_type   = 'user';
        $payout_entity = $manager_user_id;
    } else {
        $payout_type   = 'apartment';
        $payout_entity = 0;
    }

    // База расчёта
    $owner_base = round($ppn * $nights, 2);

    $totals = bsbt_snapshot_calculate_totals(
        $owner_base,
        $fee_rate,
        $vat_rate,
        $commission_mode
    );

    $snapshot = [
        '_bsbt_snapshot_room_type_id'    => $room_type_id,
        '_bsbt_snapshot_ppn'             => $ppn,
        '_bsbt_snapshot_nights'          => $nights,
        '_bsbt_snapshot_model'           => $model,

        '_bsbt_snapshot_manager_user_id' => $manager_user_id,
        '_bsbt_snapshot_payout_type'     => $payout_type,
        '_bsbt_snapshot_payout_entity'   => $payout_entity,

        '_bsbt_snapshot_owner_payout'    => $totals['owner_payout'],
        '_bsbt_snapshot_fee_rate'        => $fee_rate,
        '_bsbt_snapshot_fee_vat_rate'    => $vat_rate,
        '_bsbt_snapshot_fee_net_total'   => $totals['commission_net'],
        '_bsbt_snapshot_fee_vat_total'   => $totals['commission_vat'],
        '_bsbt_snapshot_fee_gross_total' => $totals['commission_gross'],

        '_bsbt_snapshot_business_model'            => $model,
        '_bsbt_snapshot_commission_mode'           => $commission_mode,
        '_bsbt_snapshot_guest_total'               => $totals['guest_total'],
        '_bsbt_snapshot_platform_commission_net'   => $totals['commission_net'],
        '_bsbt_snapshot_platform_commission_gross' => $totals['commission_gross'],
        '_bsbt_snapshot_platform_vat_amount'       => $totals['commission_vat'],
        '_bsbt_snapshot_vat_mode'                  => $totals['vat_mode'],

        '_bsbt_snapshot_locked_at' => current_time('mysql'),
        '_bsbt_snapshot_version'   => '2.8.0',
    ];

    foreach ( $snapshot as $key => $val ) {
        update_post_meta($booking_id, $key, $val);
    }

    // Snapshot не управляет owner decision
}

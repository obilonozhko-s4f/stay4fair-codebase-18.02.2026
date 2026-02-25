<?php
/**
 * Plugin Name: BSBT – Financial Snapshot (Enterprise Stable)
 * Version: 3.1.0
 * Description: Замораживает финансовые показатели (Snapshot) при подтверждении брони.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Основной триггер на смену статуса
 */
add_action('transition_post_status', 'bsbt_snapshot_on_confirmed', 10, 3);

function bsbt_snapshot_on_confirmed($new_status, $old_status, $post) {

    if ( ! $post || $post->post_type !== 'mphb_booking' ) return;

    $is_confirmed  = in_array($new_status, ['confirmed','mphb-confirmed'], true);
    $was_confirmed = in_array($old_status, ['confirmed','mphb-confirmed'], true);

    if ( ! $is_confirmed || $was_confirmed ) return;

    $booking_id = $post->ID;

    // Idempotency
    if ( get_post_meta($booking_id, '_bsbt_snapshot_locked_at', true) ) return;
    if ( ! function_exists('MPHB') ) return;

    $booking = MPHB()->getBookingRepository()->findById($booking_id);
    if ( ! $booking ) return;

    $rooms = $booking->getReservedRooms();
    if ( empty($rooms) ) return;

    $room_type_id = $rooms[0]->getRoomTypeId();

    $check_in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
    $check_out = get_post_meta($booking_id, 'mphb_check_out_date', true);

    if ( ! $check_in || ! $check_out ) return;

    $ts_in  = strtotime($check_in);
    $ts_out = strtotime($check_out);
    if ( ! $ts_in || ! $ts_out || $ts_out <= $ts_in ) return;

    $nights = max(1, ($ts_out - $ts_in) / DAY_IN_SECONDS);

    $model = get_post_meta($room_type_id, '_bsbt_business_model', true) ?: 'model_a';

    $manager_user_id = (int) get_post_meta($room_type_id, 'bsbt_owner_id', true);

    $payout_type   = ($model === 'model_b' && $manager_user_id > 0) ? 'user' : 'apartment';
    $payout_entity = ($model === 'model_b' && $manager_user_id > 0) ? $manager_user_id : 0;

    $fee_rate = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.0;
    $vat_rate = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.0;

    $guest_total      = 0.0;
    $owner_payout     = 0.0;
    $commission_net   = 0.0;
    $commission_vat   = 0.0;
    $commission_gross = 0.0;
    $ppn              = 0.0;

    // =========================
    // MODEL A
    // =========================
    if ($model === 'model_a') {

        $ppn = (float) get_post_meta($room_type_id, 'owner_price_per_night', true);
        if (!$ppn && function_exists('get_field')) {
            $ppn = (float) get_field('owner_price_per_night', $room_type_id);
        }

        if ($ppn <= 0) return;

        $owner_payout = round($ppn * $nights, 2);
        $guest_total  = $owner_payout;

    }

    // =========================
    // MODEL B
    // =========================
    if ($model === 'model_b') {

        if (method_exists($booking, 'getTotalPrice')) {
            $guest_total = (float) $booking->getTotalPrice();
        } else {
            $guest_total = (float) get_post_meta($booking_id, 'mphb_booking_total_price', true);
        }

        $guest_total = round(max(0.0, $guest_total), 2);
        if ($guest_total <= 0) return;

        $commission_net   = round($guest_total * max(0.0, $fee_rate), 2);
        $commission_vat   = round($commission_net * max(0.0, $vat_rate), 2);
        $commission_gross = round($commission_net + $commission_vat, 2);
        $owner_payout     = round($guest_total - $commission_net, 2);
    }

    $snapshot = [
        '_bsbt_snapshot_room_type_id'    => $room_type_id,
        '_bsbt_snapshot_ppn'             => $ppn,
        '_bsbt_snapshot_nights'          => $nights,
        '_bsbt_snapshot_model'           => $model,
        '_bsbt_snapshot_manager_user_id' => $manager_user_id,
        '_bsbt_snapshot_payout_type'     => $payout_type,
        '_bsbt_snapshot_payout_entity'   => $payout_entity,
        '_bsbt_snapshot_owner_payout'    => $owner_payout,
        '_bsbt_snapshot_fee_rate'        => $fee_rate,
        '_bsbt_snapshot_fee_vat_rate'    => $vat_rate,
        '_bsbt_snapshot_fee_net_total'   => $commission_net,
        '_bsbt_snapshot_fee_vat_total'   => $commission_vat,
        '_bsbt_snapshot_fee_gross_total' => $commission_gross,
        '_bsbt_snapshot_guest_total'     => $guest_total,
        '_bsbt_snapshot_locked_at'       => current_time('mysql'),
        '_bsbt_snapshot_version'         => '3.1.0',
    ];

    foreach ($snapshot as $key => $val) {
        update_post_meta($booking_id, $key, $val);
    }
}

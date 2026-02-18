<?php
/**
 * Plugin Name: BSBT – Booking Consent Logs
 * Description: Stores legal consent (Privacy / Terms / Cancellation) in booking meta on checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Version of legal text.
 * Меняй, если меняешь тексты условий.
 */
define( 'BSBT_CONSENT_VERSION', '2026-02-01' );

/**
 * Hook after booking is created (MotoPress Hotel Booking)
 */
add_action( 'mphb_booking_created', function ( $booking_id, $booking ) {

    if ( ! $booking_id ) {
        return;
    }

    // Проверяем наличие нашего чекбокса
    $accepted = isset( $_POST['mphb_bsbt_terms'] ) && $_POST['mphb_bsbt_terms'] == '1';

    if ( ! $accepted ) {
        return;
    }

    // Сохраняем consent-логи
    update_post_meta( $booking_id, '_bsbt_consent_accepted', 'yes' );
    update_post_meta( $booking_id, '_bsbt_consent_version', BSBT_CONSENT_VERSION );
    update_post_meta( $booking_id, '_bsbt_consent_time', current_time( 'mysql' ) );

    // IP (с учётом прокси)
    $ip = '';
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ip = sanitize_text_field( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
    }

    if ( $ip ) {
        update_post_meta( $booking_id, '_bsbt_consent_ip', $ip );
    }

    // User Agent
    if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
        update_post_meta(
            $booking_id,
            '_bsbt_consent_user_agent',
            sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] )
        );
    }

}, 10, 2 );

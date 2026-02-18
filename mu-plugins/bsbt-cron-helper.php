<?php
/**
 * Plugin Name: BSBT – Owner Portal Cron Helper
 * Description: Автоматическая проверка бронирований каждые 24 часа.
 * Version: 1.1
 * Author: BSBT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Привязываем функцию из нашего CORE класса к событию
add_action( 'bsbt_check_24h_bookings_cron', ['BSBT_Owner_Decision_Core', 'process_auto_expire'] );

// 2. Гарантируем, что задача запланирована (для MU плагинов это нужно делать так)
if ( ! wp_next_scheduled( 'bsbt_check_24h_bookings_cron' ) ) {
    wp_schedule_event( time(), 'hourly', 'bsbt_check_24h_bookings_cron' );
}
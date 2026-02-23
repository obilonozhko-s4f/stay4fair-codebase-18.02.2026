<?php
/**
 * Plugin Name: BSBT – Owner Portal Cron Helper
 * Description: Автоматическая проверка бронирований каждые 24 часа.
 * Version: 1.2
 * Author: BSBT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * =========================================================
 * NOTE (2026): Cron dedup / single source of truth
 * =========================================================
 * RU: Процесс auto-expire должен запускаться только одним cron-hook,
 * чтобы исключить дублирование и гонки.
 *
 * Канонический hook теперь: bsbt_owner_cron_auto_expire
 * (плагин bsbt-owner-portal/includes/owner-cron.php).
 *
 * Этот MU-плагин больше НЕ планирует и НЕ выполняет bsbt_check_24h_bookings_cron.
 * Также пытаемся снять старые запланированные события, если они остались.
 */

// Снимаем старые запланированные события (если были ранее)
add_action('init', function () {

	$hook = 'bsbt_check_24h_bookings_cron';

	// Если событие было запланировано ранее — удаляем все ближайшие инстансы
	$ts = wp_next_scheduled($hook);
	while ($ts) {
		wp_unschedule_event($ts, $hook);
		$ts = wp_next_scheduled($hook);
	}

}, 1);

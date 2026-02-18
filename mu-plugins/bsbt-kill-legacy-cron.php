<?php
/**
 * Plugin Name: BSBT – Kill legacy cron hook bs_hb_wc_make_order (SAFE + QUIET)
 * Description: One-time cleanup of scheduled events for bs_hb_wc_make_order + safe dummy handler. Quiet in logs.
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Dummy handler: if something still calls do_action('bs_hb_wc_make_order', ...),
 * we do nothing (prevents fatal/side effects).
 */
add_action( 'bs_hb_wc_make_order', function( $booking_id = null ) {
	// intentionally silent
}, 1, 1 );

/**
 * One-time cleanup: remove scheduled cron events for the hook.
 * Runs only for admins and only once (stored in option).
 */
add_action( 'admin_init', function() {

	if ( ! current_user_can( 'manage_options' ) ) return;

	$flag = 'bsbt_killed_legacy_cron_bs_hb_wc_make_order_v1';
	if ( get_option( $flag ) ) return;

	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
		update_option( $flag, 1, false );
		return;
	}

	$hook = 'bs_hb_wc_make_order';

	$did = 0;
	while ( $ts = wp_next_scheduled( $hook ) ) {
		wp_unschedule_event( $ts, $hook );
		$did++;
	}

	// Mark done (even if nothing was scheduled)
	update_option( $flag, time(), false );

	// Optional: uncomment for one-time log
	// error_log('[BSBT_CRON_CLEANUP] One-time cleanup done for hook: ' . $hook . ' (removed: ' . $did . ')');

}, 20 );

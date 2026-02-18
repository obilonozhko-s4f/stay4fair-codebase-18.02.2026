<?php
/**
 * Plugin Name: BSBT – MPHB ↔ WooCommerce Debug
 * Description: Логирует связку MotoPress Hotel Booking с WooCommerce: статусы броней и создание/обновление заказов.
 * Author: BS Business Travelling / Stay4Fair.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'BSBT_MPHB_WC_LOG' ) ) {
	define( 'BSBT_MPHB_WC_LOG', true );
}

/**
 * Универсальный логгер в debug.log
 */
function bsbt_mphb_wc_log( $message, $context = array() ) {
	if ( ! BSBT_MPHB_WC_LOG || ! function_exists( 'error_log' ) ) {
		return;
	}
	if ( ! is_array( $context ) ) {
		$context = array( 'raw' => $context );
	}

	$line = '[BSBT_MPHB_WC] ' . $message . ' | ' . wp_json_encode( $context );
	error_log( $line );
}

/**
 * Хелпер: вытащить все meta-ключи заказа, связанные с MPHB
 */
function bsbt_mphb_wc_extract_mphb_meta( WC_Order $order ) {
	$data  = array();
	$metas = $order->get_meta_data();

	foreach ( $metas as $meta ) {
		$key = (string) $meta->key;

		// Логируем всё, где явно есть "mphb" или наш bsbt-ключ
		if (
			strpos( $key, 'mphb' ) !== false
			|| strpos( $key, 'bsbt' ) !== false
		) {
			$data[ $key ] = $meta->value;
		}
	}

	return $data;
}

/**
 * Хелпер: собрать базовую информацию по брони (booking_id)
 */
function bsbt_mphb_wc_booking_snapshot( $booking_id ) {
	$booking_id = (int) $booking_id;

	$flow       = get_post_meta( $booking_id, '_bsbt_flow_mode', true );
	$channel    = get_post_meta( $booking_id, '_bsbt_channel', true );
	$total      = get_post_meta( $booking_id, 'mphb_total_price', true );
	$balance    = get_post_meta( $booking_id, 'mphb_balance_due', true );
	$paid       = get_post_meta( $booking_id, 'mphb_paid_amount', true );
	$email      = get_post_meta( $booking_id, 'mphb_email', true );
	$room_types = array();

	if ( function_exists( 'MPHB' ) ) {
		try {
			$booking = MPHB()->getBookingRepository()->findById( $booking_id );
			if ( $booking ) {
				$rooms = $booking->getReservedRooms();
				if ( ! empty( $rooms ) ) {
					foreach ( $rooms as $room ) {
						if ( method_exists( $room, 'getRoomTypeId' ) ) {
							$room_types[] = (int) $room->getRoomTypeId();
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			// ignore
		}
	}

	return array(
		'booking_id'    => $booking_id,
		'flow'          => $flow,
		'channel'       => $channel,
		'total_price'   => (string) $total,
		'paid_amount'   => (string) $paid,
		'balance_due'   => (string) $balance,
		'guest_email'   => (string) $email,
		'room_type_ids' => $room_types,
	);
}

/* ============================================================
 * 1) LOG MPHB STATUS CHANGES (SAFE FOR ALL CALL SIGNATURES)
 * ============================================================ */

add_action(
	'mphb_booking_status_changed',
	function ( $arg1, $arg2 = null, $arg3 = null ) {

		$booking_id = 0;
		$new_status = '';
		$old_status = '';

		// CASE A: object + new_status
		if ( class_exists('\MPHB\Entities\Booking') && $arg1 instanceof \MPHB\Entities\Booking ) {
			$booking_id = (int) $arg1->getId();
			$new_status = (string) $arg2;
			$old_status = ''; // old status is not provided in this MPHB call
		}
		// CASE B: classic (id, new, old)
		else {
			$booking_id = (int) $arg1;
			$new_status = (string) $arg2;
			$old_status = (string) $arg3;
		}

		if ( $booking_id <= 0 ) {
			return;
		}

		$snapshot = bsbt_mphb_wc_booking_snapshot( $booking_id );
		$snapshot['new_status'] = $new_status;
		$snapshot['old_status'] = $old_status;

		bsbt_mphb_wc_log( 'MPHB booking status changed', $snapshot );
	},
	10,
	3
);

/* ============================================================
 * 2) LOG WOOCOMMERCE NEW ORDER
 * ============================================================ */

add_action(
	'woocommerce_new_order',
	function ( $order_id ) {

		if ( ! function_exists( 'wc_get_order' ) ) return;

		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		bsbt_mphb_wc_log(
			'WooCommerce new order created',
			array(
				'order_id'      => $order_id,
				'status'        => $order->get_status(),
				'total'         => $order->get_total(),
				'billing_email' => $order->get_billing_email(),
				'mphb_meta'     => bsbt_mphb_wc_extract_mphb_meta( $order ),
			)
		);
	},
	20,
	1
);

/* ============================================================
 * 3) LOG CHECKOUT META UPDATE
 * ============================================================ */

add_action(
	'woocommerce_checkout_update_order_meta',
	function ( $order_id, $data ) {

		if ( ! function_exists( 'wc_get_order' ) ) return;

		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		bsbt_mphb_wc_log(
			'Woo checkout update order meta',
			array(
				'order_id'      => $order_id,
				'status'        => $order->get_status(),
				'total'         => $order->get_total(),
				'billing_email' => $order->get_billing_email(),
				'mphb_meta'     => bsbt_mphb_wc_extract_mphb_meta( $order ),
			)
		);
	},
	20,
	2
);

/* ============================================================
 * 4) LOG STORE API CHECKOUT (Blocks)
 * ============================================================ */

add_action(
	'woocommerce_store_api_checkout_order_processed',
	function ( $order ) {

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order instanceof WC_Order ) return;

		bsbt_mphb_wc_log(
			'Woo Store API checkout order processed',
			array(
				'order_id'      => $order->get_id(),
				'status'        => $order->get_status(),
				'total'         => $order->get_total(),
				'billing_email' => $order->get_billing_email(),
				'mphb_meta'     => bsbt_mphb_wc_extract_mphb_meta( $order ),
			)
		);
	},
	20,
	1
);

/* ============================================================
 * 5) LOG ORDER STATUS CHANGES
 * ============================================================ */

add_action(
	'woocommerce_order_status_changed',
	function ( $order_id, $old_status, $new_status, $order ) {

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) return;
		}

		bsbt_mphb_wc_log(
			'Woo order status changed',
			array(
				'order_id'      => $order_id,
				'old_status'    => $old_status,
				'new_status'    => $new_status,
				'total'         => $order->get_total(),
				'billing_email' => $order->get_billing_email(),
				'mphb_meta'     => bsbt_mphb_wc_extract_mphb_meta( $order ),
			)
		);
	},
	20,
	4
);

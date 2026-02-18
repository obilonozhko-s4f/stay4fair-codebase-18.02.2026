<?php
/**
 * Plugin Name: BSBT – Attach Voucher to Woo "Processing Order" Email
 * Description: Attaches voucher PDF to WooCommerce customer_processing_order email (AUTO flow only).
 */

if ( ! defined('ABSPATH') ) exit;

add_filter(
    'woocommerce_email_attachments',
    function ( $attachments, $email_id, $order ) {

        // 1️⃣ Только нужное письмо
        if ( $email_id !== 'customer_processing_order' ) {
            return $attachments;
        }

        if ( ! $order || ! is_object($order) ) {
            return $attachments;
        }

        $order_id = $order->get_id();
        if ( ! $order_id ) {
            return $attachments;
        }

        /**
         * 2️⃣ Находим MPHB booking_id по заказу
         * (ты уже это делал — используем проверенную логику)
         */
        $booking_id = 0;

        foreach ( $order->get_items() as $item ) {
            $payment_id = (int) $item->get_meta('_mphb_payment_id', true);
            if ( $payment_id > 0 ) {
                $booking_id = (int) get_post_meta($payment_id, '_mphb_booking_id', true);
                if ( $booking_id > 0 ) {
                    break;
                }
            }
        }

        if ( $booking_id <= 0 ) {
            return $attachments;
        }

        // 3️⃣ AUTO FLOW ONLY
        if ( defined('BSBT_FLOW_META') ) {
            $flow = get_post_meta( $booking_id, BSBT_FLOW_META, true );
            if ( $flow !== 'auto' ) {
                return $attachments;
            }
        }

        // 4️⃣ Генерация PDF ваучера (ИСПОЛЬЗУЕМ СУЩЕСТВУЮЩУЮ ЛОГИКУ)
        if ( ! function_exists('bsbt_generate_voucher_pdf_for_booking') ) {
            return $attachments;
        }

        $pdf = bsbt_generate_voucher_pdf_for_booking( $booking_id );
        if ( ! $pdf || ! is_file($pdf) ) {
            return $attachments;
        }

        // 5️⃣ Прикрепляем (без дублей)
        if ( ! in_array($pdf, $attachments, true) ) {
            $attachments[] = $pdf;
        }

        // 6️⃣ ЛОГ
        if ( function_exists('bs_bt_log_voucher_send') ) {
            bs_bt_log_voucher_send( $booking_id, array(
                'to'      => 'Woo customer',
                'subject' => 'Customer processing order',
                'source'  => 'woo:processing:attach',
                'status'  => 'ok',
                'error'   => ''
            ));
        }

        return $attachments;

    },
    10,
    3
);

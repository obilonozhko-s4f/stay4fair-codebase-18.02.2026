<?php
/**
 * Core логика принятия решений владельцем.
 * Core logic for owner decision making.
 *
 * Version V10.23.7 - Safe Production FINAL
 *
 * SCENARIOS / СЦЕНАРИИ:
 * 1) APPROVE  -> Woo capture -> Snapshot payout -> MPHB sync via workflow (не трогаем статус MPHB).
 * 2) DECLINE  -> Woo cancel/refund -> MPHB cancelled (calendar unlock).
 * 3) EXPIRE   -> Woo cancel/refund -> MPHB pending (calendar locked for admin investigation).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BSBT_Owner_Decision_Core {

    /* =========================
     * META KEYS / META
     * ========================= */

    const META_DECISION       = '_bsbt_owner_decision';        // approved|declined|expired
    const META_DECISION_TIME  = '_bsbt_owner_decision_time';   // mysql datetime
    const META_BSBT_REF       = '_bsbt_booking_id';            // order meta: welded booking id
    const META_PAYMENT_ISSUE  = '_bsbt_payment_issue';         // 1
    const META_DECISION_LOCK  = '_bsbt_owner_decision_lock';   // uuid token

    /* =========================================================
     * APPROVE FLOW (Safe Capture + Order Welding)
     * ========================================================= */

    /**
     * Одобрение брони владельцем (Safe Capture Flow).
     * Owner approves -> capture -> finalize snapshot.
     */
    public static function approve_and_send_payment( int $booking_id ): array {

        if ( $booking_id <= 0 ) {
            return ['ok'=>false,'message'=>'Invalid ID'];
        }

        // 1) Already processed?
        $existing = (string) get_post_meta( $booking_id, self::META_DECISION, true );
        if ( $existing !== '' ) {
            return ['ok'=>false,'message'=>'Already processed: ' . $existing];
        }

        // 2) Atomic lock (double click / parallel requests)
        $lock_token = wp_generate_uuid4();
        if ( ! add_post_meta( $booking_id, self::META_DECISION_LOCK, $lock_token, true ) ) {
            return ['ok'=>false,'message'=>'Already locked / processing'];
        }

        $release_lock = function() use ( $booking_id, $lock_token ) {
            if ( (string) get_post_meta( $booking_id, self::META_DECISION_LOCK, true ) === $lock_token ) {
                delete_post_meta( $booking_id, self::META_DECISION_LOCK );
            }
        };

        try {

            // 3) Draft payout snapshot (snapshot-first logic)
$existing_snapshot = get_post_meta( $booking_id, '_bsbt_snapshot_owner_payout', true );

if ( $existing_snapshot !== '' ) {
    $payout = (float) $existing_snapshot;
} else {
    $payout = self::calculate_payout( $booking_id );
}

update_post_meta( $booking_id, '_bsbt_snapshot_owner_payout_draft', $payout );


            // 4) Find Woo order
            $order = self::find_order_for_booking( $booking_id );
            if ( ! ( $order instanceof WC_Order ) ) {
                update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
                error_log('[BSBT APPROVE] Order not found for booking #' . $booking_id);
                return ['ok'=>false,'message'=>'Order not found'];
            }

            $order_id = (int) $order->get_id();

            // ✅ WELD: hard bind booking id to this order for future fast lookups
            // RU: Пишем связь в мету заказа через Woo API (HPOS-safe).
            // EN: Write meta via Woo API for compatibility.
            $order->update_meta_data( self::META_BSBT_REF, $booking_id );
            $order->save();

            // 5) Capture / Complete flow
            if ( ! $order->is_paid() ) {

                $gateway = function_exists('wc_get_payment_gateway_by_order')
                    ? wc_get_payment_gateway_by_order( $order )
                    : null;

                // Try gateway capture (Stripe authorize -> capture)
                if ( $gateway && method_exists( $gateway, 'capture_payment' ) ) {
                    $order->add_order_note('BSBT: Attempting capture_payment()...');
                    $gateway->capture_payment( $order_id );
                }

                // Reload after capture
                $order = wc_get_order( $order_id );

                // Soft fallback ONLY if still not paid
                if ( $order && ! $order->is_paid() ) {
                    $order->add_order_note('BSBT: Soft fallback payment_complete() (gateway did not update status).');
                    $order->payment_complete();
                }
            }

            // Final verify
            $order   = wc_get_order( $order_id );
            $paid_ok = ( $order && $order->is_paid() );

            if ( ! $paid_ok ) {
                update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
                return ['ok'=>false,'message'=>'Payment not captured'];
            }

            // 6) Finalize decision + snapshot
            delete_post_meta( $booking_id, self::META_PAYMENT_ISSUE );
            update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

            // RU: Решение записываем только после успешной оплаты.
            // EN: Decision is saved only after successful payment.
            add_post_meta( $booking_id, self::META_DECISION, 'approved', true );

            update_post_meta( $booking_id, '_bsbt_snapshot_owner_payout', $payout );
            delete_post_meta( $booking_id, '_bsbt_snapshot_owner_payout_draft' );

            error_log('[BSBT SUCCESS] Booking #' . $booking_id . ' approved | Order #' . $order_id);

            return ['ok'=>true,'paid'=>true,'order_id'=>$order_id,'message'=>'Approved & Captured'];

        } catch ( \Throwable $e ) {

            error_log('[BSBT APPROVE ERROR] #' . $booking_id . ' | ' . $e->getMessage());
            update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );

            return ['ok'=>false,'message'=>'Capture error'];

        } finally {
            $release_lock();
        }
    }

    /* =========================================================
     * DECLINE FLOW (Atomic + Cancel calendar)
     * ========================================================= */

    /**
     * Owner manual decline within 24h:
     * Woo: cancel/void or refund
     * MPHB: cancelled (calendar unlock)
     */
    public static function decline_booking( int $booking_id ): array {

        if ( $booking_id <= 0 ) {
            return ['ok'=>false,'message'=>'Invalid ID'];
        }

        // If already processed -> do nothing
        $existing = (string) get_post_meta( $booking_id, self::META_DECISION, true );
        if ( $existing !== '' ) {
            return ['ok'=>false,'message'=>'Already processed: ' . $existing];
        }

        // 🔒 Atomic decision lock (prevents approve/decline race)
        $locked = add_post_meta( $booking_id, self::META_DECISION, 'declined', true );
        if ( ! $locked ) {
            return ['ok'=>false,'message'=>'Already processed/locked'];
        }

        update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

        // Woo: refund or cancel
        $order = self::find_order_for_booking( $booking_id );

        if ( $order instanceof WC_Order ) {
            try {

                // Weld for future (optional)
                $order->update_meta_data( self::META_BSBT_REF, $booking_id );
                $order->save();

                if ( $order->is_paid() ) {

                    if ( function_exists('wc_create_refund') ) {
                        wc_create_refund([
                            'amount'         => $order->get_total(),
                            'reason'         => 'Owner declined',
                            'order_id'       => $order->get_id(),
                            'refund_payment' => true,
                            'restock_items'  => false,
                        ]);
                        $order->add_order_note('BSBT: Refund executed (Owner declined).');
                    } else {
                        $order->add_order_note('BSBT: Owner declined (paid), but wc_create_refund() not available.');
                    }

                } else {
                    // Cancel releases Stripe authorization hold
                    $order->update_status( 'cancelled', 'BSBT: Owner declined – hold released.' );
                }

            } catch ( \Throwable $e ) {
                error_log('[BSBT DECLINE ERROR] #' . $booking_id . ' | ' . $e->getMessage());
            }
        } else {
            error_log('[BSBT DECLINE] Booking #' . $booking_id . ' | Woo order not found.');
        }

        // ✅ Unlock MPHB calendar
        self::set_mphb_status_safe( $booking_id, 'cancelled' );

        return ['ok'=>true,'message'=>'Declined'];
    }

    /* =========================================================
     * AUTO EXPIRE (CRON)
     * ========================================================= */

    /**
     * Auto-expire after 24h with no owner decision:
     * decision=expired (atomic)
     * Woo cancel/refund
     * MPHB pending (keep locked)
     */
    public static function process_auto_expire(): void {

        if ( ! function_exists('MPHB') ) {
            return;
        }

        $q = new WP_Query([
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => self::META_DECISION, 'compare' => 'NOT EXISTS'],
            ],
            'date_query'     => [
                ['before' => '24 hours ago'],
            ],
        ]);

        if ( ! $q->have_posts() ) {
            return;
        }

        foreach ( $q->posts as $booking_id ) {

            $booking_id = (int) $booking_id;
            if ( $booking_id <= 0 ) continue;

            // Atomic mark as expired
            $locked = add_post_meta( $booking_id, self::META_DECISION, 'expired', true );
            if ( ! $locked ) continue;

            update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

            $order = self::find_order_for_booking( $booking_id );

            if ( $order instanceof WC_Order ) {
                try {

                    // Weld (optional)
                    $order->update_meta_data( self::META_BSBT_REF, $booking_id );
                    $order->save();

                    if ( $order->is_paid() ) {
                        if ( function_exists('wc_create_refund') ) {
                            wc_create_refund([
                                'amount'         => $order->get_total(),
                                'reason'         => 'Auto-expired (no owner response)',
                                'order_id'       => $order->get_id(),
                                'refund_payment' => true,
                                'restock_items'  => false,
                            ]);
                            $order->add_order_note('BSBT: Auto-expire -> refund requested.');
                        }
                    } else {
                        $order->update_status( 'cancelled', 'BSBT: Auto-expire – hold released.' );
                    }

                } catch ( \Throwable $e ) {
                    error_log('[BSBT AUTO-EXPIRE WC ERROR] Booking #' . $booking_id . ' | ' . $e->getMessage());
                }
            }

            // Keep calendar locked for admin investigation
            self::set_mphb_status_safe( $booking_id, 'pending' );

            error_log('[BSBT AUTO-EXPIRE] Booking #' . $booking_id . ' -> MPHB pending, Woo released.');
        }
    }

    /* =========================================================
     * ORDER FINDER (Meta + Deep Scan)
     * ========================================================= */

    /**
     * Find Woo order by booking id:
     * 1) meta lookup (fast) including our welded key
     * 2) deep fallback by item name "Reservation #ID"
     */
    private static function find_order_for_booking( int $booking_id ): ?WC_Order {

        if ( ! function_exists('wc_get_orders') ) return null;

        $statuses  = array_keys( wc_get_order_statuses() );

        // 1) meta lookup (fast)
        $meta_keys = [ self::META_BSBT_REF, '_mphb_booking_id' ];

        foreach ( $meta_keys as $key ) {
            $orders = wc_get_orders([
                'limit'      => 1,
                'meta_key'   => $key,
                'meta_value' => $booking_id,
                'status'     => $statuses,
                'orderby'    => 'date',
                'order'      => 'DESC',
            ]);

            if ( ! empty($orders) && $orders[0] instanceof WC_Order ) {
                return $orders[0];
            }
        }

        // 2) deep fallback
        $needle = 'Reservation #' . $booking_id;

        $recent_orders = wc_get_orders([
            'limit'   => 30,
            'status'  => $statuses,
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        foreach ( $recent_orders as $order ) {
            if ( ! ($order instanceof WC_Order) ) continue;

            foreach ( $order->get_items() as $item ) {
                $name = (string) $item->get_name();
                if ( $name && strpos( $name, $needle ) !== false ) {
                    return $order;
                }
            }
        }

        return null;
    }

    /* =========================================================
     * MPHB STATUS (SAFE, NO PREFIX)
     * ========================================================= */

    /**
     * Safe MPHB status setter using native statuses (no mphb- prefix).
     * Это важно, чтобы бронь не "исчезала" из списка и не было notice про незарегистрированный статус.
     */
    private static function set_mphb_status_safe( int $booking_id, string $status ): void {

        if ( ! function_exists('MPHB') ) return;

        try {
            $booking = MPHB()->getBookingRepository()->findById( $booking_id );
            if ( ! $booking ) return;

            // Native statuses from MPHB admin dropdown
            $allowed = [
                'pending-user',
                'pending-payment',
                'pending',
                'abandoned',
                'confirmed',
                'cancelled',
            ];

            if ( ! in_array( $status, $allowed, true ) ) {
                return;
            }

            $booking->setStatus( $status );
            MPHB()->getBookingRepository()->save( $booking );

            // Optional: clear availability cache
            if ( class_exists('\MPHB\Shortcodes\SearchAvailabilityShortcode') ) {
                \MPHB\Shortcodes\SearchAvailabilityShortcode::clearCache();
            }

        } catch ( \Throwable $e ) {
            error_log('[BSBT MPHB ERR] ' . $e->getMessage());
        }
    }

    /* =========================================================
     * PAYOUT CALCULATION (PHP 8.2 SAFE)
     * ========================================================= */

    /**
     * Расчет выплаты владельцу (DateTimeInterface safe).
     */
    private static function calculate_payout( int $booking_id ): float {

        if ( ! function_exists('MPHB') ) return 0.0;

        $booking = MPHB()->getBookingRepository()->findById( $booking_id );
        if ( ! $booking || empty($booking->getReservedRooms()) ) return 0.0;

        $room_type_id = (int) $booking->getReservedRooms()[0]->getRoomTypeId();
        $ppn = (float) get_post_meta( $room_type_id, 'owner_price_per_night', true );

        $in  = $booking->getCheckInDate();
        $out = $booking->getCheckOutDate();

        $in_ts  = ($in  instanceof \DateTimeInterface) ? $in->getTimestamp()  : strtotime( (string) $in );
        $out_ts = ($out instanceof \DateTimeInterface) ? $out->getTimestamp() : strtotime( (string) $out );

        if ( ! $in_ts || ! $out_ts ) return 0.0;

        $nights = max( 0, ($out_ts - $in_ts) / 86400 );

        return (float) ( $ppn * $nights );
    }
}

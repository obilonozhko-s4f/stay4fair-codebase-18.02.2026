<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V1.12.0 - strict snapshot source)
 * Version: 1.12.0
 */

if (!defined('ABSPATH')) exit;

final class BSBT_Owner_PDF {

    public static function init() {}

    private static function collect_data(int $bid): array {

        if (!function_exists('MPHB')) return ['ok'=>false];

        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return ['ok'=>false];

        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return ['ok'=>false];

        $rt = (int)$rooms[0]->getRoomTypeId();

        $snap_model  = get_post_meta($bid, '_bsbt_snapshot_model', true);
        $snap_guest  = (float)get_post_meta($bid, '_bsbt_snapshot_guest_total', true);
        $snap_payout = (float)get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);

        $guest_total = 0.0;

        if ($snap_guest > 0) {
            $guest_total = $snap_guest;
        } else {
            if (method_exists($b, 'getTotalPrice')) {
                $guest_total = (float)$b->getTotalPrice();
            } else {
                $guest_total = (float)get_post_meta($bid, 'mphb_booking_total_price', true);
            }
        }

        $pricing = null;

        if ($snap_model === 'model_b') {
            $pricing = [
                'commission_rate'        => (float)get_post_meta($bid, '_bsbt_snapshot_fee_rate', true),
                'commission_net_total'   => (float)get_post_meta($bid, '_bsbt_snapshot_fee_net_total', true),
                'commission_vat_total'   => (float)get_post_meta($bid, '_bsbt_snapshot_fee_vat_total', true),
                'commission_gross_total' => (float)get_post_meta($bid, '_bsbt_snapshot_fee_gross_total', true),
            ];
        }

        return [
            'ok'=>true,
            'data'=>[
                'booking_id'        => $bid,
                'business_model'    => ($snap_model === 'model_b' ? 'Modell B (Vermittlung)' : 'Modell A (Direkt)'),
                'apt_title'         => get_the_title($rt),
                'guest_gross_total' => number_format($guest_total, 2, ',', '.'),
                'payout'            => number_format($snap_payout, 2, ',', '.'),
                'pricing'           => $pricing,
            ]
        ];
    }
}

BSBT_Owner_PDF::init();

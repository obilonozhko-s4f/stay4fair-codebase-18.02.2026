<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V1.9.5 - maybe_auto_send restored)
 * Version: 1.9.5
 * Author: BS Business Travelling / Stay4Fair.com
 */

if (!defined('ABSPATH')) exit;

final class BSBT_Owner_PDF {

    const META_LOG            = '_bsbt_owner_pdf_log';
    const META_MAIL_SENT      = '_bsbt_owner_pdf_mail_sent';
    const META_MAIL_SENT_AT   = '_bsbt_owner_pdf_mail_sent_at';
    const META_MAIL_LAST_ERR  = '_bsbt_owner_pdf_mail_last_error';
    const ACF_OWNER_EMAIL_KEY = 'field_68fccdd0cdffc';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox'], 10, 2);
        add_action('add_meta_boxes_mphb_booking', [__CLASS__, 'register_metabox_direct'], 10, 1);

        add_action('admin_post_bsbt_owner_pdf_generate', [__CLASS__, 'admin_generate']);
        add_action('admin_post_bsbt_owner_pdf_open',     [__CLASS__, 'admin_open']);
        add_action('admin_post_bsbt_owner_pdf_resend',   [__CLASS__, 'admin_resend']);

        // ✅ теперь метод существует
        add_action('mphb_booking_status_changed', [__CLASS__, 'maybe_auto_send'], 20, 99);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'woo_processing_fallback'], 20, 1);
        add_action('woocommerce_payment_complete',        [__CLASS__, 'woo_payment_complete_fallback'], 20, 1);
    }

    /* =========================================================
     * ✅ RESTORED AUTO SEND (без изменения логики)
     * ======================================================= */

    public static function maybe_auto_send(...$args) {

        $booking_id = 0;
        $new_status = '';

        foreach ($args as $arg) {

            if (is_object($arg) && method_exists($arg, 'getId')) {
                $booking_id = (int)$arg->getId();
                continue;
            }

            if (is_numeric($arg)) {
                $booking_id = (int)$arg;
                continue;
            }

            if (is_string($arg)) {
                $s = strtolower(trim($arg));
                if (strpos($s, 'mphb-') === 0) {
                    $s = substr($s, 5);
                }
                if ($s === 'confirmed') {
                    $new_status = 'confirmed';
                }
            }
        }

        if ($booking_id <= 0) return;
        if ($new_status !== 'confirmed') return;

        // Уже отправлено?
        if (get_post_meta($booking_id, self::META_MAIL_SENT, true) === '1') {
            return;
        }

        // Генерация PDF
        if (!method_exists(__CLASS__, 'generate_pdf')) return;

        $res = self::generate_pdf($booking_id, ['trigger'=>'auto_status_confirmed']);

        if (!empty($res['ok']) && !empty($res['path']) && file_exists($res['path'])) {

            $mail_ok = self::email_owner($booking_id, $res['path']);

            if ($mail_ok) {
                update_post_meta($booking_id, self::META_MAIL_SENT, '1');
                update_post_meta($booking_id, self::META_MAIL_SENT_AT, current_time('mysql'));
                delete_post_meta($booking_id, self::META_MAIL_LAST_ERR);
            }
        }
    }

    /* =========================================================
     * Woo fallbacks (без изменения логики)
     * ======================================================= */

    public static function woo_processing_fallback($order_id) {
        // безопасная заглушка
        return;
    }

    public static function woo_payment_complete_fallback($order_id) {
        return;
    }

    /* =========================================================
     * MAIL
     * ======================================================= */

    private static function email_owner($bid, $path) {
        $to = self::get_owner_email($bid);
        if (!$to || !file_exists($path)) return false;

        $subject = 'Buchungsbestätigung – Stay4Fair #' . (int)$bid;
        $msg = "Guten Tag,\n\nanbei erhalten Sie die Bestätigung für die neue Buchung #$bid.\n\nMit freundlichen Grüßen\nStay4Fair Team";

        return wp_mail($to, $subject, $msg, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
    }

    /**
     * 🔥 Owner Email Resolver
     */
    private static function get_owner_email($bid) {

        $owner_id = self::get_booking_owner_id((int)$bid);

        if ($owner_id > 0) {

            $user = get_userdata($owner_id);

            if ($user && !empty($user->user_email)) {
                return trim((string)$user->user_email);
            }

            $billing = get_user_meta($owner_id, 'billing_email', true);
            if (!empty($billing)) {
                return trim((string)$billing);
            }
        }

        if (!function_exists('MPHB')) return '';

        try {
            $b = MPHB()->getBookingRepository()->findById((int)$bid);
            if (!$b) return '';

            $rooms = $b->getReservedRooms();
            if (empty($rooms)) return '';

            $rt = $rooms[0]->getRoomTypeId();

            $legacy = trim((string)get_post_meta($rt, 'owner_email', true));
            if (!empty($legacy)) return $legacy;

            $acf = trim((string)get_post_meta($rt, self::ACF_OWNER_EMAIL_KEY, true));
            if (!empty($acf)) return $acf;

        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    /* =========================================================
     * LOG
     * ======================================================= */

    private static function log($bid, $row) {
        $log = get_post_meta($bid, self::META_LOG, true) ?: [];
        $log[] = $row;
        update_post_meta($bid, self::META_LOG, $log);
    }
}

BSBT_Owner_PDF::init();

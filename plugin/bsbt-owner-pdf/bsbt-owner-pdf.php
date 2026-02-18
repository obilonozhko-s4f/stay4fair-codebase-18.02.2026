<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V1.9.3 - Security Hardening)
 * Version: 1.9.3
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

        add_action('mphb_booking_status_changed', [__CLASS__, 'maybe_auto_send'], 20, 99);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'woo_processing_fallback'], 20, 1);
        add_action('woocommerce_payment_complete',        [__CLASS__, 'woo_payment_complete_fallback'], 20, 1);
    }

    /* -------------------- ВСЁ БЕЗ ИЗМЕНЕНИЙ ДО collect_data -------------------- */

    public static function maybe_auto_send(...$args) {
        $booking = null; $bid = 0; $new_status = ''; $is_force = false;
        foreach ($args as $arg) {
            if (is_object($arg) && method_exists($arg, 'getId')) {
                $booking = $arg; $bid = (int)$arg->getId(); continue;
            }
            if ((is_int($arg) || (is_string($arg) && ctype_digit($arg))) && (int)$arg > 0) {
                $bid = (int)$arg; continue;
            }
            if (is_string($arg)) {
                $s = strtolower(trim($arg));
                if (strpos($s, 'mphb-') === 0) $s = substr($s, 5);
                if ($s === 'confirmed') $new_status = 'confirmed';
                if ($s === 'force_trigger') $is_force = true;
            }
        }
        if ($bid <= 0) return;
        if (!$is_force) {
            if ($new_status !== 'confirmed') return;
            if (get_post_meta($bid, self::META_MAIL_SENT, true) === '1') return;
        }
        if (!$booking && function_exists('MPHB')) {
            $booking = MPHB()->getBookingRepository()->findById($bid);
        }
        if (!$booking) return;
        $res = self::generate_pdf($bid, ['trigger' => $is_force ? 'force_trigger' : 'auto_status_confirmed']);
        if (!empty($res['ok']) && !empty($res['path']) && file_exists($res['path'])) {
            $mail_ok = self::email_owner($bid, $res['path']);
            if ($mail_ok) {
                update_post_meta($bid, self::META_MAIL_SENT, '1');
                update_post_meta($bid, self::META_MAIL_SENT_AT, current_time('mysql'));
                delete_post_meta($bid, self::META_MAIL_LAST_ERR);
            }
        }
    }

    public static function woo_processing_fallback($order_id) { self::bridge_from_order((int)$order_id, 'woo_processing'); }
    public static function woo_payment_complete_fallback($order_id) { self::bridge_from_order((int)$order_id, 'woo_payment_complete'); }

    private static function bridge_from_order(int $order_id, string $trigger): void {
        if ($order_id <= 0 || !function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order || !($order->is_paid() || in_array($order->get_status(), ['processing','completed']))) return;

        foreach ($order->get_items() as $item) {
            $payment_id = 0;
            $meta_data = $item->get_meta_data();
            foreach ($meta_data as $meta) {
                if (isset($meta->value) && is_numeric($meta->value)) {
                    $post_id = (int)$meta->value;
                    if ($post_id > 0 && get_post_type($post_id) === 'mphb_payment') {
                        $payment_id = $post_id; break;
                    }
                }
            }
            if ($payment_id > 0) {
                $booking_id = (int)get_post_meta($payment_id, '_mphb_booking_id', true);
                if ($booking_id > 0) {
                    if (get_post_meta($booking_id, self::META_MAIL_SENT, true) === '1') return;
                    self::maybe_auto_send($booking_id, 'confirmed', 'force_trigger');
                    $order->add_order_note("BSBT: Bridge Payment #$payment_id -> Booking #$booking_id (via $trigger)");
                    return;
                }
            }
        }
    }

    private static function generate_pdf(int $bid, array $ctx): array {
        if (!function_exists('bs_bt_try_load_pdf_engine')) return ['ok'=>false];
        $data = self::collect_data($bid);
        if (!$data['ok']) return ['ok'=>false];

        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']).'bsbt-owner-pdf/';
        wp_mkdir_p($dir);

        $path = $dir.'Owner_PDF_'.$bid.'.pdf';

        try {
            $engine = bs_bt_try_load_pdf_engine();
            $html = self::render_pdf_html($data['data']);

            if ($engine === 'mpdf') {
                $mpdf = new \Mpdf\Mpdf(['format'=>'A4']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($path, 'F');
            } else {
                $dom = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
                $dom->loadHtml($html, 'UTF-8');
                $dom->render();
                file_put_contents($path, $dom->output());
            }

            self::log($bid, ['path' => $path, 'generated_at' => current_time('mysql'), 'trigger' => $ctx['trigger'] ?? 'ui']);
            return ['ok'=>true, 'path'=>$path];

        } catch (\Throwable $e) {
            update_post_meta($bid, self::META_MAIL_LAST_ERR, 'PDF Error: ' . $e->getMessage());
            return ['ok'=>false];
        }
    }

    /* ===================== ЗДЕСЬ ТОЛЬКО ДОБАВИЛИ GROSS ===================== */

    private static function collect_data(int $bid): array {

        if (!function_exists('MPHB')) return ['ok'=>false];
        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return ['ok'=>false];

        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return ['ok'=>false];
        $rt = $rooms[0]->getRoomTypeId();

        $in  = get_post_meta($bid, 'mphb_check_in_date', true);
        $out = get_post_meta($bid, 'mphb_check_out_date', true);

        /* === NEU: Brutto Buchungspreis vom Gast === */
        $guest_gross_total = 0;
        if (method_exists($b, 'getTotalPrice')) {
            $guest_gross_total = (float)$b->getTotalPrice();
        } else {
            $guest_gross_total = (float)get_post_meta($bid, 'mphb_booking_total_price', true);
        }

        /* --- далее код 1:1 твой оригинал --- */

        $snap_payout = get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);
        $snap_model  = get_post_meta($bid, '_bsbt_snapshot_model', true);

        if ($snap_payout !== '') {
            $n         = (int)get_post_meta($bid, '_bsbt_snapshot_nights', true);
            $total     = (float)$snap_payout;
            $model_key = $snap_model;

            $pricing = null;
            if ($model_key === 'model_b') {
                $pricing = [
                    'commission_rate'        => (float)get_post_meta($bid, '_bsbt_snapshot_fee_rate', true),
                    'commission_net_total'   => (float)get_post_meta($bid, '_bsbt_snapshot_fee_net_total', true),
                    'commission_vat_total'   => (float)get_post_meta($bid, '_bsbt_snapshot_fee_vat_total', true),
                    'commission_gross_total' => (float)get_post_meta($bid, '_bsbt_snapshot_fee_gross_total', true)
                ];
            }
        } else {
            $n         = max(1, (strtotime($out) - strtotime($in)) / 86400);
            $model_key = get_post_meta($rt, '_bsbt_business_model', true) ?: 'model_a';
            $ppn       = (float)get_post_meta($rt, 'owner_price_per_night', true);
            if (!$ppn && function_exists('get_field')) $ppn = (float)get_field('owner_price_per_night', $rt);
            $total     = $ppn * $n;

            $pricing = null;
            if ($model_key === 'model_b') {
                $f = defined('BSBT_FEE') ? BSBT_FEE : 0.15;
                $v = defined('BSBT_VAT_ON_FEE') ? BSBT_VAT_ON_FEE : 0.19;
                $net = $total * $f; $vat = $net * $v;
                $pricing = ['commission_rate'=>$f, 'commission_net_total'=>$net, 'commission_vat_total'=>$vat, 'commission_gross_total'=>$net+$vat];
            }
        }

        $cc = get_post_meta($bid, 'mphb_country', true);
        $countries = ['DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','FR'=>'Frankreich','IT'=>'Italien','ES'=>'Spanien'];
        $full_country = $countries[$cc] ?? $cc;

        return ['ok'=>true, 'data'=>[
            'booking_id'     => $bid,
            'business_model' => ($model_key === 'model_b' ? 'Modell B (Vermittlung)' : 'Modell A (Direkt)'),
            'document_type'  => 'Abrechnung',
            'apt_title'      => get_the_title($rt),
            'apt_id'         => $rt,
            'apt_address'    => get_post_meta($rt, 'address', true),
            'owner_name'     => get_post_meta($rt, 'owner_name', true) ?: '—',
            'check_in'       => $in,
            'check_out'      => $out,
            'nights'         => $n,
            'guests'         => get_post_meta($bid, 'mphb_adults', true) ?: 1,
            'guest_name'     => trim((string)get_post_meta($bid, 'mphb_first_name', true) . ' ' . (string)get_post_meta($bid, 'mphb_last_name', true)),
            'guest_company'  => get_post_meta($bid, 'mphb_company', true),
            'guest_email'    => get_post_meta($bid, 'mphb_email', true),
            'guest_phone'    => get_post_meta($bid, 'mphb_phone', true),
            'guest_addr'     => get_post_meta($bid, 'mphb_address1', true),
            'guest_zip'      => get_post_meta($bid, 'mphb_zip', true),
            'guest_city'     => get_post_meta($bid, 'mphb_city', true),
            'guest_country'  => $full_country,
            'payout'         => number_format($total, 2, ',', '.'),
            'guest_gross_total' => number_format($guest_gross_total, 2, ',', '.'),
            'pricing'        => $pricing,
        ]];
    }

    /* ---- ВСЁ ОСТАЛЬНОЕ 1:1 КАК У ТЕБЯ ---- */

    private static function render_pdf_html($data) {
        ob_start(); $d = $data;
        $tpl = plugin_dir_path(__FILE__).'templates/owner-pdf.php';
        if (file_exists($tpl)) include $tpl;
        return ob_get_clean();
    }

    public static function register_metabox($post_type) { if ($post_type === 'mphb_booking') self::add_metabox(); }
    public static function register_metabox_direct() { self::add_metabox(); }
    private static function add_metabox() {
        add_meta_box('bsbt_owner_pdf', 'BSBT – Owner PDF', [__CLASS__, 'render_metabox'], 'mphb_booking', 'side', 'high');
    }

    public static function render_metabox($post) {
        $bid = (int)$post->ID;

        $decision = (string) get_post_meta($bid, '_bsbt_owner_decision', true);
        $status = ($decision === 'approved') ? 'BESTÄTIGT' : (($decision === 'declined') ? 'ABGELEHNT' : 'OFFEN');
        $color  = ($decision === 'approved') ? '#2e7d32' : (($decision === 'declined') ? '#c62828' : '#f9a825');

        $sent = (get_post_meta($bid, self::META_MAIL_SENT, true) === '1');
        $nonce = wp_create_nonce('bsbt_owner_pdf_'.$bid);

        echo "<div style='font-size:12px;line-height:1.4'>";
        echo "<p><strong>Entscheidung:</strong> <span style='color:" . esc_attr($color) . "'>" . esc_html($status) . "</span></p>";
        echo "<p><strong>E-Mail Status:</strong> " . ($sent ? "<span style='color:#2e7d32'>Versendet</span>" : "<span style='color:#f9a825'>Nicht versendet</span>") . "</p>";
        echo "<hr>";

        $open   = admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$nonce");
        $gen    = admin_url("admin-post.php?action=bsbt_owner_pdf_generate&booking_id=$bid&_wpnonce=$nonce");
        $resend = admin_url("admin-post.php?action=bsbt_owner_pdf_resend&booking_id=$bid&_wpnonce=$nonce");

        echo "<a class='button' target='_blank' href='" . esc_url($open) . "'>Öffnen</a> ";
        echo "<a class='button button-primary' href='" . esc_url($gen) . "'>Erzeugen</a> ";
        echo "<a class='button' href='" . esc_url($resend) . "'>Senden</a>";
        echo "</div>";
    }

    /* =========================================================
     * SECURITY / AUTH (ADMIN + OWNER SAFE)
     * ======================================================= */

    private static function get_booking_owner_id(int $booking_id): int {
        // 1) direct booking meta (if exists)
        $oid = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        if ($oid > 0) return $oid;

        // 2) via MPHB -> room_type -> bsbt_owner_id
        if (!function_exists('MPHB')) return 0;

        try {
            $b = MPHB()->getBookingRepository()->findById($booking_id);
            if (!$b) return 0;

            $rooms = $b->getReservedRooms();
            if (empty($rooms) || !is_object($rooms[0]) || !method_exists($rooms[0], 'getRoomTypeId')) return 0;

            $rt = (int) $rooms[0]->getRoomTypeId();
            if ($rt <= 0) return 0;

            return (int) get_post_meta($rt, 'bsbt_owner_id', true);

        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function guard_and_get_booking_id(string $purpose = 'read'): int {

        if (!is_user_logged_in()) {
            wp_die('No permission.');
        }

        $bid = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
        if ($bid <= 0) {
            wp_die('Invalid booking id.');
        }

        // Nonce (works for admin + owner)
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'bsbt_owner_pdf_'.$bid)) {
            wp_die('Invalid nonce.');
        }

        // Capability / object-level authorization
        if (current_user_can('manage_options')) {
            return $bid;
        }

        // Owner role: allow ONLY if booking belongs to current owner
        $u = wp_get_current_user();
        $is_owner = in_array('owner', (array)$u->roles, true);

        if ($is_owner) {
            $owner_id = self::get_booking_owner_id($bid);
            if ($owner_id > 0 && $owner_id === get_current_user_id()) {
                return $bid;
            }
            wp_die('Not your booking.');
        }

        // Everyone else denied
        wp_die('No permission.');
    }

    private static function safe_redirect_back(): void {
        $to = wp_get_referer();
        if (!$to) $to = admin_url('edit.php?post_type=mphb_booking');
        wp_safe_redirect($to);
        exit;
    }

    private static function safe_path_in_uploads(?string $path): bool {
        if (!$path) return false;

        $upload = wp_upload_dir();
        $base   = trailingslashit($upload['basedir']) . 'bsbt-owner-pdf/';

        $p = wp_normalize_path((string)$path);
        $b = wp_normalize_path((string)$base);

        return (strpos($p, $b) === 0);
    }

    /* =========================================================
     * ADMIN POST HANDLERS (HARDENED)
     * ======================================================= */

    public static function admin_generate() {
        $bid = self::guard_and_get_booking_id('write');
        self::generate_pdf($bid, ['trigger'=>'admin_generate']);
        self::safe_redirect_back();
    }

    public static function admin_open() {
        $bid = self::guard_and_get_booking_id('read');

        $path = '';
        $log = get_post_meta($bid, self::META_LOG, true);
        $last = is_array($log) ? end($log) : null;
        if ($last && !empty($last['path'])) {
            $path = (string) $last['path'];
        }

        // If missing -> generate once (keeps owner UX stable)
        if (!$path || !file_exists($path)) {
            $res = self::generate_pdf($bid, ['trigger'=>'open_autogen']);
            if (!empty($res['ok']) && !empty($res['path']) && file_exists($res['path'])) {
                $path = (string) $res['path'];
            }
        }

        if (!$path || !file_exists($path)) {
            wp_die('PDF Datei nicht gefunden.');
        }

        // Ensure file is within uploads/bsbt-owner-pdf/
        if (!self::safe_path_in_uploads($path)) {
            wp_die('Invalid file path.');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Owner_PDF_' . (int)$bid . '.pdf"');
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }

    public static function admin_resend() {
        $bid = self::guard_and_get_booking_id('write');

        // Admin-only resend (even if owner hits it by URL)
        if (!current_user_can('manage_options')) {
            wp_die('No permission.');
        }

        delete_post_meta($bid, self::META_MAIL_SENT);
        self::maybe_auto_send($bid, 'confirmed', 'force_trigger');

        self::safe_redirect_back();
    }

    /* =========================
     * MAIL
     * ========================= */

    private static function email_owner($bid, $path) {
        $to = self::get_owner_email($bid);
        if (!$to || !file_exists($path)) return false;

        $subject = 'Buchungsbestätigung – Stay4Fair #' . (int)$bid;
        $msg = "Guten Tag,\n\nanbei erhalten Sie die Bestätigung für die neue Buchung #$bid.\n\nMit freundlichen Grüßen\nStay4Fair Team";

        return wp_mail($to, $subject, $msg, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
    }

    private static function get_owner_email($bid) {
        if (!function_exists('MPHB')) return '';
        $b = MPHB()->getBookingRepository()->findById((int)$bid); if (!$b) return '';
        $rooms = $b->getReservedRooms(); if (empty($rooms)) return '';
        $rt = $rooms[0]->getRoomTypeId();

        // NOTE: пока legacy, позже переведём на bsbt_owner_id -> user_meta resolver
        return trim((string)get_post_meta($rt, 'owner_email', true)) ?: trim((string)get_post_meta($rt, self::ACF_OWNER_EMAIL_KEY, true));
    }

    private static function log($bid, $row) {
        $log = get_post_meta($bid, self::META_LOG, true) ?: [];
        $log[] = $row;
        update_post_meta($bid, self::META_LOG, $log);
    }
}

BSBT_Owner_PDF::init();

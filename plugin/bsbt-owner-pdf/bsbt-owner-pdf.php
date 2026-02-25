<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V1.12.0 - strict snapshot/MPHB source + Model A margin)
 * Version: 1.12.0
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

    /* =========================================================
     * ENTERPRISE-SAFE METABOX REGISTRATION (RESTORED)
     * ======================================================= */

    public static function register_metabox($post_type, $post = null) {
        if ( ! is_admin() ) return;
        if ($post_type !== 'mphb_booking') return;
        self::add_metabox();
    }

    public static function register_metabox_direct($post) {
        if ( ! is_admin() ) return;
        if ( ! $post || empty($post->post_type) || $post->post_type !== 'mphb_booking') return;
        self::add_metabox();
    }

    private static function add_metabox() {
        add_meta_box(
            'bsbt_owner_pdf',
            'BSBT – Owner PDF',
            [__CLASS__, 'render_metabox'],
            'mphb_booking',
            'side',
            'high'
        );
    }

    public static function render_metabox($post) {
        if ( ! $post || empty($post->ID) ) return;

        $bid = (int)$post->ID;

        $decision = (string) get_post_meta($bid, '_bsbt_owner_decision', true);
        $status = ($decision === 'approved') ? 'BESTÄTIGT' : (($decision === 'declined') ? 'ABGELEHNT' : 'OFFEN');
        $color  = ($decision === 'approved') ? '#2e7d32' : (($decision === 'declined') ? '#c62828' : '#f9a825');

        $sent = (get_post_meta($bid, self::META_MAIL_SENT, true) === '1');
        $nonce = wp_create_nonce('bsbt_owner_pdf_'.$bid);

        $open   = admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$nonce");
        $gen    = admin_url("admin-post.php?action=bsbt_owner_pdf_generate&booking_id=$bid&_wpnonce=$nonce");
        $resend = admin_url("admin-post.php?action=bsbt_owner_pdf_resend&booking_id=$bid&_wpnonce=$nonce");

        // Покажем, найден ли email (чтобы ты видел legacy-проблемы)
        $owner_email = self::get_owner_email($bid);

        echo "<div style='font-size:12px;line-height:1.4'>";
        echo "<p><strong>Entscheidung:</strong> <span style='color:" . esc_attr($color) . "'>" . esc_html($status) . "</span></p>";
        echo "<p><strong>E-Mail Status:</strong> " . ($sent ? "<span style='color:#2e7d32'>Versendet</span>" : "<span style='color:#f9a825'>Nicht versendet</span>") . "</p>";

        if ( ! empty($owner_email) ) {
            echo "<p><strong>Owner E-Mail:</strong><br><code>" . esc_html($owner_email) . "</code></p>";
        } else {
            echo "<p style='color:#c62828'><strong>⚠ Owner E-Mail NICHT gefunden</strong></p>";
        }

        echo "<p style='margin-top:10px'><a class='button button-secondary' href='" . esc_url($open) . "' target='_blank'>PDF öffnen</a></p>";
        echo "<p><a class='button button-primary' href='" . esc_url($gen) . "'>PDF neu generieren</a></p>";
        echo "<p><a class='button' href='" . esc_url($resend) . "'>Erneut senden</a></p>";

        $log = get_post_meta($bid, self::META_LOG, true);
        if (is_array($log) && !empty($log)) {
            $last = end($log);
            if (is_array($last)) {
                echo "<hr><p><strong>Last Log:</strong><br>";
                echo esc_html($last['time'] ?? '-') . " | " . esc_html($last['status'] ?? '-') . "<br>";
                echo esc_html($last['message'] ?? '');
                echo "</p>";
            }
        }

        echo "</div>";
    }

    public static function admin_open() {
        $bid = self::guard_and_get_booking_id('read');

        $ctx = self::collect_data($bid);
        if (empty($ctx['ok'])) wp_die('No data');

        $html = self::render_pdf_html($ctx['data']);
        $fname = 'owner-pdf-booking-'.$bid.'.pdf';

        if (!class_exists('\Mpdf\Mpdf')) {
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }

        try {
            $mpdf = new \Mpdf\Mpdf(['tempDir' => wp_get_upload_dir()['basedir'].'/mpdf-temp']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($fname, \Mpdf\Output\Destination::INLINE);
            exit;
        } catch (\Throwable $e) {
            wp_die('PDF failed: '.esc_html($e->getMessage()));
        }
    }

    public static function admin_generate() {
        $bid = self::guard_and_get_booking_id('write');

        $ctx = self::collect_data($bid);
        if (empty($ctx['ok'])) wp_die('No data');

        $res = self::generate_pdf($bid, $ctx['data']);

        self::log($bid, [
            'time'    => current_time('mysql'),
            'status'  => $res['ok'] ? 'generated' : 'error',
            'message' => $res['ok'] ? ('Saved: '.$res['path']) : ($res['error'] ?? 'unknown'),
        ]);

        self::safe_redirect_back();
    }

    public static function admin_resend() {
        $bid = self::guard_and_get_booking_id('write');

        $ctx = self::collect_data($bid);
        if (empty($ctx['ok'])) wp_die('No data');

        $res = self::generate_pdf($bid, $ctx['data']);

        if ($res['ok']) {
            $mail = self::email_owner($bid, $res['path']);
            self::log($bid, [
                'time'    => current_time('mysql'),
                'status'  => $mail['ok'] ? 'resent' : 'mail_error',
                'message' => $mail['ok'] ? 'Mail sent' : ($mail['error'] ?? 'mail unknown'),
            ]);
        } else {
            self::log($bid, [
                'time'    => current_time('mysql'),
                'status'  => 'error',
                'message' => $res['error'] ?? 'unknown',
            ]);
        }

        self::safe_redirect_back();
    }

    public static function maybe_auto_send($booking_id, $old_status = null, $new_status = null) {
        $booking_id = (int)$booking_id;
        if ($booking_id <= 0) return;

        $decision = (string)get_post_meta($booking_id, '_bsbt_owner_decision', true);
        if ($decision !== 'approved') return;

        if (get_post_meta($booking_id, self::META_MAIL_SENT, true) === '1') return;

        $ctx = self::collect_data($booking_id);
        if (empty($ctx['ok'])) return;

        $res = self::generate_pdf($booking_id, $ctx['data']);
        if (!$res['ok']) {
            self::log($booking_id, [
                'time'    => current_time('mysql'),
                'status'  => 'error',
                'message' => $res['error'] ?? 'pdf failed',
            ]);
            return;
        }

        $mail = self::email_owner($booking_id, $res['path']);
        self::log($booking_id, [
            'time'    => current_time('mysql'),
            'status'  => $mail['ok'] ? 'auto_sent' : 'mail_error',
            'message' => $mail['ok'] ? 'Auto mail sent' : ($mail['error'] ?? 'mail failed'),
        ]);
    }

    public static function woo_processing_fallback($order_id) {
        self::bridge_from_order((int)$order_id, 'woo_processing');
    }

    public static function woo_payment_complete_fallback($order_id) {
        self::bridge_from_order((int)$order_id, 'woo_payment_complete');
    }

    private static function bridge_from_order(int $order_id, string $trigger): void {
        if ($order_id <= 0) return;
        if (!function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $bid = (int)$order->get_meta('_mphb_booking_id', true);
        if (!$bid) $bid = (int)get_post_meta($order_id, '_mphb_booking_id', true);
        if (!$bid) return;

        $decision = (string)get_post_meta($bid, '_bsbt_owner_decision', true);
        if ($decision !== 'approved') return;

        if (get_post_meta($bid, self::META_MAIL_SENT, true) === '1') return;

        $ctx = self::collect_data($bid);
        if (empty($ctx['ok'])) return;

        $res = self::generate_pdf($bid, $ctx['data']);
        if (!$res['ok']) {
            self::log($bid, [
                'time'    => current_time('mysql'),
                'status'  => 'error',
                'message' => "[$trigger] ".$res['error'],
            ]);
            return;
        }

        $mail = self::email_owner($bid, $res['path']);
        self::log($bid, [
            'time'    => current_time('mysql'),
            'status'  => $mail['ok'] ? 'auto_sent' : 'mail_error',
            'message' => "[$trigger] ".($mail['ok'] ? 'Auto mail sent' : ($mail['error'] ?? 'mail failed')),
        ]);
    }

    private static function generate_pdf(int $bid, array $ctx): array {
        $up = wp_upload_dir();
        if (empty($up['basedir'])) return ['ok'=>false, 'error'=>'uploads unavailable'];

        $dir = trailingslashit($up['basedir']).'owner-pdf';
        if (!wp_mkdir_p($dir)) return ['ok'=>false, 'error'=>'cannot create dir'];

        $path = trailingslashit($dir).'owner-pdf-booking-'.$bid.'.pdf';
        $html = self::render_pdf_html($ctx);

        if (!class_exists('\Mpdf\Mpdf')) {
            return ['ok'=>false, 'error'=>'mPDF missing'];
        }

        try {
            $mpdf = new \Mpdf\Mpdf(['tempDir' => $up['basedir'].'/mpdf-temp']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($path, \Mpdf\Output\Destination::FILE);

            if (!file_exists($path) || filesize($path) <= 0) {
                return ['ok'=>false, 'error'=>'pdf not created'];
            }

            update_post_meta($bid, '_bsbt_owner_pdf_path', $path);
            update_post_meta($bid, '_bsbt_owner_pdf_generated_at', current_time('mysql'));

            return ['ok'=>true, 'path'=>$path];

        } catch (\Throwable $e) {
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    private static function collect_data(int $bid): array {

        if (!function_exists('MPHB')) return ['ok'=>false];

        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return ['ok'=>false];

        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return ['ok'=>false];

        $rt = (int)$rooms[0]->getRoomTypeId();

        $in  = (string)get_post_meta($bid, 'mphb_check_in_date', true);
        $out = (string)get_post_meta($bid, 'mphb_check_out_date', true);

        // Brutto Buchungspreis vom Gast (MPHB)
        $mphb_guest_total = 0.0;
        if (method_exists($b, 'getTotalPrice')) {
            $mphb_guest_total = (float)$b->getTotalPrice();
        } else {
            $mphb_guest_total = (float)get_post_meta($bid, 'mphb_booking_total_price', true);
        }

        // Snapshot fields
        $snap_model       = (string)get_post_meta($bid, '_bsbt_snapshot_model', true);
        $snap_guest_total = (float)get_post_meta($bid, '_bsbt_snapshot_guest_total', true);
        $snap_payout      = get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);
        $snap_margin      = (float)get_post_meta($bid, '_bsbt_snapshot_margin_total', true);

        // Гостевую сумму берём из snapshot, если он существует, иначе из MPHB.
        $guest_gross_total = ($snap_guest_total > 0) ? (float)$snap_guest_total : (float)$mphb_guest_total;

        if ($snap_payout !== '') {

            $n         = (int)get_post_meta($bid, '_bsbt_snapshot_nights', true);
            $total     = (float)$snap_payout;
            $model_key = $snap_model ?: (string)(get_post_meta($rt, '_bsbt_business_model', true) ?: 'model_a');

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

            // Fallback: если snapshot отсутствует (например, старые брони)
            $n         = max(1, (int)((strtotime($out) - strtotime($in)) / 86400));
            $model_key = (string)(get_post_meta($rt, '_bsbt_business_model', true) ?: 'model_a');

            $ppn = (float)get_post_meta($rt, 'owner_price_per_night', true);
            if (!$ppn && function_exists('get_field')) $ppn = (float)get_field('owner_price_per_night', $rt);

            $total = $ppn * $n;

            $pricing = null;
            if ($model_key === 'model_b') {

                // Для Model B база = гостевая сумма (snapshot если есть, иначе MPHB)
                $base = ($snap_guest_total > 0) ? (float)$snap_guest_total : (float)$mphb_guest_total;

                $f = defined('BSBT_FEE') ? (float)BSBT_FEE : 0.15;
                $v = defined('BSBT_VAT_ON_FEE') ? (float)BSBT_VAT_ON_FEE : 0.19;

                $net = round($base * $f, 2);
                $vat = round($net * $v, 2);

                $pricing = [
                    'commission_rate'        => $f,
                    'commission_net_total'   => $net,
                    'commission_vat_total'   => $vat,
                    'commission_gross_total' => $net + $vat
                ];

                // Payout в fallback для Model B = guest_total - commission_net
                $total = round($base - $net, 2);

            } else {
                // Model A fallback payout = ppn * nights (как закупка)
                $total = round($total, 2);
            }
        }

        $cc = (string)get_post_meta($bid, 'mphb_country', true);
        $countries = ['DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','FR'=>'Frankreich','IT'=>'Italien','ES'=>'Spanien'];
        $full_country = $countries[$cc] ?? $cc;

        return ['ok'=>true, 'data'=>[
            'booking_id'        => $bid,
            'business_model'    => ($model_key === 'model_b' ? 'Modell B (Vermittlung)' : 'Modell A (Direkt)'),
            'document_type'     => 'Abrechnung',
            'apt_title'         => get_the_title($rt),
            'apt_id'            => $rt,
            'apt_address'       => (string)get_post_meta($rt, 'address', true),
            'owner_name'        => (string)(get_post_meta($rt, 'owner_name', true) ?: '—'),
            'check_in'          => $in,
            'check_out'         => $out,
            'nights'            => $n,
            'guests'            => (int)(get_post_meta($bid, 'mphb_adults', true) ?: 1),
            'guest_name'        => trim((string)get_post_meta($bid, 'mphb_first_name', true) . ' ' . (string)get_post_meta($bid, 'mphb_last_name', true)),
            'guest_company'     => (string)get_post_meta($bid, 'mphb_company', true),
            'guest_email'       => (string)get_post_meta($bid, 'mphb_email', true),
            'guest_phone'       => (string)get_post_meta($bid, 'mphb_phone', true),
            'guest_addr'        => (string)get_post_meta($bid, 'mphb_address1', true),
            'guest_zip'         => (string)get_post_meta($bid, 'mphb_zip', true),
            'guest_city'        => (string)get_post_meta($bid, 'mphb_city', true),
            'guest_country'     => $full_country,

            // payout = сумма к выплате владельцу (snapshot/расчёт)
            'payout'            => number_format((float)$total, 2, ',', '.'),

            // guest_gross_total = сумма гостя (snapshot/MPHB)
            'guest_gross_total' => number_format((float)$guest_gross_total, 2, ',', '.'),

            // margin_total = маржа для Model A (если snapshot её уже записал)
            'margin_total'      => number_format((float)max(0.0, $snap_margin), 2, ',', '.'),

            'pricing'           => $pricing,
        ]];
    }

    private static function render_pdf_html($data) {
        ob_start();
        $d = $data;
        $tpl = plugin_dir_path(__FILE__).'templates/owner-pdf.php';
        if (file_exists($tpl)) include $tpl;
        return ob_get_clean();
    }

    /* =========================================================
     * SECURITY / AUTH (AS-IS)
     * ======================================================= */

    private static function get_booking_owner_id(int $booking_id): int {
        $oid = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        if ($oid > 0) return $oid;

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

        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'bsbt_owner_pdf_'.$bid)) {
            wp_die('Invalid nonce.');
        }

        if (current_user_can('manage_options')) {
            return $bid;
        }

        $u = wp_get_current_user();
        $is_owner = in_array('owner', (array)$u->roles, true);

        if ($is_owner) {
            $owner_id = self::get_booking_owner_id($bid);
            if ($owner_id > 0 && $owner_id === get_current_user_id()) {
                return $bid;
            }
            wp_die('Not your booking.');
        }

        wp_die('No permission.');
    }

    private static function safe_redirect_back(): void {
        $to = wp_get_referer();
        if (!$to) $to = admin_url('edit.php?post_type=mphb_booking');
        wp_safe_redirect($to);
        exit;
    }

    private static function safe_path_in_uploads(?string $path): bool {
        if (!$path || !is_string($path)) return false;

        $up = wp_upload_dir();
        if (empty($up['basedir'])) return false;

        $base = wp_normalize_path((string)$up['basedir']);
        $real = wp_normalize_path((string)$path);

        if ($base === '' || $real === '') return false;
        if (strpos($real, $base) !== 0) return false;

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') return false;

        return true;
    }

    private static function email_owner($bid, $path) {

        if (!self::safe_path_in_uploads($path) || !file_exists($path)) {
            update_post_meta($bid, self::META_MAIL_LAST_ERR, 'invalid_or_missing_pdf');
            return ['ok' => false, 'error' => 'PDF path invalid'];
        }

        $to = self::get_owner_email($bid);

        if (!$to || !is_email($to)) {
            update_post_meta($bid, self::META_MAIL_LAST_ERR, 'owner_email_missing');
            return ['ok' => false, 'error' => 'Owner email missing'];
        }

        $subject = 'Buchungsbestätigung – Booking #'.$bid;

        $lines = [
            'Hallo,',
            '',
            'im Anhang erhalten Sie die Buchungsbestätigung inkl. Abrechnung als PDF.',
            '',
            'Viele Grüße',
            'Stay4Fair'
        ];
        $body = implode("\n", $lines);

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $body, $headers, [$path]);

        if ($sent) {
            update_post_meta($bid, self::META_MAIL_SENT, '1');
            update_post_meta($bid, self::META_MAIL_SENT_AT, current_time('mysql'));
            update_post_meta($bid, self::META_MAIL_LAST_ERR, '');
            return ['ok' => true];
        }

        update_post_meta($bid, self::META_MAIL_LAST_ERR, 'wp_mail_failed');
        return ['ok' => false, 'error' => 'wp_mail failed'];
    }

    private static function get_owner_email($bid) {
        if (!function_exists('MPHB')) return '';

        try {
            $b = MPHB()->getBookingRepository()->findById((int)$bid);
            if (!$b) return '';

            $rooms = $b->getReservedRooms();
            if (empty($rooms) || !is_object($rooms[0]) || !method_exists($rooms[0], 'getRoomTypeId')) return '';

            $rt = (int)$rooms[0]->getRoomTypeId();
            if ($rt <= 0) return '';

            $candidates = [];

            $meta_owner = get_post_meta($rt, 'owner_email', true);
            if (is_string($meta_owner) && trim($meta_owner) !== '') $candidates[] = trim($meta_owner);

            if (function_exists('get_field')) {
                $acf_by_name = get_field('owner_email', $rt);
                if (is_string($acf_by_name) && trim($acf_by_name) !== '') $candidates[] = trim($acf_by_name);

                $acf_by_key = get_field(self::ACF_OWNER_EMAIL_KEY, $rt);
                if (is_string($acf_by_key) && trim($acf_by_key) !== '') $candidates[] = trim($acf_by_key);
            }

            $owner_uid = (int) get_post_meta($rt, 'bsbt_owner_id', true);
            if ($owner_uid > 0) {
                $u = get_userdata($owner_uid);
                if ($u && !empty($u->user_email)) $candidates[] = (string)$u->user_email;
            }

            foreach ($candidates as $em) {
                $em = sanitize_email($em);
                if ($em && is_email($em)) return $em;
            }

            return '';

        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function log($bid, $row) {
        $log = get_post_meta($bid, self::META_LOG, true);
        if (!is_array($log)) $log = [];
        $log[] = $row;
        if (count($log) > 100) $log = array_slice($log, -100);
        update_post_meta($bid, self::META_LOG, $log);
    }
}

BSBT_Owner_PDF::init();

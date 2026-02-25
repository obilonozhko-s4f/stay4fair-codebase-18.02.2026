<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V1.9.3 - Snapshot + Nights + Model A Brutto hidden)
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

    /* =========================================================
     * AUTO SEND (MPHB status confirmed OR forced trigger)
     * ======================================================= */

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

    /* =========================================================
     * WOO FALLBACK BRIDGE (order -> payment -> booking)
     * ======================================================= */

    public static function woo_processing_fallback($order_id) {
        self::bridge_from_order((int)$order_id, 'woo_processing');
    }

    public static function woo_payment_complete_fallback($order_id) {
        self::bridge_from_order((int)$order_id, 'woo_payment_complete');
    }

    private static function bridge_from_order(int $order_id, string $trigger): void {

        if ($order_id <= 0 || !function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        if (!$order->is_paid() && !in_array($order->get_status(), ['processing','completed'], true)) return;

        foreach ($order->get_items() as $item) {

            // Primary: MPHB writes payment id into order item meta key "_mphb_payment_id"
            $payment_id = (int) $item->get_meta('_mphb_payment_id', true);

            // Fallback (legacy): scan numeric metas for mphb_payment post ids
            if ($payment_id <= 0) {
                $meta_data = $item->get_meta_data();
                foreach ($meta_data as $meta) {
                    if (isset($meta->value) && is_numeric($meta->value)) {
                        $post_id = (int)$meta->value;
                        if ($post_id > 0 && get_post_type($post_id) === 'mphb_payment') {
                            $payment_id = $post_id;
                            break;
                        }
                    }
                }
            }

            if ($payment_id > 0) {

                // MPHB stores booking link on payment post
                $booking_id = (int) get_post_meta($payment_id, '_mphb_booking_id', true);
                if ($booking_id <= 0) {
                    // some setups may use non-underscore key
                    $booking_id = (int) get_post_meta($payment_id, 'mphb_booking_id', true);
                }

                if ($booking_id > 0) {

                    if (get_post_meta($booking_id, self::META_MAIL_SENT, true) === '1') return;

                    self::maybe_auto_send($booking_id, 'confirmed', 'force_trigger');
                    $order->add_order_note("BSBT: Bridge Payment #$payment_id -> Booking #$booking_id (via $trigger)");
                    return;
                }
            }
        }
    }

    /* =========================================================
     * PDF GENERATION
     * ======================================================= */

    private static function generate_pdf(int $bid, array $ctx): array {

        if (!function_exists('bs_bt_try_load_pdf_engine')) return ['ok'=>false, 'message'=>'PDF engine not available'];

        $data = self::collect_data($bid);
        if (empty($data['ok'])) return ['ok'=>false, 'message'=>'Collect data failed'];

        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'bsbt-owner-pdf/';
        wp_mkdir_p($dir);

        $path = $dir . 'Owner_PDF_' . $bid . '.pdf';

        try {

            $engine = bs_bt_try_load_pdf_engine();
            $html = self::render_pdf_html($data['data']);

            if ($engine === 'mpdf') {
                $mpdf = new \Mpdf\Mpdf(['format' => 'A4']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($path, 'F');
            } else {
                $dom = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
                $dom->loadHtml($html, 'UTF-8');
                $dom->render();
                file_put_contents($path, $dom->output());
            }

            self::log($bid, [
                'path'         => $path,
                'generated_at' => current_time('mysql'),
                'trigger'      => $ctx['trigger'] ?? 'ui',
            ]);

            return ['ok'=>true, 'path'=>$path];

        } catch (\Throwable $e) {
            update_post_meta($bid, self::META_MAIL_LAST_ERR, 'PDF Error: ' . $e->getMessage());
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
    }

    private static function render_pdf_html($data) {
        ob_start();
        $d = $data;
        $tpl = plugin_dir_path(__FILE__) . 'templates/owner-pdf.php';
        if (file_exists($tpl)) include $tpl;
        return ob_get_clean();
    }

    /* =========================================================
     * DATA COLLECTION (SNAPSHOT SAFE)
     * ======================================================= */

    private static function collect_data(int $bid): array {

        if (!function_exists('MPHB')) return ['ok'=>false];

        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return ['ok'=>false];

        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return ['ok'=>false];

        $rt = (int) $rooms[0]->getRoomTypeId();

        $in  = (string) get_post_meta($bid, 'mphb_check_in_date', true);
        $out = (string) get_post_meta($bid, 'mphb_check_out_date', true);

        // ✅ FIX: Nights ALWAYS from dates (snapshot does not store nights)
        $n = 0;
        if ($in && $out) {
            $n = (int) max(1, (strtotime($out) - strtotime($in)) / 86400);
        }

        $snap_payout = get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);
        $snap_model  = (string) get_post_meta($bid, '_bsbt_snapshot_model', true);

        $model_key = 'model_a';
        $total_owner_payout = 0.0;
        $guest_total = 0.0;
        $pricing = null;

        if ($snap_payout !== '' && $snap_payout !== null) {

            $total_owner_payout = (float) $snap_payout;
            $guest_total        = (float) get_post_meta($bid, '_bsbt_snapshot_guest_total', true);
            $model_key          = $snap_model ?: 'model_a';

            if ($model_key === 'model_b') {

                $fee_rate = (float) get_post_meta($bid, '_bsbt_snapshot_fee_rate', true);
                if ($fee_rate <= 0) $fee_rate = (defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15);

                $pricing = [
                    'commission_rate'        => $fee_rate,
                    'commission_net_total'   => (float) get_post_meta($bid, '_bsbt_snapshot_fee_net_total', true),
                    'commission_vat_total'   => (float) get_post_meta($bid, '_bsbt_snapshot_fee_vat_total', true),
                    // ✅ FIX: snapshot key is _bsbt_snapshot_fee_brut_total (not "gross_total" / not "fee_gross_total")
                    'commission_gross_total' => (float) get_post_meta($bid, '_bsbt_snapshot_fee_brut_total', true),
                ];
            }

        } else {

            $model_key = (string) get_post_meta($rt, '_bsbt_business_model', true);
            $model_key = $model_key ?: 'model_a';

            $ppn = (float) get_post_meta($rt, 'owner_price_per_night', true);
            if (!$ppn && function_exists('get_field')) {
                $ppn = (float) get_field('owner_price_per_night', $rt);
            }

            $total_owner_payout = (float) ($ppn * $n);

            // In non-snapshot fallback:
            // - Model A: guest_total = payout (resell total not used here)
            // - Model B: guest_total approximated as payout/(1-fee_rate) (but you primarily use snapshot; keep simple safe)
            if ($model_key === 'model_b') {

                $f = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15;
                $v = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.19;

                // Approx guest total from owner payout (safe fallback)
                $guest_total = ($f > 0 && $f < 1) ? round($total_owner_payout / (1 - $f), 2) : $total_owner_payout;

                $fee_brut = round($guest_total * $f, 2);
                $fee_net  = round($fee_brut / (1 + $v), 2);
                $fee_vat  = round($fee_brut - $fee_net, 2);

                $pricing = [
                    'commission_rate'        => $f,
                    'commission_net_total'   => $fee_net,
                    'commission_vat_total'   => $fee_vat,
                    'commission_gross_total' => $fee_brut,
                ];

            } else {
                $guest_total = $total_owner_payout;
            }
        }

        $cc = (string) get_post_meta($bid, 'mphb_country', true);
        $countries = ['DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','FR'=>'Frankreich','IT'=>'Italien','ES'=>'Spanien'];
        $full_country = $countries[$cc] ?? $cc;

        return ['ok'=>true, 'data'=>[
            'booking_id'        => $bid,
            'business_model'    => ($model_key === 'model_b' ? 'Modell B (Vermittlung)' : 'Modell A (Direkt)'),
            'model_key'         => $model_key,
            'document_type'     => 'Abrechnung',
            'apt_title'         => get_the_title($rt),
            'apt_id'            => $rt,
            'apt_address'       => get_post_meta($rt, 'address', true),
            'owner_name'        => get_post_meta($rt, 'owner_name', true) ?: '—',
            'check_in'          => $in,
            'check_out'         => $out,
            'nights'            => $n,
            'guests'            => get_post_meta($bid, 'mphb_adults', true) ?: 1,
            'guest_name'        => trim((string)get_post_meta($bid, 'mphb_first_name', true) . ' ' . (string)get_post_meta($bid, 'mphb_last_name', true)),
            'guest_company'     => get_post_meta($bid, 'mphb_company', true),
            'guest_email'       => get_post_meta($bid, 'mphb_email', true),
            'guest_phone'       => get_post_meta($bid, 'mphb_phone', true),
            'guest_addr'        => get_post_meta($bid, 'mphb_address1', true),
            'guest_zip'         => get_post_meta($bid, 'mphb_zip', true),
            'guest_city'        => get_post_meta($bid, 'mphb_city', true),
            'guest_country'     => $full_country,

            // formatted strings for template
            'guest_gross_total' => number_format((float)$guest_total, 2, ',', '.'),
            'payout'            => number_format((float)$total_owner_payout, 2, ',', '.'),
            'pricing'           => $pricing,
        ]];
    }

    /* =========================================================
     * METABOX
     * ======================================================= */

    public static function register_metabox($post_type) {
        if ($post_type === 'mphb_booking') self::add_metabox();
    }

    public static function register_metabox_direct() {
        self::add_metabox();
    }

    private static function add_metabox() {
        add_meta_box('bsbt_owner_pdf', 'BSBT – Owner PDF', [__CLASS__, 'render_metabox'], 'mphb_booking', 'side', 'high');
    }

    public static function render_metabox($post) {

        $bid = (int) $post->ID;

        $decision = (string) get_post_meta($bid, '_bsbt_owner_decision', true);
        $status = ($decision === 'approved') ? 'BESTÄTIGT' : (($decision === 'declined') ? 'ABGELEHNT' : 'OFFEN');
        $color  = ($decision === 'approved') ? '#2e7d32' : (($decision === 'declined') ? '#c62828' : '#f9a825');

        $sent = (get_post_meta($bid, self::META_MAIL_SENT, true) === '1');
        $nonce = wp_create_nonce('bsbt_owner_pdf_' . $bid);

        echo "<div style='font-size:12px;line-height:1.4'>";
        echo "<p><strong>Entscheidung:</strong> <span style='color:$color'>$status</span></p>";
        echo "<p><strong>E-Mail Status:</strong> " . ($sent ? "<span style='color:#2e7d32'>Versendet</span>" : "<span style='color:#f9a825'>Nicht versendet</span>") . "</p>";
        echo "<hr>";
        echo "<a class='button' target='_blank' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$nonce") . "'>Öffnen</a> ";
        echo "<a class='button button-primary' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_generate&booking_id=$bid&_wpnonce=$nonce") . "'>Erzeugen</a> ";
        echo "<a class='button' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_resend&booking_id=$bid&_wpnonce=$nonce") . "'>Senden</a>";
        echo "</div>";
    }

    /* =========================================================
     * ADMIN ACTIONS
     * ======================================================= */

    public static function admin_generate() {
        self::guard();
        self::generate_pdf((int)($_GET['booking_id'] ?? 0), ['trigger' => 'admin']);
        wp_redirect(wp_get_referer());
        exit;
    }

    public static function admin_open() {

        self::guard();

        $bid = (int)($_GET['booking_id'] ?? 0);

        $log = get_post_meta($bid, self::META_LOG, true);
        $last = is_array($log) ? end($log) : null;

        if (!$last || empty($last['path']) || !file_exists($last['path'])) {
            wp_die('PDF Datei nicht gefunden.');
        }

        header('Content-Type: application/pdf');
        readfile($last['path']);
        exit;
    }

    public static function admin_resend() {
        self::guard();
        $bid = (int)($_GET['booking_id'] ?? 0);
        delete_post_meta($bid, self::META_MAIL_SENT);
        self::maybe_auto_send($bid, 'confirmed', 'force_trigger');
        wp_redirect(wp_get_referer());
        exit;
    }

    private static function guard() {
        $bid = (int)($_GET['booking_id'] ?? 0);
        check_admin_referer('bsbt_owner_pdf_' . $bid);
    }

    /* =========================================================
     * EMAIL
     * ======================================================= */

    private static function email_owner($bid, $path) {

        $to = self::get_owner_email($bid);
        if (!$to || !file_exists($path)) return false;

        $subject = 'Buchungsbestätigung – Stay4Fair #' . $bid;

        $msg = "Guten Tag,\n\nanbei erhalten Sie die Bestätigung für die neue Buchung #$bid.\n\nMit freundlichen Grüßen\nStay4Fair Team";

        return wp_mail($to, $subject, $msg, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
    }

    private static function get_owner_email($bid) {

        if (!function_exists('MPHB')) return '';

        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return '';

        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return '';

        $rt = (int) $rooms[0]->getRoomTypeId();

        $email = trim((string) get_post_meta($rt, 'owner_email', true));
        if ($email) return $email;

        // ACF fallback (if stored as post meta – depends on your setup)
        $acf = trim((string) get_post_meta($rt, self::ACF_OWNER_EMAIL_KEY, true));
        return $acf;
    }

    /* =========================================================
     * LOG
     * ======================================================= */

    private static function log($bid, $row) {
        $log = get_post_meta($bid, self::META_LOG, true);
        if (!is_array($log)) $log = [];
        $log[] = $row;
        update_post_meta($bid, self::META_LOG, $log);
    }
}

BSBT_Owner_PDF::init();

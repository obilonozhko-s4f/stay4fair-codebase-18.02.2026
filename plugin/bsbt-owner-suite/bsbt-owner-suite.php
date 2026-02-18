<?php
/**
 * Plugin Name: BSBT – Owner Suite (WhatsApp + Admin UI)
 * Description: Owner communication (WhatsApp) + Admin UI. Decision logic delegated to Owner Portal Core.
 * Version: 1.5.0
 * Author: BS Business Travelling
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * SAFETY
 * ======================================================= */
if (defined('BSBT_OWNER_SUITE_LOADED')) return;
define('BSBT_OWNER_SUITE_LOADED', true);

/*
|--------------------------------------------------------------------------
| IMPORTANT ARCHITECTURE NOTE (V1.5.0)
|--------------------------------------------------------------------------
| This plugin NO LONGER handles booking decision logic.
| All decisions (approve/decline/expire) are delegated to:
| BSBT_Owner_Decision_Core (Owner Portal plugin).
|
| This file is now UI-only:
| - WhatsApp communication
| - Admin Metabox
| - Snapshot-first payout preview
|--------------------------------------------------------------------------
*/

/* =========================================================
 * LOAD ADMIN COLUMNS
 * ======================================================= */
if ( is_admin() ) {
    require_once plugin_dir_path(__FILE__) . 'includes/admin-columns.php';
}

/* =========================================================
 * HELPERS: MPHB SAFE ACCESS
 * ======================================================= */
function bsbt_os_get_booking($booking_id) {
    if (!function_exists('MPHB')) return null;
    return MPHB()->getBookingRepository()->findById($booking_id);
}

function bsbt_os_get_room_type_id($booking_id): int {
    $b = bsbt_os_get_booking($booking_id);
    if (!$b) return 0;
    $room = $b->getReservedRooms()[0] ?? null;
    return ($room && method_exists($room,'getRoomTypeId')) ? (int)$room->getRoomTypeId() : 0;
}

function bsbt_os_get_guests($booking_id): int {
    $b = bsbt_os_get_booking($booking_id);
    if (!$b) return 0;
    $room = $b->getReservedRooms()[0] ?? null;
    if (!$room) return 0;
    $g = 0;
    if (method_exists($room,'getAdults'))   $g += (int)$room->getAdults();
    if (method_exists($room,'getChildren')) $g += (int)$room->getChildren();
    return $g;
}

/* =========================================================
 * SNAPSHOT-FIRST PAYOUT LOGIC (READ-ONLY)
 * ======================================================= */
function bsbt_os_calc_payout($booking_id): float {

    // 1️⃣ Snapshot priority (immutable financial truth)
    $snapshot = get_post_meta($booking_id, '_bsbt_snapshot_owner_payout', true);

    if ($snapshot !== '' && $snapshot !== null) {
        return (float) $snapshot;
    }

    // 2️⃣ Legacy preview fallback (BEFORE confirmation only)
    $rt = bsbt_os_get_room_type_id($booking_id);
    if (!$rt) return 0.0;

    $ppn = (float)get_post_meta($rt,'owner_price_per_night',true);
    if ($ppn <= 0) return 0.0;

    $in  = (string)get_post_meta($booking_id,'mphb_check_in_date',true);
    $out = (string)get_post_meta($booking_id,'mphb_check_out_date',true);
    if (!$in || !$out) return 0.0;

    $nights = max(0,(strtotime($out)-strtotime($in))/86400);

    return round($ppn * $nights, 2);
}

/* =========================================================
 * WHATSAPP MESSAGE
 * ======================================================= */
function bsbt_os_build_whatsapp_text($booking_id): string {

    $rt = bsbt_os_get_room_type_id($booking_id);

    // ⚠️ Legacy fallback (to be migrated to owner resolver)
    $owner = (string)get_post_meta($rt,'owner_name',true) ?: 'Guten Tag';

    $apt   = $rt ? (get_the_title($rt) ?: '—') : '—';
    $in    = (string)get_post_meta($booking_id,'mphb_check_in_date',true);
    $out   = (string)get_post_meta($booking_id,'mphb_check_out_date',true);
    $g     = bsbt_os_get_guests($booking_id);
    $pay   = number_format(bsbt_os_calc_payout($booking_id),2,',','.');
    $portal= 'https://stay4fair.com/owner-bookings/?booking_id='.$booking_id;

    return "Hallo {$owner} | neue Buchungsanfrage | Wohnung: {$apt} | Zeitraum: {$in} – {$out} | Gäste: {$g} | Auszahlung für Sie: {$pay} € | Bitte loggen Sie sich in Ihr Eigentümer-Konto ein und bestätigen oder lehnen Sie dort ab: {$portal} | Vielen Dank | Stay4Fair.com";
}

function bsbt_os_whatsapp_url($booking_id): string {
    $rt = bsbt_os_get_room_type_id($booking_id);
    $phone = preg_replace('/\D+/','',(string)get_post_meta($rt,'owner_phone',true));
    if (!$phone) return '';
    if (strpos($phone,'0')===0) $phone = '49'.substr($phone,1);
    return 'https://wa.me/'.$phone.'?text='.rawurlencode(bsbt_os_build_whatsapp_text($booking_id));
}

/* =========================================================
 * ADMIN METABOX (UI ONLY)
 * ======================================================= */
add_action('add_meta_boxes', function(){
    if (!current_user_can('manage_options')) return;
    add_meta_box(
        'bsbt_owner_suite_box',
        'BSBT – Owner Actions',
        'bsbt_os_render_box',
        'mphb_booking',
        'side',
        'high'
    );
});

function bsbt_os_render_box($post){

    if (!class_exists('BSBT_Owner_Decision_Core')) {
        echo "<div style='color:#c62828;font-weight:600'>
            Owner Portal Core not loaded.
        </div>";
        return;
    }

    $bid = (int)$post->ID;

    $decision = (string)get_post_meta($bid,'_bsbt_owner_decision',true);
    $source   = (string)get_post_meta($bid,'_bsbt_owner_decision_source',true);
    $time     = (string)get_post_meta($bid,'_bsbt_owner_decision_time',true);
    $user_id  = (int)get_post_meta($bid,'_bsbt_owner_decision_user_id',true);

    $rt = bsbt_os_get_room_type_id($bid);
    $owner_id = (int)get_post_meta($rt,'bsbt_owner_id',true);
    $wa = bsbt_os_whatsapp_url($bid);
    $text = bsbt_os_build_whatsapp_text($bid);
    $pay = number_format(bsbt_os_calc_payout($bid),2,',','.');
    $nonce = wp_create_nonce('bsbt_owner_action');
    $ajax = admin_url('admin-ajax.php');

    $status = 'OFFEN'; 
    $color='#f9a825';
    if ($decision==='approved'){ $status='BESTÄTIGT'; $color='#2e7d32'; }
    if ($decision==='declined'){ $status='ABGELEHNT'; $color='#c62828'; }

    echo "<div style='font-size:12px;line-height:1.45'>";

    echo "<p><strong>Status:</strong> <span style='color:$color;font-weight:700'>$status</span></p>";
    echo "<p><strong>Owner:</strong> ".($owner_id?'🟢 registriert':'🔴 nicht registriert')."</p>";
    echo "<p><strong>Auszahlung:</strong> {$pay} €</p>";

    if ($decision){
        echo "<hr style='margin:8px 0'>";
        echo "<p><strong>Decision source:</strong> ".esc_html($source ?: '—')."</p>";
        if ($user_id){
            $u = get_userdata($user_id);
            if ($u){
                echo "<p><strong>Admin:</strong> ".esc_html($u->display_name)."</p>";
            }
        }
        if ($time){
            echo "<p><strong>Zeitpunkt:</strong> ".esc_html($time)."</p>";
        }
    }

    echo "<label><strong>WhatsApp Text</strong></label>";
    echo "<textarea id='bsbt-wa-text' style='width:100%;min-height:120px;font-size:11px'>".esc_textarea($text)."</textarea>";

    echo "<p style='display:flex;gap:6px;margin-top:6px'>";
    if ($wa) echo "<a class='button button-primary' target='_blank' href='".esc_url($wa)."'>WhatsApp</a>";
    echo "<button type='button' class='button' onclick=\"var t=document.getElementById('bsbt-wa-text');t.select();document.execCommand('copy');\">Copy</button>";
    echo "</p>";

    if (!$decision){
        echo "<p style='font-size:11px;color:#666;margin:8px 0'><em>Nur verwenden, wenn der Eigentümer außerhalb des Portals bestätigt hat.</em></p>";
        echo "<p style='display:flex;gap:6px'>";
        echo "<button class='button button-primary bsbt-confirm' data-id='$bid' data-nonce='$nonce'>Bestätigen</button>";
        echo "<button class='button bsbt-reject' data-id='$bid' data-nonce='$nonce'>Ablehnen</button>";
        echo "</p>";
    } else {
        echo "<div style='margin-top:8px;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;font-size:11px'>
            Aktion gesperrt – Entscheidung bereits getroffen.
        </div>";
    }

    echo "</div>";
    ?>

    <script>
    (function(){
        const ajax = <?php echo json_encode($ajax); ?>;
        document.addEventListener('click',function(e){
            const c=e.target.closest('.bsbt-confirm');
            const r=e.target.closest('.bsbt-reject');
            if(!c&&!r) return;
            if(!confirm('Aktion bestätigen?')) return;
            const b=c||r;
            const d=new URLSearchParams();
            d.append('action',c?'bsbt_confirm_booking':'bsbt_reject_booking');
            d.append('booking_id',b.dataset.id);
            d.append('_wpnonce',b.dataset.nonce);
            fetch(ajax,{
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:d
            }).then(()=>location.reload());
        });
    })();
    </script>

    <?php
}

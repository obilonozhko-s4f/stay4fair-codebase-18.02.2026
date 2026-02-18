<?php
if (!defined('ABSPATH')) exit;

/**
 * Используем официальный API MotoPress для получения ID типа жилья
 */
function bsbt_get_room_type_id_from_booking($booking_id){
    if (function_exists('mphb_get_booking')) {
        $booking = mphb_get_booking($booking_id);
        if ($booking) {
            $reserved_rooms = $booking->getReservedRooms();
            if (!empty($reserved_rooms)) {
                $first_room = reset($reserved_rooms);
                return (int)$first_room->getRoomTypeId();
            }
        }
    }
    return (int)get_post_meta($booking_id, 'mphb_room_type_id', true)
        ?: (int)get_post_meta($booking_id, '_mphb_room_type_id', true);
}

/**
 * ===== WhatsApp text builder — ТВОЙ НОВЫЙ ФОРМАТ =====
 */
function bsbt_build_owner_whatsapp_text($booking_id){

    $type_id = bsbt_get_room_type_id_from_booking($booking_id);

    // Данные владельца и объекта
    if ($type_id > 0) {
        $owner_name = get_post_meta($type_id, 'owner_name', true);
        $address    = get_post_meta($type_id, 'address', true);
        $rate       = (float)get_post_meta($type_id, 'owner_price_per_night', true);
        $title      = get_the_title($type_id);
        $apt_code   = preg_match('/\bID\s*([0-9]+)\b/i', (string)$title, $m) ? 'ID' . $m[1] : '';
    } else {
        $owner_name = get_post_meta($booking_id, 'bsbt_owner_name', true);
        $address    = get_post_meta($booking_id, 'bsbt_apartment_address', true);
        $rate       = (float)get_post_meta($booking_id, 'bsbt_owner_price_per_night', true);
        $apt_code   = get_post_meta($booking_id, 'bsbt_apartment_code', true);
    }

    // Данные брони
    $in  = get_post_meta($booking_id, 'mphb_check_in_date', true)
        ?: get_post_meta($booking_id, '_mphb_check_in_date', true);
    $out = get_post_meta($booking_id, 'mphb_check_out_date', true)
        ?: get_post_meta($booking_id, '_mphb_check_out_date', true);

    $nights = ($in && $out)
        ? (int) round(max(0, strtotime($out) - strtotime($in)) / 86400)
        : 0;

    /**
     * 🔵 НОВОЕ: Snapshot приоритет
     * Если бронь уже подтверждена и snapshot существует —
     * используем замороженную сумму.
     */
    $snapshot_payout = get_post_meta($booking_id, '_bsbt_snapshot_owner_payout', true);

    if ($snapshot_payout !== '') {
        $total = (float)$snapshot_payout;
    } else {
        $total = round($nights * $rate, 2);
    }

    // Страна гостя
    $country = '';
    foreach (['mphb_country','_mphb_country','mphb_billing_country','_mphb_billing_country'] as $k){
        $v = get_post_meta($booking_id, $k, true);
        if ($v) { $country = $v; break; }
    }

    // Формируем сообщение
    $parts = [];
    $parts[] = "*Neue Buchungsanfrage für Sie*";
    $parts[] = "Hallo " . ($owner_name ?: 'Vermieter');

    $apt_info = [];
    if ($apt_code) $apt_info[] = "Apartment: " . $apt_code;
    if ($address)  $apt_info[] = "Adresse: " . $address;
    if (!empty($apt_info)) $parts[] = implode(' | ', $apt_info);

    $booking_info = [];
    $booking_info[] = "Zeitraum: " . ($in ? date('d.m.Y', strtotime($in)) : '—') .
                      " – " . ($out ? date('d.m.Y', strtotime($out)) : '—');
    $booking_info[] = "Nächte: " . $nights;
    $booking_info[] = "Gäste: " . (get_post_meta($booking_id, 'mphb_adults', true) ?: '1');
    if ($country) $booking_info[] = "Land: " . $country;

    $parts[] = implode(' | ', $booking_info);
    $parts[] = "Auszahlung: *" . number_format($total, 2, ',', '.') . " €*";
    $parts[] = "Bitte per WhatsApp Bestätigen/Ablehnen innerhalb von 24 Stunden";
    $parts[] = "— Vielen Dank im Voraus!";
    $parts[] = "Oleksandr, Stay4Fair.com";

    $text = implode(' | ', $parts);
    return apply_filters('bsbt_owner_whatsapp_text', $text, $booking_id);
}

/**
 * ===== WhatsApp Phone Formatter =====
 */
function bsbt_format_phone_for_wa($phone) {
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($phone) > 0 && strpos($phone, '0') === 0) {
        $phone = '49' . substr($phone, 1);
    }
    return $phone;
}

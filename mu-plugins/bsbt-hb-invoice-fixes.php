<?php
/**
 * Plugin Name: BSBT – MPHB Invoices Fixes (Smart Model A/B)
 * Description: Интеллектуальный расчет НДС для инвойсов. Model A = 7% на всё. Model B = 19% только на комиссию сервиса.
 * Author: BS Business Travelling
 * Version: 5.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'BS_EXT_REF_META' ) ) {
    define( 'BS_EXT_REF_META', '_bs_external_reservation_ref' );
}

/* ============================================================
 * 1) Force English
 * ============================================================ */

add_action( 'mphb_invoices_print_pdf_before', function( $booking_id ) {
    // RU: Перед генерацией PDF принудительно переключаем локаль на EN.
    // EN: Force English locale before PDF rendering.
    if ( function_exists( 'switch_to_locale' ) ) {
        switch_to_locale( 'en_US' );
    }
}, 1 );

add_action( 'mphb_invoices_print_pdf_after', function( $booking_id ) {
    // RU: Возвращаем предыдущую локаль после генерации PDF.
    // EN: Restore previous locale after PDF generation.
    if ( function_exists( 'restore_previous_locale' ) ) {
        restore_previous_locale();
    }
}, 99 );

/* ============================================================
 * 2) DOM helpers
 * ============================================================ */

function bsbt_dom_supported(): bool {
    return class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' );
}

function bsbt_dom_load_html( DOMDocument $dom, string $html ): void {
    libxml_use_internal_errors( true );
    @$dom->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
}

function bsbt_dom_body_html( DOMDocument $dom, string $fallback ): string {
    $out = $dom->saveHTML();
    $out = preg_replace( '~^.*?<body>(.*)</body>.*$~is', '$1', (string) $out );
    libxml_clear_errors();
    return $out ?: $fallback;
}

function bsbt_insert_custom_row_before_total( string $html, string $label, string $valueHtml ): string {
    if ( $html === '' || $valueHtml === '' ) return $html;
    if ( ! bsbt_dom_supported() ) return $html;

    $dom = new DOMDocument( '1.0', 'UTF-8' );
    bsbt_dom_load_html( $dom, $html );
    $xpath = new DOMXPath( $dom );

    $targets = $xpath->query(
        "//tr[th and (translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='TOTAL'
        or translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='GESAMT')]"
    );

    if ( $targets && $targets->length > 0 ) {
        $tr = $dom->createElement( 'tr' );
        $th = $dom->createElement( 'th', $label );
        $td = $dom->createElement( 'td' );
        $plain = html_entity_decode( wp_strip_all_tags( $valueHtml ), ENT_QUOTES, 'UTF-8' );
        $td->appendChild( $dom->createTextNode( $plain ) );
        $tr->appendChild( $th );
        $tr->appendChild( $td );
        $targets->item( 0 )->parentNode->insertBefore( $tr, $targets->item( 0 ) );
    }

    return bsbt_dom_body_html( $dom, $html );
}

/* ============================================================
 * 3) Meta Helpers
 * ============================================================ */

function bsbt_meta_first_nonempty( int $post_id, array $keys ): string {
    foreach ( $keys as $k ) {
        $v = get_post_meta( $post_id, $k, true );
        if ( is_scalar( $v ) ) {
            $s = trim( (string) $v );
            if ( $s !== '' ) return $s;
        }
    }
    return '';
}

/* ============================================================
 * 4) Main Filter
 * ============================================================ */

add_filter( 'mphb_invoices_print_pdf_variables', function( array $vars, $booking_id ) {

    $booking_id = (int) $booking_id;
    if ( $booking_id <= 0 ) return $vars;

    /* ======================================================
       SNAPSHOT MODEL PRIORITY (Enterprise Stable)
       ====================================================== */

    // RU: Приоритет — модель из snapshot (замороженная при подтверждении).
    // EN: Snapshot model has priority (frozen at confirmation time).
    $snapshot_model = get_post_meta( $booking_id, '_bsbt_snapshot_model', true );

    // RU: Если snapshot есть — используем его.
    // EN: If snapshot exists — use it.
    $model = $snapshot_model ?: 'model_a';

    // RU: Legacy fallback — только если snapshot отсутствует.
    // EN: Legacy fallback — only if snapshot is missing.
    if ( ! $snapshot_model ) {

        $room_type_id = 0;

        $room_details = get_post_meta( $booking_id, 'mphb_room_details', true );
        if ( is_array( $room_details ) && ! empty( $room_details ) ) {
            $first_room = reset( $room_details );
            $room_type_id = isset( $first_room['room_type_id'] ) ? (int) $first_room['room_type_id'] : 0;
        }

        if ( ! $room_type_id && function_exists( 'MPHB' ) ) {
            try {
                $booking_obj = MPHB()->getBookingRepository()->findById( $booking_id );
                if ( $booking_obj ) {
                    $reserved_rooms = $booking_obj->getReservedRooms();
                    if ( ! empty( $reserved_rooms ) ) {
                        $first_reserved = reset( $reserved_rooms );
                        $room_type_id = $first_reserved->getRoomTypeId();
                    }
                }
            } catch ( \Throwable $e ) { }
        }

        if ( $room_type_id ) {
            $model = get_post_meta( $room_type_id, '_bsbt_business_model', true ) ?: 'model_a';
        }
    }

    /* ======================================================
       Smart VAT Row (based on SNAPSHOT model)
       ====================================================== */

    if ( ! empty( $vars['BOOKING_DETAILS'] ) && function_exists( 'MPHB' ) && function_exists( 'mphb_format_price' ) ) {

        try {
            $booking = MPHB()->getBookingRepository()->findById( $booking_id );
            if ( $booking ) {

                $gross = (float) $booking->getTotalPrice();

                // RU: Расчёт НДС зависит от зафиксированной модели.
                // EN: VAT calculation depends on frozen business model.
                if ( $model === 'model_b' ) {

                    /**
                     * RU: Для Model B НДС = 19% ТОЛЬКО на комиссию.
                     *     Если есть snapshot комиссии — берём точное значение (идеально совпадает везде).
                     *     Если snapshot отсутствует — считаем по формуле из общего Brutto:
                     *     gross * (f*v) / (1 + f + f*v)  (где f=комиссия, v=НДС на комиссию)
                     *
                     * EN: For Model B VAT = 19% ONLY on service fee.
                     *     Prefer snapshot value for perfect alignment.
                     *     Fallback: derive from gross using the same fee-on-owner logic.
                     */
                    $snap_fee_vat = get_post_meta( $booking_id, '_bsbt_snapshot_fee_vat_total', true );

                    if ( $snap_fee_vat !== '' && $snap_fee_vat !== null ) {
                        $vat = round( (float) $snap_fee_vat, 2 );
                    } else {
                        $snap_fee_rate = get_post_meta( $booking_id, '_bsbt_snapshot_fee_rate', true );
                        $f = ( $snap_fee_rate !== '' && $snap_fee_rate !== null ) ? (float) $snap_fee_rate : ( defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15 );
                        $v = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.19;

                        $den = 1 + $f + ( $f * $v );
                        $vat = ( $gross > 0 && $den > 0 ) ? round( $gross * ( $f * $v ) / $den, 2 ) : 0.0;
                    }

                    $label = 'incl. Service Fee VAT (19%)';

                } else {

                    // RU: Model A — 7% включён в общую стоимость.
                    // EN: Model A — 7% VAT included in total.
                    $vat = round( $gross - ( $gross / 1.07 ), 2 );
                    $label = 'VAT (7%) included';
                }

                if ( $vat > 0 ) {
                    $vars['BOOKING_DETAILS'] = bsbt_insert_custom_row_before_total(
                        $vars['BOOKING_DETAILS'],
                        $label,
                        (string) mphb_format_price( $vat )
                    );
                }
            }
        } catch ( \Throwable $e ) { }
    }

    return $vars;

}, 20, 2 );

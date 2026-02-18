<?php
/**
 * Plugin Name: BSBT – Minimum Stay Output
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Шорткод: [bsbt_min_stay] или [bsbt_min_stay id="123"]
 *
 * Берём данные из опции mphb_min_stay_length,
 * где плагин хранит правила "Minimum stay".
 */
add_shortcode( 'bsbt_min_stay', function( $atts ) {

    $atts = shortcode_atts(
        [
            'id' => 0, // можно передать вручную, иначе берём get_the_ID().
        ],
        $atts
    );

    $room_type_id = intval( $atts['id'] );
    if ( ! $room_type_id ) {
        $room_type_id = get_the_ID();
    }

    if ( ! $room_type_id ) {
        return '';
    }

    // Правила минимального проживания (то, что ты видишь в Bookings → Booking Rules → Minimum stay).
    $rules = get_option( 'mphb_min_stay_length', [] );

    if ( empty( $rules ) || ! is_array( $rules ) ) {
        return '';
    }

    $min_nights = 0;

    /**
     * Логика:
     * - проходим по всем правилам;
     * - если правило глобальное (room_type_ids пустой или содержит 0) — оно подходит всем;
     * - если есть room_type_ids и в списке есть текущий $room_type_id — правило подходит;
     * - берём последнее подходящее правило как самое "сильное".
     */
    foreach ( $rules as $rule ) {

        if ( empty( $rule['min_stay_length'] ) ) {
            continue;
        }

        $rule_min = (int) $rule['min_stay_length'];

        // Массив ID типов размещения, на которые действует правило
        $room_ids = [];

        if ( isset( $rule['room_type_ids'] ) ) {

            if ( is_array( $rule['room_type_ids'] ) ) {
                $room_ids = $rule['room_type_ids'];
            } else {
                // На всякий случай, если вдруг строка
                $room_ids = array_map( 'intval', (array) $rule['room_type_ids'] );
            }
        }

        $room_ids = array_map( 'intval', $room_ids );

        $is_global = empty( $room_ids ) || in_array( 0, $room_ids, true );
        $is_for_this_room = in_array( $room_type_id, $room_ids, true );

        if ( $is_global || $is_for_this_room ) {
            // Это правило подходит — считаем его последним актуальным
            $min_nights = $rule_min;
            // Если хочешь брать первое найденное, можно поставить здесь break;
        }
    }

    if ( $min_nights < 1 ) {
        return '';
    }

    // Текст вывода (пока по-английски, позже можем сделать перевод/двуязычие)
    if ( $min_nights === 1 ) {
        $label = 'Minimum stay: 1 night';
    } else {
        $label = 'Minimum stay: ' . $min_nights . ' nights';
    }

    return '<div class="bsbt-min-stay">' . esc_html( $label ) . '</div>';
} );

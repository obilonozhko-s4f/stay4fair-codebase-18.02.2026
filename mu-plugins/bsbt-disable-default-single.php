<?php
/**
 * BSBT – Clean up default MPHB single layout for Elementor
 */

if ( ! defined('ABSPATH') ) exit;

// Отключаем только мета-блоки (галерея, детали, цена, календарь, форма)
add_action( 'wp', function () {

    if ( ! is_singular( 'mphb_room_type' ) ) {
        return;
    }

    // Галерея
    remove_action(
        'mphb_render_single_room_type_metas',
        array( '\MPHB\Views\SingleRoomTypeView', 'renderGallery' ),
        10
    );

    // Атрибуты (Details)
    remove_action(
        'mphb_render_single_room_type_metas',
        array( '\MPHB\Views\SingleRoomTypeView', 'renderAttributes' ),
        20
    );

    // Цена (Default / For Dates)
    remove_action(
        'mphb_render_single_room_type_metas',
        array( '\MPHB\Views\SingleRoomTypeView', 'renderDefaultOrForDatesPrice' ),
        30
    );

    // Календарь
    remove_action(
        'mphb_render_single_room_type_metas',
        array( '\MPHB\Views\SingleRoomTypeView', 'renderCalendar' ),
        40
    );

    // Форма бронирования
    remove_action(
        'mphb_render_single_room_type_metas',
        array( '\MPHB\Views\SingleRoomTypeView', 'renderReservationForm' ),
        50
    );
});

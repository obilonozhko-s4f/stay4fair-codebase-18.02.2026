<?php
/**
 * BSBT – Hide Gutenberg content on all MPHB Accommodation (Room Type) single pages
 */

if ( ! defined('ABSPATH') ) exit;

// 1) Полностью глушим the_content на single-квартирах
add_filter('the_content', function($content) {

    // Только для Single Accommodation Type
    if (is_singular('mphb_room_type')) {
        return ''; // НЕ выводить Gutenberg-контент вообще
    }

    return $content;
});

// 2) Шорткод [bsbt_room_description] – выводит описание квартиры из backend'а
add_shortcode('bsbt_room_description', function( $atts = array(), $content = '' ) {

    if ( ! is_singular('mphb_room_type') ) {
        return '';
    }

    global $post;
    if ( ! $post ) {
        return '';
    }

    $text = $post->post_content;
    if ( ! trim( $text ) ) {
        return '';
    }

    // Абзацы + вложенные шорткоды
    $text = wpautop( $text );
    $text = do_shortcode( $text );

    return '<div class="bsbt-room-description">' . $text . '</div>';
});

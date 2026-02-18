<?php
/**
 * Plugin Name: BSBT – Room Permalink Shortcode
 * Description: Шорткод [bsbt_room_permalink] возвращает корректный URL объекта (Room Type).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'bsbt_room_permalink', function() {
	global $post;
	if ( ! $post || $post->post_type !== 'mphb_room_type' ) return home_url('/');
	return get_permalink( $post->ID );
});

<?php
if (!defined('ABSPATH')) exit;

/**
 * При создании брони переносим данные владельца и apartment_code из объекта (mphb_room).
 * Закупочная цена остаётся приватной (только в meta).
 */
add_action('mphb_new_booking', function($booking_id){
	$room_id = get_post_meta($booking_id, 'mphb_room_id', true);
	if (!$room_id) return;

	$owner_price = get_post_meta($room_id, BSBT_META_OWNER_PRICE_N, true);
	$owner_name  = get_post_meta($room_id, BSBT_META_OWNER_NAME,  true);
	$owner_phone = get_post_meta($room_id, BSBT_META_OWNER_PHONE, true);
	$owner_email = get_post_meta($room_id, BSBT_META_OWNER_EMAIL, true);
	$address     = get_post_meta($room_id, BSBT_META_APT_ADDRESS, true);
	$apt_code    = get_post_meta($room_id, BSBT_META_APARTMENT_CODE, true);

	update_post_meta($booking_id, BSBT_BMETA_OWNER_PRICE_N,  $owner_price);
	update_post_meta($booking_id, BSBT_BMETA_OWNER_NAME,     $owner_name);
	update_post_meta($booking_id, BSBT_BMETA_OWNER_PHONE,    $owner_phone);
	update_post_meta($booking_id, BSBT_BMETA_OWNER_EMAIL,    $owner_email);
	update_post_meta($booking_id, BSBT_BMETA_APT_ADDRESS,    $address);
	update_post_meta($booking_id, BSBT_BMETA_APARTMENT_CODE, $apt_code);
}, 10);

<?php
/**
 * Plugin Name: BSBT – Core Image Optimizer (Server-side + WebP)
 * Description: Unlimited local image optimization for WordPress: resize, JPEG quality, native WebP.
 * Author: BS Business Travelling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1️⃣ Максимальный размер изображения
 */
add_filter( 'big_image_size_threshold', function () {
	return 1920; // px
} );

/**
 * 2️⃣ Качество JPEG
 */
add_filter( 'jpeg_quality', function () {
	return 82;
} );
add_filter( 'wp_editor_set_quality', function () {
	return 82;
} );

/**
 * 3️⃣ Включаем НАТИВНЫЙ WebP (без облаков)
 * WordPress сам создаёт WebP-версии и отдаёт их браузеру
 */
add_filter(
	'wp_image_editors',
	function ( $editors ) {
		return $editors; // Imagick / GD
	}
);

add_filter(
	'image_editor_output_format',
	function ( $formats ) {
		$formats['image/jpeg'] = 'image/webp';
		return $formats;
	}
);

/**
 * 4️⃣ Не трогаем PNG / SVG (важно для логотипов)
 */
add_filter(
	'image_editor_output_format',
	function ( $formats ) {
		unset( $formats['image/png'] );
		return $formats;
	},
	20
);

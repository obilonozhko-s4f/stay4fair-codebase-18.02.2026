<?php
/**
 * Plugin Name: BSBT – Fix Loop Grid Empty Item
 * Description: Удаляет шаблонную карточку (e-loop-item--template) Elementor Loop Grid на фронте.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Убираем template-превью у Loop Grid на фронте
add_action( 'wp_enqueue_scripts', function() {
	wp_add_inline_style(
		'elementor-frontend',
		'.e-loop-item--template{display:none!important;visibility:hidden!important;}'
	);
}, 99);

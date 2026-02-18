<?php
/**
 * Plugin Name: BSBT – Color Override (Brand Update)
 * Description: Automatically replaces old brand blue #212F54 with new #082567 in all inline and dynamic plugin styles.
 */

if (!defined('ABSPATH')) exit;

/**
 * Replace all occurrences of the old brand color in generated CSS.
 */
function bsbt_filter_color_output($css) {
    if (empty($css)) return $css;

    // Replace old → new
    $css = str_replace('#212F54', '#082567', $css);
    $css = str_replace('#212f54', '#082567', $css);

    return $css;
}

/**
 * Apply replacement to Elementor, plugins, and theme outputs.
 */
add_filter('elementor/frontend/the_content', 'bsbt_filter_color_output', 999);
add_filter('elementor/css/file/post', 'bsbt_filter_color_output', 999);
add_filter('elementor/css/print_css', 'bsbt_filter_color_output', 999);
add_filter('wp_add_inline_style', 'bsbt_filter_color_output', 999);
add_filter('wp_head', 'bsbt_filter_color_output', 999);
add_filter('wp_footer', 'bsbt_filter_color_output', 999);

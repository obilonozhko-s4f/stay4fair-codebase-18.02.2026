<?php
if (!defined('ABSPATH')) exit;

/** Метабокс на объекте (mphb_room): Apartment Code (внутренняя ID квартиры). */
add_action('add_meta_boxes', function(){
	add_meta_box(
		'bsbt_apartment_code_box',
		__('Apartment Code (interne ID)', 'bsbt'),
		function($post){
			$val = get_post_meta($post->ID, BSBT_META_APARTMENT_CODE, true);
			echo '<input type="text" name="'.esc_attr(BSBT_META_APARTMENT_CODE).'" value="'.esc_attr($val).'" style="width:100%">';
			echo '<p class="description">Eigener, stabiler Code (z. B. APT-102, KR-12). Wird in Buchung kopiert.</p>';
			wp_nonce_field('bsbt_apartment_code_'.$post->ID, 'bsbt_apartment_code_nonce');
		},
		'mphb_room', // при другом CPT замените
		'side',
		'default'
	);
});

add_action('save_post_mphb_room', function($post_id){
	if (!isset($_POST['bsbt_apartment_code_nonce']) || !wp_verify_nonce($_POST['bsbt_apartment_code_nonce'], 'bsbt_apartment_code_'.$post_id)) return;
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
	if (!current_user_can('edit_post', $post_id)) return;

	$val = isset($_POST[BSBT_META_APARTMENT_CODE]) ? sanitize_text_field($_POST[BSBT_META_APARTMENT_CODE]) : '';
	update_post_meta($post_id, BSBT_META_APARTMENT_CODE, $val);
}, 10, 1);

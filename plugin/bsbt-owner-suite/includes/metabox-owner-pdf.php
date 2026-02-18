<?php
if (!defined('ABSPATH')) exit;

/** Метабокс: PDF для админки и e-mail; WhatsApp — ТОЛЬКО текст. */
add_action('add_meta_boxes', function(){
	add_meta_box(
		'bsbt_owner_pdf_box',
		__('Besitzer Rechnung', 'bsbt'),
		'bsbt_render_owner_pdf_box',
		'mphb_booking',
		'side',
		'high'
	);
});

function bsbt_render_owner_pdf_box($post){
	// важное: nonce один и тот же для всех действий
	$nonce = wp_create_nonce('bsbt_owner_pdf_'.$post->ID);
	$base  = admin_url('admin-post.php');

	$actions = [
		['label'=>'Öffnen (PDF)',           'act'=>'bsbt_owner_pdf_open',      'newtab'=>true],
		['label'=>'Download (PDF)',         'act'=>'bsbt_owner_pdf_download',  'newtab'=>false],
		['label'=>'Per E-Mail senden (PDF)','act'=>'bsbt_owner_pdf_email',     'newtab'=>false],
		['label'=>'WhatsApp (Anfrage)',        'act'=>'bsbt_owner_msg_whatsapp',  'newtab'=>true], // только текст!
	];

	echo '<div style="display:flex;flex-direction:column;gap:8px;">';
	foreach($actions as $a){
		$url = add_query_arg([
			'action'     => $a['act'],
			'booking_id' => $post->ID,
			'nonce'      => $nonce
		], $base);
		$target = $a['newtab'] ? ' target="_blank"' : '';
		echo '<a class="button button-primary" style="width:100%;text-align:center" href="'.esc_url($url).'"'.$target.'>'.esc_html($a['label']).'</a>';
	}
	echo '</div>';
	echo '<p style="margin-top:8px;color:#666;font-size:12px">PDF bleibt für Admin & E-Mail. WhatsApp sendet nur Text.</p>';
}

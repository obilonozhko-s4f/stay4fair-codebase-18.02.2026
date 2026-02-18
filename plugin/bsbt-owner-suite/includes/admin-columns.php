<?php
if (!defined('ABSPATH')) exit;

/**
 * Колонки в списке брони:
 *  - Einkauf (Gesamt) — snapshot payout (если есть) ИЛИ owner_price_per_night × Nächte
 *  - Verkauf (Gesamt) — рассчитывается по marketplace формуле
 *  - MwSt 7%/19%      — рассчитывается по marketplace формуле
 * Внизу — сумма по видимым строкам.
 */

/* =========================================================
 * UTILS
 * ======================================================= */

function bsbt__get_dates_raw($booking_id){
	$in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
	$out = get_post_meta($booking_id, 'mphb_check_out_date', true);
	if (!$in)  $in  = get_post_meta($booking_id, '_mphb_check_in_date', true);
	if (!$out) $out = get_post_meta($booking_id, '_mphb_check_out_date', true);
	return [$in, $out];
}

function bsbt__nights($in, $out){
	$ti = strtotime($in);
	$to = strtotime($out);
	if (!$ti || !$to) return 0;
	return (int) round(max(0, $to - $ti) / DAY_IN_SECONDS);
}

/* =========================================================
 * ROOM TYPE DISCOVERY
 * ======================================================= */

function bsbt__discover_room_type($booking_id){

	if (function_exists('mphb_get_booking')){
		$bk = mphb_get_booking((int)$booking_id);
		if ($bk && method_exists($bk,'getReservedRooms')){
			$rooms = (array) $bk->getReservedRooms();
			if ($rooms){
				$first = reset($rooms);
				if (is_object($first) && method_exists($first,'getRoomTypeId')){
					$t=(int)$first->getRoomTypeId();
					if ($t>0) return $t;
				}
			}
		}
	}

	return 0;
}

/* =========================================================
 * MODEL RESOLVER (SNAPSHOT FIRST)
 * ======================================================= */

function bsbt__get_model_for_booking($booking_id): string {

	$booking_id = (int) $booking_id;
	if ($booking_id <= 0) return 'model_a';

	$snapshot_model = (string) get_post_meta($booking_id, '_bsbt_snapshot_model', true);
	$snapshot_model = trim($snapshot_model);
	if ($snapshot_model !== '') return $snapshot_model;

	$rt = bsbt__discover_room_type($booking_id);
	if ($rt > 0) {
		$m = (string) get_post_meta($rt, '_bsbt_business_model', true);
		$m = trim($m);
		if ($m !== '') return $m;
	}

	return 'model_a';
}

/* =========================================================
 * ADD COLUMNS
 * ======================================================= */

function bsbt_add_fin_columns($cols){

	$cols['bsbt_purchase_total'] = __('Einkauf (Gesamt)', 'bsbt');
	$cols['bsbt_guest_total']    = __('Verkauf (Gesamt)', 'bsbt');
	$cols['bsbt_vat_7']          = __('MwSt 7%/19%', 'bsbt');

	return $cols;
}

add_filter('manage_mphb_booking_posts_columns', 'bsbt_add_fin_columns', 20);
add_action('manage_mphb_booking_posts_custom_column','bsbt_render_fin_columns', 10, 2);

/* =========================================================
 * RENDER
 * ======================================================= */

function bsbt_render_fin_columns($col, $post_id){

	/* =====================================
	   Einkauf (Owner Net)
	===================================== */

	if ($col === 'bsbt_purchase_total'){

		$snapshot = get_post_meta($post_id, '_bsbt_snapshot_owner_payout', true);

		if ($snapshot !== '' && $snapshot !== null && (float)$snapshot > 0) {
			$owner_net = (float) $snapshot;
		} else {

			list($in,$out) = bsbt__get_dates_raw($post_id);
			$nights = bsbt__nights($in,$out);

			$typeid = bsbt__discover_room_type($post_id);
			$ppn = 0.0;

			if ($typeid > 0) {
				$r = get_post_meta($typeid, 'owner_price_per_night', true);
				if ($r !== '' && $r !== null) $ppn = (float) $r;
			}

			$owner_net = round(max(0,$nights) * max(0,$ppn), 2);
		}

		echo '<span class="bsbt-sum bsbt-purchase" data-val="'.esc_attr($owner_net).'">'.
		     esc_html(number_format_i18n($owner_net, 2)).' €</span>';
		return;
	}

	/* =====================================
	   Verkauf & VAT — MARKETPLACE LOGIC
	===================================== */

	if ($col === 'bsbt_guest_total' || $col === 'bsbt_vat_7'){

		$model = bsbt__get_model_for_booking($post_id);

		list($in,$out) = bsbt__get_dates_raw($post_id);
		$nights = bsbt__nights($in,$out);

		$typeid = bsbt__discover_room_type($post_id);
		$ppn = 0.0;

		if ($typeid > 0) {
			$r = get_post_meta($typeid, 'owner_price_per_night', true);
			if ($r !== '' && $r !== null) $ppn = (float) $r;
		}

		$owner_net = round(max(0,$nights) * max(0,$ppn), 2);

		/* =====================
		   MODEL A
		===================== */

		if ($model === 'model_a') {

			$gross = $owner_net;
			$vat   = ($gross > 0) ? round($gross - ($gross / 1.07), 2) : 0.00;
		}

		/* =====================
		   MODEL B
		===================== */

		else {

			$fee_rate = get_post_meta($post_id, '_bsbt_snapshot_fee_rate', true);
			if ($fee_rate === '' || $fee_rate === null) {
				$fee_rate = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15;
			}

			$fee_rate = (float) $fee_rate;
			$vat_rate = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.19;

			$fee = round($owner_net * $fee_rate, 2);
			$vat = round($fee * $vat_rate, 2);
			$gross = round($owner_net + $fee + $vat, 2);
		}

		if ($col === 'bsbt_guest_total') {
			echo '<span class="bsbt-sum bsbt-guest" data-val="'.esc_attr($gross).'">'.
			     esc_html(number_format_i18n($gross, 2)).' €</span>';
			return;
		}

		if ($col === 'bsbt_vat_7') {
			echo '<span class="bsbt-sum bsbt-vat" data-val="'.esc_attr($vat).'">'.
			     esc_html(number_format_i18n($vat, 2)).' €</span>';
			return;
		}
	}
}

/* =========================================================
 * FOOTER TOTALS
 * ======================================================= */

function bsbt_fin_totals_footer(){

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->id !== 'edit-mphb_booking') return;
	?>

	<script>
	(function(){
		const table = document.querySelector('table.wp-list-table.posts');
		if(!table) return;

		let p=0,g=0,v=0;

		table.querySelectorAll('span.bsbt-purchase').forEach(el => p += parseFloat(el.dataset.val||0));
		table.querySelectorAll('span.bsbt-guest').forEach(el => g += parseFloat(el.dataset.val||0));
		table.querySelectorAll('span.bsbt-vat').forEach(el => v += parseFloat(el.dataset.val||0));

		const tfoot = table.querySelector('tfoot') || table.createTFoot();
		const tr = document.createElement('tr');

		tr.innerHTML = `
			<td colspan="3" style="text-align:right;font-weight:600">SUMMEN (sichtbar):</td>
			<td style="font-weight:600">Einkauf (Gesamt): ${p.toLocaleString(undefined,{minimumFractionDigits:2})} €</td>
			<td style="font-weight:600">Verkauf (Gesamt): ${g.toLocaleString(undefined,{minimumFractionDigits:2})} €</td>
			<td style="font-weight:600">MwSt: ${v.toLocaleString(undefined,{minimumFractionDigits:2})} €</td>
		`;

		tfoot.appendChild(tr);

	})();
	</script>

	<?php
}

add_action('admin_footer-edit.php','bsbt_fin_totals_footer');

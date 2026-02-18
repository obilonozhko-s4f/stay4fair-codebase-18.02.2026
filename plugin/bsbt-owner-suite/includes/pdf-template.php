<?php
if (!defined('ABSPATH')) exit;

/**
 * BSBT Owner Suite — Besitzer Rechnung / Bestätigung
 * Показываем владельцу ТОЛЬКО суммы его выплаты (без Verkauf/MwSt).
 * Wohnungs-ID вытягиваем ИМЕННО из названия Unterkunftsart (RoomType): токен вида "ID1234".
 */

/** Универсальный поиск внешнего номера брони и отображаемого номера */
if (!function_exists('bsbt_get_external_booking_id')) {
	function bsbt_get_external_booking_id($booking_id){
		// базовый ключ из наших сниппетов
		$keys = [];
		if (defined('BS_EXT_REF_META')) {
			$keys[] = BS_EXT_REF_META; // обычно '_bs_external_reservation_ref'
		} else {
			$keys[] = '_bs_external_reservation_ref';
		}
		// запасные варианты (если когда-то меняли ключ)
		$keys = array_merge($keys, [
			'bsbt_external_booking_id',
			'bsbt_external_booking_no',
			'external_booking_id',
			'external_booking_number',
			'bsbt_external_id',
		]);

		foreach ($keys as $k){
			$val = trim((string) get_post_meta($booking_id, $k, true));
			if ($val !== '') return $val;
		}
		return '';
	}
}
if (!function_exists('bsbt_get_display_booking_ref')) {
	function bsbt_get_display_booking_ref($booking_id){
		$ext = bsbt_get_external_booking_id($booking_id);
		return ($ext !== '') ? $ext : (string) (int) $booking_id;
	}
}

/** Надёжный поиск RoomType для брони (без внешних зависимостей) */
if (!function_exists('bsbt_pdf_find_room_type_id')) {
	function bsbt_pdf_find_room_type_id($booking_id){
		$booking_id = (int) $booking_id;

		// 1) прямые/кастомные мета
		foreach (['bsbt_room_type_id','mphb_room_type_id','_mphb_room_type_id'] as $k){
			$t = (int) get_post_meta($booking_id, $k, true);
			if ($t > 0) return $t;
		}

		// 2) reserved_rooms
		$reserved = get_post_meta($booking_id, 'mphb_reserved_rooms', true);
		$try = function($val){
			if (is_array($val) && isset($val[0]) && is_array($val[0])){
				if (!empty($val[0]['room_type_id'])) return (int)$val[0]['room_type_id'];
				if (!empty($val[0]['room_id'])) {
					$room_id = (int)$val[0]['room_id'];
					if ($room_id > 0) return (int) get_post_meta($room_id, 'mphb_room_type_id', true);
				}
			}
			if (is_array($val) && !empty($val) && !isset($val[0]['room_type_id'])){
				$rr = (int) reset($val);
				if ($rr > 0){
					$t = (int) get_post_meta($rr, 'mphb_room_type_id', true);
					if ($t > 0) return $t;
					$room_id = (int) get_post_meta($rr, 'mphb_room_id', true);
					if ($room_id > 0) return (int) get_post_meta($room_id, 'mphb_room_type_id', true);
				}
			}
			return 0;
		};
		if (!empty($reserved)) {
			$t = $try($reserved); if ($t > 0) return $t;
			if (is_string($reserved)){
				$maybe = @maybe_unserialize($reserved);
				if ($maybe && $maybe !== $reserved){ $t = $try($maybe); if ($t > 0) return $t; }
				$j = json_decode($reserved, true);
				if (json_last_error() === JSON_ERROR_NONE && $j){ $t = $try($j); if ($t > 0) return $t; }
			}
		}

		// 3) API MotoPress
		if (function_exists('mphb_get_booking')){
			$bk = mphb_get_booking($booking_id);
			if ($bk){
				foreach (['getRoomTypeId','get_room_type_id'] as $m){
					if (method_exists($bk,$m)){
						$t = (int) $bk->$m();
						if ($t > 0) return $t;
					}
				}
				if (method_exists($bk,'getReservedRooms')){
					$rooms = (array) $bk->getReservedRooms();
					if ($rooms){
						$first = reset($rooms);
						if (is_object($first)){
							foreach (['getRoomTypeId','get_room_type_id'] as $m){
								if (method_exists($first,$m)){ $t=(int)$first->$m(); if ($t>0) return $t; }
							}
							foreach (['getRoomId','get_room_id'] as $m){
								if (method_exists($first,$m)){
									$room_id = (int) $first->$m();
									if ($room_id > 0){
										$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
										if ($t > 0) return $t;
									}
								}
							}
						}
					}
				}
				if (method_exists($bk,'getReservedRoomsIds')){
					$ids = (array) $bk->getReservedRoomsIds();
					if ($ids){
						$rr = (int) reset($ids);
						if ($rr > 0){
							$t = (int) get_post_meta($rr, 'mphb_room_type_id', true);
							if ($t > 0) return $t;
						}
					}
				}
			}
		}

		// 4) WP_Query по reserved_room
		$q = new WP_Query([
			'post_type'      => 'mphb_reserved_room',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [[ 'key'=>'mphb_booking_id', 'value'=>$booking_id ]]
		]);
		if ($q->have_posts()){
			$rr = (int) $q->posts[0];
			$t  = (int) get_post_meta($rr, 'mphb_room_type_id', true);
			if ($t > 0) return $t;
			$room_id = (int) get_post_meta($rr, 'mphb_room_id', true);
			if ($room_id > 0){
				$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
				if ($t > 0) return $t;
			}
		}

		// 5) room_id на самой брони
		foreach (['mphb_room_id','_mphb_room_id','mphb_room','_mphb_room'] as $k){
			$room_id = (int) get_post_meta($booking_id, $k, true);
			if ($room_id > 0){
				$t = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
				if ($t > 0) return $t;
			}
		}
		return 0;
	}
}

/** Основной рендер PDF */
if (!function_exists('bsbt_owner_pdf_html')) {
	function bsbt_owner_pdf_html($booking_id){
		$display_ref = bsbt_get_display_booking_ref($booking_id);

		// Даты/гости
		$check_in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
		$check_out = get_post_meta($booking_id, '_mphb_check_out_date', true) ?: get_post_meta($booking_id, 'mphb_check_out_date', true);
		if (!$check_in)  $check_in  = get_post_meta($booking_id, '_mphb_check_in_date', true);

		$in_ts  = strtotime($check_in);
		$out_ts = strtotime($check_out);
		$nights = ($in_ts && $out_ts) ? (int) round(max(0, $out_ts - $in_ts) / DAY_IN_SECONDS) : 0;

		$guests_cnt = get_post_meta($booking_id, 'mphb_adults', true);
		if ($guests_cnt === '' || $guests_cnt === null) $guests_cnt = get_post_meta($booking_id, '_mphb_adults', true);
		$guests_cnt = (int) ($guests_cnt ?: 1);

		$guest_first = get_post_meta($booking_id, 'mphb_first_name', true);
		$guest_last  = get_post_meta($booking_id, 'mphb_last_name', true);
		$guest_phone = get_post_meta($booking_id, 'mphb_phone', true);
		$guest_addr  = get_post_meta($booking_id, 'mphb_address1', true);
		if (!$guest_first) $guest_first = get_post_meta($booking_id, '_mphb_first_name', true);
		if (!$guest_last)  $guest_last  = get_post_meta($booking_id, '_mphb_last_name', true);
		if (!$guest_phone) $guest_phone = get_post_meta($booking_id, '_mphb_phone', true);
		if (!$guest_addr)  $guest_addr  = get_post_meta($booking_id, '_mphb_address1', true);
		$guest_name = trim(($guest_first ?: '').' '.($guest_last ?: ''));

		// RoomType + owner-мета
		$type_id    = bsbt_pdf_find_room_type_id($booking_id);
		$owner_name = $type_id ? get_post_meta($type_id, 'owner_name', true) : '';
		$address    = $type_id ? get_post_meta($type_id, 'address', true) : '';
		$rate_raw   = $type_id ? get_post_meta($type_id, 'owner_price_per_night', true) : '';
		$rate       = ($rate_raw!=='' && $rate_raw!==null) ? (float)$rate_raw : 0.0;

		// Кеш ставки
		update_post_meta($booking_id, 'bsbt_owner_price_per_night', $rate);

		$owner_total = round(max(0,$nights) * max(0,$rate), 2);

		// Wohnungs-ID из названия RoomType (токен "ID1234")
		$apt_code = '';
		if ($type_id > 0) {
			$title = get_the_title($type_id);
			if (preg_match('/\bID\s*([0-9]+)\b/i', (string)$title, $m)) {
				$apt_code = 'ID' . $m[1];
			}
		}

		$site_name = 'Stay4Fair.com';

		ob_start(); ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Besitzer Rechnung – Buchung #<?php echo esc_html($display_ref); ?></title>
<style>
	body { font-family: DejaVu Sans, Arial, sans-serif; color:#111; font-size:13px; line-height:1.5; }
	.header { margin-bottom:16px; }
	.section { margin:12px 0 16px; padding:12px; border:1px solid #e7e7e7; border-radius:8px; }
	.h1 { font-size:18px; font-weight:700; margin:0 0 6px; }
	.h2 { font-size:15px; font-weight:700; margin:0 0 8px; }
	.grid{ display:flex; gap:16px; flex-wrap:wrap; }
	.col { flex:1 1 280px; }
	.kv  { margin:0; }
	.kv dt{ font-weight:600; }
	.kv dd{ margin:0 0 6px; }
	.hr  { height:1px; background:#eee; margin:12px 0; }
	.footer { margin-top:18px; font-size:11px; color:#555; }
	.badge{ display:inline-block; padding:4px 8px; border-radius:999px; background:#111; color:#fff; font-size:11px; text-transform:uppercase; letter-spacing:.04em;}
	.table{ width:100%; border-collapse:collapse; }
	.table th,.table td{ border:1px solid #e7e7e7; padding:8px; text-align:left; }
	.right{ text-align:right; }
	.mono{ font-family: "DejaVu Sans Mono", monospace; }
</style>
</head>
<body>

<div class="header">
	<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
		<div>
			<div class="h1">Besitzer Rechnung / Bestätigung</div>
			<div>#<?php echo esc_html($display_ref); ?> · <?php echo esc_html($site_name); ?></div>
		</div>
		<div><span class="badge">VERBINDLICHE BUCHUNG</span></div>
	</div>
</div>

<div class="section">
	<div class="h2">Vermieter (Kontaktdaten)</div>
	<dl class="kv">
		<dt>Name</dt><dd><?php echo esc_html($owner_name); ?></dd>
		<dt>Adresse</dt><dd><?php echo esc_html($address); ?></dd>
	</dl>
</div>

<div class="grid">
	<div class="section col">
		<div class="h2">Unterkunft</div>
		<dl class="kv">
			<dt>Wohnungs-ID</dt><dd class="mono"><?php echo esc_html($apt_code ?: '—'); ?></dd>
			<dt>Adresse</dt><dd><?php echo esc_html($address); ?></dd>
		</dl>
	</div>
	<div class="section col">
		<div class="h2">Gast & Zeitraum</div>
		<dl class="kv">
			<dt>Gast</dt><dd><?php echo esc_html($guest_name ?: '—'); ?></dd>
			<dt>Telefon</dt><dd><?php echo esc_html($guest_phone ?: '—'); ?></dd>
			<?php if (!empty($guest_addr)): ?>
			<dt>Adresse (Gast)</dt><dd><?php echo esc_html($guest_addr); ?></dd>
			<?php endif; ?>
			<dt>Anreise – Abreise</dt><dd><?php echo esc_html($check_in ?: '—'); ?> – <?php echo esc_html($check_out ?: '—'); ?></dd>
			<dt>Nächte / Gäste</dt><dd><?php echo (int)$nights; ?> / <?php echo (int)$guests_cnt; ?></dd>
		</dl>
	</div>
</div>

<div class="section">
	<div class="h2">Leistungen & Auszahlung an den Vermieter</div>
	<table class="table">
		<thead>
			<tr><th>Position</th><th class="right">Betrag</th></tr>
		</thead>
		<tbody>
			<tr>
				<td>Wohnung (Auszahlung, <?php echo (int)$nights; ?> Nächte)</td>
				<td class="right"><?php echo number_format($owner_total,2,',','.'); ?> €</td>
			</tr>
			<tr>
				<td><strong>Auszahlung an den Vermieter (Gesamt)</strong></td>
				<td class="right"><strong><?php echo number_format($owner_total,2,',','.'); ?> €</strong></td>
			</tr>
		</tbody>
	</table>

	<p style="margin:8px 0 0;">
		Vermieter: <?php echo esc_html($owner_name); ?> · Vermittelt durch: Bilonozhko Oleksandr, Stay4Fair.com
	</p>
</div>

<div class="section">
	<div class="h2">Auszahlung & Hinweise</div>
	<p>
		Bei erfolgreicher Belegung zahlt Stay4Fair.com den oben genannten Betrag innerhalb von 30 Tage nach Abreise
		auf das vom Vermieter angegebene Konto aus oder leistet Barzahlung. Bei kurzfristiger Beherbergung in Privatunterkünften
		handelt es sich um steuerpflichtige Einkünfte. Der Vermieter regelt seine steuerlichen Pflichten eigenverantwortlich.
	</p>
	<div class="hr"></div>
	<p style="margin:0;">
		<strong>Verbindlichkeit & Absagen (Kurzfassung):</strong><br>
		Nach Bestätigung gilt die Unterkunft als verbindlich reserviert. Eine Absage durch den Vermieter ist nur in begründeten
		Ausnahmefällen (z.B. höhere Gewalt, unbewohnbare Wohnung durch Wasser-/Brandschaden, plötzliche Krankheit bei Eigenbelegung) zulässig.
		Bei Absagen ohne triftigen Grund oder wiederholt kurzfristigen Absagen behält sich Stay4Fair.com vor,
		den Vermieter vorübergehend oder dauerhaft aus der Vermittlungsdatenbank zu entfernen, entstandene Kosten anteilig in Rechnung zu stellen
		und künftige Kooperationen nur unter verschärften Bedingungen (z. B. Kaution/Vorausbestätigung) fortzuführen.
	</p>
</div>

<p>Mit freundlichen Grüßen<br>O. Bilonozhko<br>Stay4Fair.com</p>

<div class="footer">
	<em>WhatsApp: +4917624615269 · business@stay4fair.com · stay4fair.com</em>
</div>

</body>
</html>
<?php
		return ob_get_clean();
	}
}

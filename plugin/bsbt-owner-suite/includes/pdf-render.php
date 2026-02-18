<?php
if (!defined('ABSPATH')) exit;

/**
 * PDF render & actions + WhatsApp TEXT ONLY
 * - Open/Download/Email = PDF
 * - WhatsApp = text (без PDF/ссылок)
 * Safe: function_exists/has_action guards
 */

/** Универсальный поиск внешнего номера и отображаемого номера */
if (!function_exists('bsbt_get_external_booking_id')) {
	function bsbt_get_external_booking_id($booking_id){
		$keys = [];
		if (defined('BS_EXT_REF_META')) {
			$keys[] = BS_EXT_REF_META;
		} else {
			$keys[] = '_bs_external_reservation_ref';
		}
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
		return ($ext !== '') ? $ext : (string) (int)$booking_id;
	}
}

/** PDF engine resolver */
if (!function_exists('bsbt_pdf_engine')) {
	function bsbt_pdf_engine() {
		if (class_exists('\\Dompdf\\Dompdf')) return 'dompdf';
		if (class_exists('\\Mpdf\\Mpdf'))     return 'mpdf';

		$vendor = WP_CONTENT_DIR . '/vendor/autoload.php';
		if (file_exists($vendor)) {
			require_once $vendor;
			if (class_exists('\\Dompdf\\Dompdf')) return 'dompdf';
			if (class_exists('\\Mpdf\\Mpdf'))     return 'mpdf';
		}
		return null;
	}
}

/** Build and stream PDF */
if (!function_exists('bsbt_stream_pdf')) {
	function bsbt_stream_pdf($booking_id, $disposition='inline'){
		if (!function_exists('bsbt_owner_pdf_html')) {
			wp_die('PDF template not found.');
		}
		$html     = bsbt_owner_pdf_html($booking_id);
		$engine   = bsbt_pdf_engine();
		$ref      = bsbt_get_display_booking_ref($booking_id);
		$filename = 'Besitzer-Rechnung-'.$ref.'.pdf';

		if ($engine === 'dompdf'){
			$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
			$dompdf->loadHtml($html, 'UTF-8');
			$dompdf->setPaper('A4', 'portrait');
			$dompdf->render();
			$dompdf->stream($filename, ['Attachment' => ($disposition==='attachment') ? 1 : 0 ]);
			exit;
		}
		if ($engine === 'mpdf'){
			$mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4']);
			$mpdf->WriteHTML($html);
			if ($disposition==='attachment'){
				$mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
			} else {
				$mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
			}
			exit;
		}
		wp_die('Kein PDF-Engine gefunden. Bitte Dompdf oder mPDF installieren/aktivieren.');
	}
}

/** Common nonce check */
if (!function_exists('bsbt_check_nonce')) {
	function bsbt_check_nonce($booking_id){
		$nonce = $_GET['nonce'] ?? '';
		if (!wp_verify_nonce($nonce, 'bsbt_owner_pdf_'.$booking_id)) {
			wp_die('Bad nonce.');
		}
	}
}

/** OPEN (PDF inline) */
if (!function_exists('bsbt_owner_pdf_open_handler')) {
	function bsbt_owner_pdf_open_handler(){
		$booking_id = (int)($_GET['booking_id'] ?? 0);
		bsbt_check_nonce($booking_id);
		bsbt_stream_pdf($booking_id, 'inline');
	}
	if (!has_action('admin_post_bsbt_owner_pdf_open', 'bsbt_owner_pdf_open_handler')) {
		add_action('admin_post_bsbt_owner_pdf_open', 'bsbt_owner_pdf_open_handler');
	}
}

/** DOWNLOAD (PDF file) */
if (!function_exists('bsbt_owner_pdf_download_handler')) {
	function bsbt_owner_pdf_download_handler(){
		$booking_id = (int)($_GET['booking_id'] ?? 0);
		bsbt_check_nonce($booking_id);
		bsbt_stream_pdf($booking_id, 'attachment');
	}
	if (!has_action('admin_post_bsbt_owner_pdf_download', 'bsbt_owner_pdf_download_handler')) {
		add_action('admin_post_bsbt_owner_pdf_download', 'bsbt_owner_pdf_download_handler');
	}
}

/** EMAIL (send PDF as attachment) */
if (!function_exists('bsbt_owner_pdf_email_handler')) {
	function bsbt_owner_pdf_email_handler(){
		$booking_id = (int)($_GET['booking_id'] ?? 0);
		bsbt_check_nonce($booking_id);

		$to = get_post_meta($booking_id, BSBT_BMETA_OWNER_EMAIL, true);
		if (!is_email($to)) wp_die('E-Mail des Vermieters fehlt oder ungültig.');

		$ref     = bsbt_get_display_booking_ref($booking_id);
		$subject = 'Besitzer Rechnung – Buchung #'.$ref.' – Stay4Fair.com';
		$message = "Guten Tag,\n\nanbei die Besitzer-Rechnung / Bestätigung zur Buchung #{$ref}.\n\nMit freundlichen Grüßen\nStay4Fair.com";

		$engine = bsbt_pdf_engine();
		$tmp = wp_tempnam('bsbt_owner_'.$ref.'.pdf');

		if ($engine === 'dompdf'){
			$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
			$dompdf->loadHtml(function_exists('bsbt_owner_pdf_html') ? bsbt_owner_pdf_html($booking_id) : '');
			$dompdf->setPaper('A4', 'portrait');
			$dompdf->render();
			file_put_contents($tmp, $dompdf->output());
		} elseif ($engine === 'mpdf'){
			$mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4']);
			$mpdf->WriteHTML(function_exists('bsbt_owner_pdf_html') ? bsbt_owner_pdf_html($booking_id) : '');
			$mpdf->Output($tmp, \Mpdf\Output\Destination::FILE);
		} else {
			wp_die('Kein PDF-Engine gefunden. Bitte Dompdf oder mPDF installieren/aktivieren.');
		}

		$headers = ['Content-Type: text/plain; charset=UTF-8'];
		wp_mail($to, $subject, $message, $headers, [$tmp]);

		wp_safe_redirect( wp_get_referer() ?: admin_url('edit.php?post_type=mphb_booking') );
		exit;
	}
	if (!has_action('admin_post_bsbt_owner_pdf_email', 'bsbt_owner_pdf_email_handler')) {
		add_action('admin_post_bsbt_owner_pdf_email', 'bsbt_owner_pdf_email_handler');
	}
}

/** WHATSAPP (TEXT ONLY) */
if (!function_exists('bsbt_owner_msg_whatsapp_handler')) {
	function bsbt_owner_msg_whatsapp_handler(){
		$booking_id = (int)($_GET['booking_id'] ?? 0);
		bsbt_check_nonce($booking_id);

		$ref = bsbt_get_display_booking_ref($booking_id);

		// <<< CHANGED: используем правильную функцию
		$text = function_exists('bsbt_build_owner_whatsapp_text')
			? bsbt_build_owner_whatsapp_text($booking_id)
			: "Besitzer-Bestätigung – Buchung #{$ref} – Stay4Fair.com";

		$encodedText = rawurlencode($text);

		$owner_phone = get_post_meta($booking_id, BSBT_BMETA_OWNER_PHONE, true);
		$wa_number = function_exists('bsbt_format_phone_for_wa')
			? bsbt_format_phone_for_wa($owner_phone)
			: '';

		$url = $wa_number
			? ('https://wa.me/'.$wa_number.'?text='.$encodedText)
			: ('https://wa.me/?text='.$encodedText);

		wp_redirect($url);
		exit;
	}
	if (!has_action('admin_post_bsbt_owner_msg_whatsapp', 'bsbt_owner_msg_whatsapp_handler')) {
		add_action('admin_post_bsbt_owner_msg_whatsapp', 'bsbt_owner_msg_whatsapp_handler');
	}
}

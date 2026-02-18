<?php
/**
 * Plugin Name: BSBT – Separate Voucher Email After Woo Payment
 * Description: Sends a separate email with Voucher PDF shortly after WooCommerce marks payment complete. Uses Action Scheduler when available. Supports Direct Capture status change.
 * Author: Stay4Fair.com
 * Version: 1.2.0
 */

if (!defined('ABSPATH')) exit;

/**
 * ===========================
 * 0) Small logger (fallback)
 * ===========================
 */
function bsbt_voucher_paid_log_file($message) {
	$upload_dir = wp_upload_dir();
	$log_file = trailingslashit($upload_dir['basedir']) . 'bsbt_voucher_paid_email.log';
	$ts = current_time('mysql');
	@file_put_contents($log_file, "[{$ts}] {$message}\n", FILE_APPEND);
}

function bsbt_voucher_paid_log_booking($booking_id, $entry) {
	$booking_id = (int)$booking_id;

	// Prefer your existing booking log (metabox reads it)
	if (function_exists('bs_bt_log_voucher_send')) {
		bs_bt_log_voucher_send($booking_id, $entry);
		return;
	}

	// Fallback file log
	$msg = ($entry['source'] ?? 'unknown') . ' | ' . ($entry['status'] ?? '') . ' | ' . ($entry['error'] ?? '');
	bsbt_voucher_paid_log_file("booking={$booking_id} {$msg}");
}

/**
 * ============================================================
 * 1) Trigger: payment complete OR status change to processing
 * ============================================================
 */

// А) Стандартный триггер при завершении платежа шлюзом
add_action('woocommerce_payment_complete', 'bsbt_trigger_voucher_process', 20);

// Б) Триггер на смену статуса (когда владелец нажимает "Bestätigen" в кабинете)
add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status) {
	if ($new_status === 'processing') {
		bsbt_trigger_voucher_process($order_id);
	}
}, 20, 3);

/**
 * Единая функция постановки задачи в очередь
 */
function bsbt_trigger_voucher_process($order_id) {
	$order_id = (int)$order_id;
	if ($order_id <= 0) return;

	// Задержка 10 секунд для синхронизации базы MotoPress
	$delay = 10;

	if (function_exists('as_schedule_single_action')) {
		as_schedule_single_action(
			time() + $delay,
			'bsbt_send_voucher_paid_email_action',
			array('order_id' => $order_id, 'attempt' => 1),
			'bsbt_voucher'
		);
	} else {
		// Резервный вариант, если Action Scheduler недоступен
		bsbt_send_voucher_paid_email_worker(array('order_id' => $order_id, 'attempt' => 1));
	}
}

/**
 * ===========================
 * 2) Action Scheduler worker hook
 * ===========================
 */
add_action('bsbt_send_voucher_paid_email_action', 'bsbt_send_voucher_paid_email_worker', 10, 1);

/**
 * ===========================
 * 3) Find Booking ID for Woo order
 * ===========================
 */
function bsbt_find_booking_id_for_order($order_id) {
	$order = wc_get_order($order_id);
	if (!$order) return 0;

	// A) Primary: item meta _mphb_payment_id -> payment post -> booking meta
	foreach ($order->get_items() as $item) {
		$payment_id = (int) $item->get_meta('_mphb_payment_id', true);
		if ($payment_id > 0) {
			$booking_id = (int) get_post_meta($payment_id, '_mphb_booking_id', true);
			if (!$booking_id) $booking_id = (int) get_post_meta($payment_id, 'mphb_booking_id', true);
			if ($booking_id > 0) return $booking_id;
		}
	}

	// B) Secondary: scan all item metas for something like booking id
	foreach ($order->get_items() as $item) {
		$all = $item->get_meta_data();
		if (!empty($all)) {
			foreach ($all as $meta) {
				$key = is_object($meta) ? (string)$meta->key : '';
				$val = is_object($meta) ? $meta->value : '';
				$key_l = strtolower($key);

				if (strpos($key_l, 'booking') !== false && is_scalar($val)) {
					$v = (int)$val;
					if ($v > 0) return $v;
				}
			}
		}
	}

	// C) Tertiary: check order meta
	$candidates = array('_mphb_booking_id','mphb_booking_id','_booking_id','booking_id');
	foreach ($candidates as $k) {
		$v = (int) $order->get_meta($k, true);
		if ($v > 0) return $v;
	}

	return 0;
}

/**
 * ===========================
 * 4) Generate voucher PDF (safe)
 * ===========================
 */
function bsbt_generate_voucher_pdf_for_booking($booking_id) {
	$booking_id = (int)$booking_id;
	if ($booking_id <= 0) return '';

	// Prefer your existing generator from bs-voucher.php
	if (function_exists('bs_bt_generate_voucher_pdf_file')) {
		$pdf = bs_bt_generate_voucher_pdf_file($booking_id, 'PAIDEMAIL');
		if ($pdf && is_file($pdf) && is_readable($pdf)) return $pdf;
	}

	// Fallback: try render + engine if your functions exist
	if (!function_exists('bs_bt_render_voucher_html') || !function_exists('bs_bt_try_load_pdf_engine')) {
		return '';
	}

	$html = bs_bt_render_voucher_html($booking_id);
	if (!$html) return '';

	$upload_dir = wp_upload_dir();
	$dir = trailingslashit($upload_dir['basedir']) . 'bs-vouchers';
	if (!is_dir($dir)) wp_mkdir_p($dir);

	$file = trailingslashit($dir) . 'Voucher-' . $booking_id . '-PAIDEMAIL.pdf';

	// Reuse if exists
	if (is_file($file) && filesize($file) > 800) return $file;

	$engine = bs_bt_try_load_pdf_engine();

	try {
		@ini_set('memory_limit', '512M');
		@ini_set('max_execution_time', '300');

		if ($engine === 'mpdf' && class_exists('\Mpdf\Mpdf')) {
			$mpdf = new \Mpdf\Mpdf(['format'=>'A4','margin_left'=>12,'margin_right'=>12,'margin_top'=>14,'margin_bottom'=>14]);
			$mpdf->WriteHTML($html);
			$mpdf->Output($file, \Mpdf\Output\Destination::FILE);
		} elseif ($engine === 'dompdf' && class_exists('\Dompdf\Dompdf')) {
			$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
			$dompdf->loadHtml($html, 'UTF-8');
			$dompdf->setPaper('A4','portrait');
			$dompdf->render();
			file_put_contents($file, $dompdf->output());
		} else {
			return '';
		}
	} catch (\Throwable $e) {
		return '';
	}

	return (is_file($file) && filesize($file) > 800) ? $file : '';
}

/**
 * ===========================
 * 5) Get guest email from MPHB booking
 * ===========================
 */
function bsbt_get_guest_email_for_booking($booking_id) {
	$booking_id = (int)$booking_id;

	$email = trim((string) get_post_meta($booking_id, 'mphb_email', true));
	if ($email && is_email($email)) return $email;

	if (function_exists('MPHB')) {
		try {
			$entity = MPHB()->getBookingRepository()->findById($booking_id);
			if ($entity && method_exists($entity, 'getCustomer')) {
				$c = $entity->getCustomer();
				if ($c && method_exists($c, 'getEmail')) {
					$e = trim((string)$c->getEmail());
					if ($e && is_email($e)) return $e;
				}
			}
		} catch (\Throwable $e) {}
	}

	return '';
}

/**
 * ===========================
 * 6A) Build beautiful email body (PDF-like) — TABLE LAYOUT (EMAIL SAFE)
 * ===========================
 */
function bsbt_build_voucher_email_html($booking_id) {
	$booking_id = (int)$booking_id;

	// Guest
	$guest_first = trim((string)get_post_meta($booking_id, 'mphb_first_name', true));
	$guest_last  = trim((string)get_post_meta($booking_id, 'mphb_last_name', true));
	$guest_name  = trim($guest_first . ' ' . $guest_last);
	if ($guest_name === '') $guest_name = 'Guest';

	// Guests count
	$adults   = (int)get_post_meta($booking_id, 'mphb_adults', true);
	$children = (int)get_post_meta($booking_id, 'mphb_children', true);
	$total_guests = $adults + $children;
	if ($total_guests <= 0) $total_guests = (int)get_post_meta($booking_id, 'mphb_total_guests', true);
	if ($total_guests <= 0) $total_guests = 1;

	// Dates
	$check_in  = trim((string)get_post_meta($booking_id, 'mphb_check_in_date', true));
	$check_out = trim((string)get_post_meta($booking_id, 'mphb_check_out_date', true));

	// Owner meta from first reserved room type
	$owner = array('name'=>'','phone'=>'','email'=>'','address'=>'','doorbell'=>'');
	if (function_exists('MPHB')) {
		try {
			$booking = MPHB()->getBookingRepository()->findById($booking_id);
			if ($booking) {
				$reserved = $booking->getReservedRooms();
				if (!empty($reserved)) {
					$first = reset($reserved);
					$rtid  = method_exists($first, 'getRoomTypeId') ? (int) $first->getRoomTypeId() : 0;
					if ($rtid) {
						$owner['name']     = trim((string)get_post_meta($rtid, 'owner_name', true));
						$owner['phone']    = trim((string)get_post_meta($rtid, 'owner_phone', true));
						$owner['email']    = trim((string)get_post_meta($rtid, 'owner_email', true));
						$owner['address']  = trim((string)get_post_meta($rtid, 'address', true));
						$owner['doorbell'] = trim((string)get_post_meta($rtid, 'doorbell_name', true));
					}
				}
			}
		} catch (\Throwable $e) {}
	}

	$owner_block = '';
	if ($owner['name'])    $owner_block .= 'Owner: ' . esc_html($owner['name']) . '<br>';
	if ($owner['phone'])   $owner_block .= 'Phone: ' . esc_html($owner['phone']) . '<br>';
	if ($owner['email'])   $owner_block .= 'Email: ' . esc_html($owner['email']) . '<br>';
	if ($owner['address']) $owner_block .= '<br><strong>Apartment address:</strong><br>' . nl2br(esc_html($owner['address'])) . '<br>';
	if ($owner['doorbell'])$owner_block .= 'Doorbell: ' . esc_html($owner['doorbell']) . '<br>';
	if ($owner_block === '') $owner_block = 'Details will be provided shortly by our team.';

	// Cancellation policy (HTML, controlled)
	$policy_type = function_exists('bsbt_get_cancellation_policy_type_for_booking')
		? (string) bsbt_get_cancellation_policy_type_for_booking($booking_id, 'nonref')
		: 'nonref';

	$policy_html = function_exists('bsbt_get_cancellation_text_en')
		? (string) bsbt_get_cancellation_text_en($policy_type)
		: '<p>Cancellation policy details are currently unavailable.</p>';

	// Remove emojis (email/pdf safety)
	$policy_html = str_replace(array('✨','🔐','🔄','🤝','⚠️'), '', $policy_html);

	// Instructions
	$instructions = 'The keys will be handed over to you at check-in, directly in the apartment (please inform us about your arrival time).<br>
Please note: this is a private apartment.<br>
Light cleaning will be performed every third day. We kindly ask you to keep the apartment in order, too.<br>
At check-out, you may leave the keys on the table and close the door, or coordinate your check-out time with our manager or the landlord to hand over the keys personally.<br>
Please handle the apartment and its inventory with care. In case of any damage to the landlord’s property, the guest must compensate the damage to the company or directly to the landlord.';

	$voucher_no = function_exists('bs_bt_get_voucher_number') ? bs_bt_get_voucher_number($booking_id) : (string)$booking_id;

	$logo_url = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.png';

	// Email-safe HTML (no flex, no external CSS)
	$message =
	'<div style="background:#f4f6f8;padding:24px 0;">
	  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
		<tr>
		  <td align="center" style="padding:0 12px;">

			<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;border-collapse:separate;background:#ffffff;border-radius:12px;">
			  <tr>
				<td style="padding:24px;font-family:Arial,Helvetica,sans-serif;color:#111;font-size:14px;line-height:1.5;">

				  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;border-bottom:1px solid #e5e7eb;margin-bottom:16px;">
					<tr>
					  <td valign="middle" style="padding:0 12px 16px 0;white-space:nowrap;">
						<img src="'.esc_url($logo_url).'" alt="Stay4Fair" style="max-height:48px;display:block;height:auto;width:auto;">
					  </td>
					  <td valign="middle" align="right" style="padding:0 0 16px 12px;text-align:right;font-size:12px;color:#555;line-height:1.5;white-space:nowrap;">
						<div style="white-space:nowrap;">E-mail:
						  <a style="color:#111;text-decoration:none;white-space:nowrap;" href="mailto:business@stay4fair.com">business@stay4fair.com</a>
						</div>
						<div style="white-space:nowrap;">WhatsApp:
						  <a style="color:#111;text-decoration:none;white-space:nowrap;" href="https://wa.me/4917624615269">+49 176 24615269</a>
						</div>
						<div style="white-space:nowrap;">
						  <a style="color:#111;text-decoration:none;white-space:nowrap;" href="https://stay4fair.com">stay4fair.com</a>
						</div>
					  </td>
					</tr>
				  </table>

				  <div style="font-size:20px;font-weight:700;margin:0 0 6px;">Booking Voucher</div>
				  <div style="font-size:12px;color:#666;margin:0 0 16px;">
					Voucher No: <strong>'.esc_html($voucher_no).'</strong> &nbsp;·&nbsp; Booking ID: '.(int)$booking_id.'
				  </div>

				  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:separate;">
					<tr>
					  <td valign="top" style="width:58%;padding-right:10px;">

						<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:separate;border:1px solid #e5e7eb;border-radius:8px;">
						  <tr>
							<td style="padding:12px;">
							  <div style="font-weight:700;margin:0 0 6px;">Guest</div>
							  <div>'.esc_html($guest_name).'</div>
							  <div>Total guests: '.(int)$total_guests.'</div>

							  <div style="height:1px;background:#eee;margin:12px 0;"></div>

							  <div style="font-weight:700;margin:0 0 6px;">Stay</div>
							  <div>Check-in: '.esc_html($check_in).' (15:00–23:00)</div>
							  <div>Check-out: '.esc_html($check_out).' (until 12:00)</div>
							</td>
						  </tr>
						</table>

					  </td>

					  <td valign="top" style="width:42%;padding-left:10px;">

						<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:separate;border:1px solid #e5e7eb;border-radius:8px;">
						  <tr>
							<td style="padding:12px;">
							  <div style="font-weight:700;margin:0 0 6px;">Owner / Check-in Information</div>
							  <div style="font-size:13px;line-height:1.55;">'.$owner_block.'</div>
							</td>
						  </tr>
						</table>

					  </td>
					</tr>
				  </table>

				  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:12px;border-collapse:separate;border:1px solid #e5e7eb;border-radius:8px;">
					<tr>
					  <td style="padding:12px;">
						<div style="font-weight:700;margin:0 0 6px;">Check-in / Check-out instructions</div>
						<div style="font-size:13px;line-height:1.6;">'.$instructions.'</div>
					  </td>
					</tr>
				  </table>

				  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:12px;border-collapse:separate;border:1px solid #e5e7eb;border-radius:8px;">
					<tr>
					  <td style="padding:12px;">
						<div style="font-weight:700;margin:0 0 6px;">Cancellation policy</div>
						<div style="font-size:13px;line-height:1.6;">'.$policy_html.'</div>
					  </td>
					</tr>
				  </table>

				  <div style="text-align:center;font-size:12px;color:#666;margin-top:20px;">
					Stay4Fair.com · Private apartments · WhatsApp +49 176 24615269 · business@stay4fair.com
				  </div>

				</td>
			  </tr>
			</table>

		  </td>
		</tr>
	  </table>
	</div>';

	return $message;
}

/**
 * ===========================
 * 6B) Retry scheduler
 * ===========================
 */
function bsbt_voucher_paid_schedule_retry($order_id, $attempt, $reason) {
	$order_id = (int)$order_id;
	$attempt  = (int)$attempt;

	// retry plan: 1->30s, 2->120s, 3->600s
	$delays = array(30, 120, 600);
	if ($attempt >= 4) return;

	$delay = $delays[$attempt - 1] ?? 120;

	bsbt_voucher_paid_log_file("ORDER {$order_id}: retry scheduled attempt={$attempt} in {$delay}s, reason={$reason}");

	if (function_exists('as_schedule_single_action')) {
		as_schedule_single_action(
			time() + $delay,
			'bsbt_send_voucher_paid_email_action',
			array('order_id' => $order_id, 'attempt' => $attempt),
			'bsbt_voucher'
		);
	}
}

/**
 * ===========================
 * 7) Worker: send separate email
 * ===========================
 */
function bsbt_send_voucher_paid_email_worker($args) {
	$order_id = 0;
	$attempt  = 1;

	if (is_array($args) && isset($args['order_id'])) {
		$order_id = (int)$args['order_id'];
		$attempt  = isset($args['attempt']) ? (int)$args['attempt'] : 1;
	} elseif (is_numeric($args)) {
		$order_id = (int)$args;
	}

	if ($order_id <= 0) return;

	$booking_id = bsbt_find_booking_id_for_order($order_id);
	if ($booking_id <= 0) {
		bsbt_voucher_paid_log_file("ORDER {$order_id}: booking_id not found (attempt {$attempt}).");
		bsbt_voucher_paid_schedule_retry($order_id, $attempt + 1, 'booking_id_not_found');
		return;
	}

	// Dedup
	if (get_post_meta($booking_id, '_bsbt_voucher_paid_email_sent', true)) {
		return;
	}

	// Ensure main voucher functions exist
	if (!function_exists('bs_bt_get_voucher_number')) {
		bsbt_voucher_paid_log_booking($booking_id, array(
			'to'      => 'system',
			'subject' => 'Separate voucher email',
			'source'  => 'paid-email',
			'status'  => 'fail',
			'error'   => 'Main voucher plugin functions missing (bs_bt_get_voucher_number)'
		));
		bsbt_voucher_paid_schedule_retry($order_id, $attempt + 1, 'missing_main_functions');
		return;
	}

	$pdf = bsbt_generate_voucher_pdf_for_booking($booking_id);
	if (!$pdf) {
		bsbt_voucher_paid_log_booking($booking_id, array(
			'to'      => 'system',
			'subject' => 'Separate voucher email',
			'source'  => 'paid-email',
			'status'  => 'fail',
			'error'   => 'PDF not generated'
		));
		bsbt_voucher_paid_schedule_retry($order_id, $attempt + 1, 'pdf_not_generated');
		return;
	}

	$to = bsbt_get_guest_email_for_booking($booking_id);
	if (!$to) {
		bsbt_voucher_paid_log_booking($booking_id, array(
			'to'      => 'system',
			'subject' => 'Separate voucher email',
			'source'  => 'paid-email',
			'status'  => 'fail',
			'error'   => 'Guest email not found'
		));
		bsbt_voucher_paid_schedule_retry($order_id, $attempt + 1, 'guest_email_missing');
		return;
	}

	$voucher_no = bs_bt_get_voucher_number($booking_id);
	$subject = sprintf('Your Booking Voucher %s — Stay4Fair', $voucher_no);

	// ✅ PDF-like visual body
	$message = bsbt_build_voucher_email_html($booking_id);

	$headers = array('Content-Type: text/html; charset=UTF-8');

	// Set From only for this send (scoped via temporary filters)
	$from_name_cb  = function(){ return 'Stay4Fair Reservations'; };
	$from_email_cb = function(){ return 'business@stay4fair.com'; };

	add_filter('wp_mail_from_name', $from_name_cb, 999);
	add_filter('wp_mail_from', $from_email_cb, 999);

	$sent = wp_mail($to, $subject, $message, $headers, array($pdf));

	remove_filter('wp_mail_from_name', $from_name_cb, 999);
	remove_filter('wp_mail_from', $from_email_cb, 999);

	bsbt_voucher_paid_log_booking($booking_id, array(
		'to'      => $to,
		'subject' => $subject,
		'source'  => 'paid-email',
		'status'  => $sent ? 'ok' : 'fail',
		'error'   => $sent ? '' : 'wp_mail returned false'
	));

	if ($sent) {
		update_post_meta($booking_id, '_bsbt_voucher_paid_email_sent', 1);
	} else {
		bsbt_voucher_paid_schedule_retry($order_id, $attempt + 1, 'wp_mail_failed');
	}
}
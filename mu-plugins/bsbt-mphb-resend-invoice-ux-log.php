<?php
/**
 * Plugin Name: BSBT – MPHB Resend Invoice (rename + log)
 * Description: Renames MPHB "Resend Email" metabox UI to "Resend Invoice" and logs resend attempts per booking.
 * Author: BS Business Travelling / Stay4Fair
 * Version: 1.1
 */

if (!defined('ABSPATH')) exit;

function bsbt_mphb_booking_post_type() {
    return 'mphb_booking';
}

/**
 * 1) Add our "Invoice resend log" metabox
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'bsbt_invoice_resend_log',
        __('Invoice resend log', 'bsbt'),
        'bsbt_invoice_resend_log_metabox',
        bsbt_mphb_booking_post_type(),
        'side',
        'default'
    );
}, 30);

function bsbt_invoice_resend_log_metabox($post) {
    if (!$post || empty($post->ID)) return;

    $log = get_post_meta($post->ID, '_bsbt_invoice_resend_log', true);
    if (!is_array($log)) $log = [];

    echo '<div style="font-size:12px;line-height:1.4;color:#555;">';
    echo 'Logs are recorded when a manager clicks <strong>Resend Invoice</strong>.';
    echo '</div>';

    if (empty($log)) {
        echo '<p style="margin-top:10px;color:#666;">No resend attempts yet.</p>';
        return;
    }

    echo '<div style="margin-top:10px;max-height:220px;overflow:auto;border:1px solid #e5e5e5;border-radius:8px;padding:8px;background:#fff;">';
    foreach (array_reverse($log) as $row) {
        $time = isset($row['time']) ? esc_html($row['time']) : '';
        $user = isset($row['user']) ? esc_html($row['user']) : '';
        echo '<div style="padding:6px 0;border-bottom:1px solid #f1f1f1;">';
        echo '<div><strong>' . $time . '</strong></div>';
        echo '<div style="color:#666;">by ' . $user . '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '<p style="margin-top:8px;">'
        . '<a class="button" href="#" onclick="if(confirm(\'Clear log?\')){document.getElementById(\'bsbt-clear-log\').submit();} return false;">Clear log</a>'
        . '</p>';

    echo '<form id="bsbt-clear-log" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('bsbt_clear_invoice_log', 'bsbt_clear_invoice_log_nonce');
    echo '<input type="hidden" name="action" value="bsbt_clear_invoice_log">';
    echo '<input type="hidden" name="booking_id" value="' . (int)$post->ID . '">';
    echo '</form>';
}

add_action('admin_post_bsbt_clear_invoice_log', function() {
    if (!current_user_can('edit_posts')) wp_die('Forbidden');

    $nonce = $_POST['bsbt_clear_invoice_log_nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'bsbt_clear_invoice_log')) wp_die('Bad nonce');

    $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    if (!$booking_id) wp_die('No booking_id');

    delete_post_meta($booking_id, '_bsbt_invoice_resend_log');

    wp_safe_redirect(get_edit_post_link($booking_id, 'url'));
    exit;
});

/**
 * 2) Rename MPHB UI text via admin JS (safe / update-proof)
 */
add_action('admin_enqueue_scripts', function($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== bsbt_mphb_booking_post_type()) return;

    wp_register_script('bsbt-mphb-resend-invoice-ux', '', [], '1.1', true);
    wp_enqueue_script('bsbt-mphb-resend-invoice-ux');

    $post_id = isset($_GET['post']) ? (int)$_GET['post'] : 0;

    wp_localize_script('bsbt-mphb-resend-invoice-ux', 'BSBT_RESEND_INVOICE', [
        'ajaxUrl'   => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('bsbt_invoice_resend_log'),
        'bookingId' => $post_id,
        'title'     => 'Resend Invoice',
        'btnText'   => 'Resend Invoice',
        'hint'      => 'Sends the Approved Booking email again (with PDF invoice if %pdf_invoice% is in the template).',
    ]);

    wp_add_inline_script('bsbt-mphb-resend-invoice-ux', bsbt_mphb_resend_invoice_inline_js());
});

function bsbt_mphb_resend_invoice_inline_js() {
return <<<JS
(function(){
  const TITLE_FROM = ['Resend Email','Resend email'];
  const BTN_FROM   = ['Resend Email','Resend email'];

  function renameIfFound() {
    // 1) find the MPHB metabox by its title
    const postboxes = document.querySelectorAll('.postbox');
    let box = null;

    postboxes.forEach(pb => {
      const h = pb.querySelector('.postbox-header .hndle, .hndle');
      const t = (h ? h.textContent : '').trim();
      if (TITLE_FROM.includes(t)) box = pb;
    });

    if (!box) return false;

    // 2) rename title
    const h = box.querySelector('.postbox-header .hndle, .hndle');
    if (h) h.textContent = BSBT_RESEND_INVOICE.title;

    // 3) find the actual resend button (usually primary + exact text)
    const candidates = box.querySelectorAll('a.button, button.button, input.button');
    let btn = null;

    candidates.forEach(el => {
      const text = (el.tagName === 'INPUT') ? (el.value || '') : (el.textContent || '');
      const isPrimary = el.classList.contains('button-primary');
      if (isPrimary && BTN_FROM.some(s => text.trim() === s)) btn = el;
    });

    if (!btn) return false;

    // 4) rename button text (do NOT change DOM structure around it)
    if (btn.tagName === 'INPUT') btn.value = BSBT_RESEND_INVOICE.btnText;
    else btn.textContent = BSBT_RESEND_INVOICE.btnText;

    // 5) add hint below (do NOT overwrite MPHB text)
    if (!box.querySelector('.bsbt-invoice-hint')) {
      const hint = document.createElement('div');
      hint.className = 'bsbt-invoice-hint';
      hint.style.cssText = 'margin-top:8px;font-size:12px;line-height:1.35;color:#666;';
      hint.textContent = BSBT_RESEND_INVOICE.hint;
      const inside = box.querySelector('.inside');
      if (inside) inside.appendChild(hint);
    }

    // 6) log click (once)
    if (!btn.dataset.bsbtLogged) {
      btn.dataset.bsbtLogged = '1';
      btn.addEventListener('click', function(){
        try {
          if (!BSBT_RESEND_INVOICE.bookingId) return;

          const fd = new FormData();
          fd.append('action', 'bsbt_log_invoice_resend');
          fd.append('nonce', BSBT_RESEND_INVOICE.nonce);
          fd.append('booking_id', BSBT_RESEND_INVOICE.bookingId);

          fetch(BSBT_RESEND_INVOICE.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          }).catch(()=>{});
        } catch(e) {}
      }, true);
    }

    return true;
  }

  // MPHB may render later -> MutationObserver
  function boot() {
    if (renameIfFound()) return;

    const obs = new MutationObserver(() => {
      if (renameIfFound()) obs.disconnect();
    });

    obs.observe(document.body, { childList: true, subtree: true });

    setTimeout(() => { try { obs.disconnect(); } catch(e){} }, 15000);
  }

  if (document.readyState !== 'loading') boot();
  else document.addEventListener('DOMContentLoaded', boot);
})();
JS;
}

/**
 * 3) AJAX: append log entry
 */
add_action('wp_ajax_bsbt_log_invoice_resend', function() {
    if (!current_user_can('edit_posts')) wp_send_json_error('forbidden');

    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'bsbt_invoice_resend_log')) wp_send_json_error('bad_nonce');

    $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    if (!$booking_id) wp_send_json_error('no_booking_id');

    $user = wp_get_current_user();
    $name = ($user && !empty($user->display_name)) ? $user->display_name : 'unknown';

    $log = get_post_meta($booking_id, '_bsbt_invoice_resend_log', true);
    if (!is_array($log)) $log = [];

    $log[] = [
        'time' => wp_date('Y-m-d H:i:s'),
        'user' => $name,
    ];

    // Keep last 50
    if (count($log) > 50) $log = array_slice($log, -50);

    update_post_meta($booking_id, '_bsbt_invoice_resend_log', $log);

    wp_send_json_success(true);
});

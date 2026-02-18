<?php
/**
 * Plugin Name: BSBT – MPHB Consent UI (Checkbox + Hit Area)
 * Description: Styles MPHB checkout consent checkbox, aligns text inline, and enlarges clickable area without affecting links.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================
 * 1. INLINE CSS (Checkbox styling & alignment)
 * =========================================================
 */
add_action( 'wp_head', function () {
    ?>
    <style>
    /* =========================================================
       Stay4Fair – MPHB Checkout Fields (Styled to Match Inputs)
       ========================================================= */

    .mphb-checkbox-control {
      display: flex !important;
      align-items: flex-start !important;
      gap: 12px;
      margin-bottom: 18px;
    }

    .mphb-checkbox-control input[type="checkbox"] {
      appearance: none;
      -webkit-appearance: none;
      flex: 0 0 20px;
      width: 20px;
      height: 20px;
      border: 1px solid #ccc;
      border-radius: 4px;
      background: #fff;
      cursor: pointer;
      position: relative;
      margin-top: 2px;
      transition: all 0.2s ease-in-out;
    }

    .mphb-checkbox-control input[type="checkbox"]:hover {
      border-color: #999;
    }

    .mphb-checkbox-control input[type="checkbox"]:checked {
      background-color: #E0B849;
      border-color: #E0B849;
    }

    .mphb-checkbox-control input[type="checkbox"]:checked::after {
      content: '';
      position: absolute;
      left: 6px;
      top: 2px;
      width: 5px;
      height: 10px;
      border: solid #fff;
      border-width: 0 2px 2px 0;
      transform: rotate(45deg);
    }

    .mphb-checkbox-control .mphb-control-description {
      line-height: 1.5;
      font-size: 14px;
      color: #212F54;
      margin: 0;
    }

    .mphb-checkbox-control .mphb-control-description a {
      color: #C62828 !important;
      text-decoration: none;
      font-weight: 600;
    }

    .mphb-checkbox-control .mphb-control-description a:hover {
      text-decoration: underline !important;
    }

    .mphb-checkbox-control br {
      display: none !important;
    }
    </style>
    <?php
}, 20 );

/**
 * =========================================================
 * 2. INLINE JS (Increase hit-area, keep links clickable)
 * =========================================================
 */
add_action( 'wp_footer', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

      document.querySelectorAll('.mphb-checkbox-control').forEach(function (row) {

        const checkbox = row.querySelector('input[type="checkbox"]');
        if (!checkbox) return;

        row.addEventListener('click', function (e) {

          /* If click is on a link – allow normal navigation */
          if (e.target.closest('a')) return;

          /* If click is directly on checkbox – browser handles it */
          if (e.target === checkbox) return;

          /* Toggle checkbox */
          checkbox.checked = !checkbox.checked;
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });

      });

    });
    </script>
    <?php
}, 20 );

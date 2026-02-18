<?php
/**
 * Plugin Name: BSBT – Owner Portal Core
 * Description: Core logic for Owner decisions and cron auto-expire.
 * Version: 10.23.2
 * Author: BS Business Travelling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define('BSBT_OWNER_PORTAL_PATH', plugin_dir_path(__FILE__));

/**
 * Load Core Decision Logic
 */
require_once BSBT_OWNER_PORTAL_PATH . 'includes/owner-decision-core.php';

/**
 * Load Cron Loader
 */
require_once BSBT_OWNER_PORTAL_PATH . 'includes/owner-cron.php';

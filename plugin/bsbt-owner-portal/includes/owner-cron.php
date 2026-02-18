<?php
if (!defined('ABSPATH')) exit;

/**
 * BSBT Owner Auto Expire Cron Loader
 */

// Регистрируем выполнение задачи
add_action(
    'bsbt_owner_cron_auto_expire',
    ['BSBT_Owner_Decision_Core', 'process_auto_expire']
);

// Планировщик (раз в час)
add_action('init', function () {

    if (!wp_next_scheduled('bsbt_owner_cron_auto_expire')) {
        wp_schedule_event(
            time(),
            'hourly',
            'bsbt_owner_cron_auto_expire'
        );
    }

});

<?php
/**
 * Plugin Name: BSBT – MPHB Calendar EN (safe)
 * Description: Аккуратно переключает Keith Wood Datepick в EN, не ломая формат даты, плюс красит выбранные даты в фирменный жёлтый.
 * Author: BS Business Travelling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ===========================================================
 * 1. EN локаль для datepick INPUT-полей (search & booking form)
 * ===========================================================
 */
add_action( 'wp_footer', function () {
    ?>
    <script>
    (function($){

        function initCalendarEN(){
            if (!window.jQuery || !$.fn || !$.fn.datepick || !$.datepick) return;

            var EN = ($.datepick.regionalOptions &&
                      ($.datepick.regionalOptions['en-GB'] ||
                       $.datepick.regionalOptions['en'])) || {};

            EN = $.extend({
                todayText:  'Today',
                clearText:  'Clear',
                closeText:  'Close',
                prevText:   '<',
                nextText:   '>',
                monthNames: [
                    "January","February","March","April","May","June",
                    "July","August","September","October","November","December"
                ],
                monthNamesShort:[
                    "Jan","Feb","Mar","Apr","May","Jun",
                    "Jul","Aug","Sep","Oct","Nov","Dec"
                ],
                dayNames:[
                    "Sunday","Monday","Tuesday","Wednesday",
                    "Thursday","Friday","Saturday"
                ],
                dayNamesMin:["Su","Mo","Tu","We","Th","Fr","Sa"],
                firstDay: 1
            }, EN);

            var $fields = $('input[name="mphb_check_in_date"], input[name="mphb_check_out_date"]');
            if (!$fields.length) return;

            $fields.each(function(){
                var $i   = $(this);
                var fmt  = $i.data('date-format');
                var opts = $.extend({}, EN);

                if (fmt) {
                    opts.dateFormat = fmt;
                }

                try {
                    $i.datepick('option', opts);
                } catch(e){}
            });
        }

        $(function(){
            setTimeout(initCalendarEN, 200);
        });

    })(jQuery);
    </script>
    <?php
}, 50);


/**
 * ===========================================================
 * 2. EN локаль для inline-календарей (Availability Calendar)
 * ===========================================================
 */
add_action( 'wp_footer', function () {
    ?>
    <script>
    (function($){

        function fixInlineCalendarEN() {
            if (!$.datepick || !$.datepick.regionalOptions) return;

            var EN = $.extend({}, $.datepick.regionalOptions['en-GB'] ||
                                   $.datepick.regionalOptions['en'] || {});
            EN = $.extend({
                todayText:  'Today',
                clearText:  'Clear',
                closeText:  'Close',
                prevText:   '<',
                nextText:   '>',
                monthNames: [
                    "January","February","March","April","May","June",
                    "July","August","September","October","November","December"
                ],
                monthNamesShort:[
                    "Jan","Feb","Mar","Apr","May","Jun",
                    "Jul","Aug","Sep","Oct","Nov","Dec"
                ],
                dayNames:[
                    "Sunday","Monday","Tuesday","Wednesday",
                    "Thursday","Friday","Saturday"
                ],
                dayNamesMin:["Su","Mo","Tu","We","Th","Fr","Sa"],
                firstDay: 1
            }, EN);

            // Для inline-календарей
            $('.datepick-inline, .mphb-calendar').each(function(){
                try { $(this).datepick('option', EN); } catch(e){}
            });
        }

        $(function(){
            setTimeout(fixInlineCalendarEN, 300);
        });

    })(jQuery);
    </script>
    <?php
}, 60);


/**
 * ===========================================================
 * 3. Фирменный цвет BSBT для строки выбранных дат
 * ===========================================================
 */
add_action( 'wp_footer', function () {
    ?>
    <style>
    /* Конкретно этот элемент: <div class="mphb-calendar__selected-dates"> */
    .mphb-calendar__selected-dates {
        color: #E0B849 !important;   /* BSBT Gold */
        font-weight: 600 !important;
    }

    /* Подстраховка: остальные варианты статуса дат */
    .datepick .datepick-status,
    .datepick-inline .datepick-status,
    .mphb-calendar .datepick-status,
    .datepick .datepick-selected,
    .datepick-inline .datepick-selected,
    .mphb-calendar .datepick-selected {
        color: #E0B849 !important;
        font-weight: 600 !important;
    }
    </style>
    <?php
}, 70);

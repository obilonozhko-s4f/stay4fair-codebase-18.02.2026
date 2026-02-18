<?php
/**
 * Plugin Name: BSBT – Search Form Styles
 * Description: Глобальные CSS стили для формы поиска MotoPress HB (кнопки, поля, календарь).
 * Author: BS Business Travelling
 */

add_action('wp_head', function() {
?>
<style>

/* === BSBT: Search button custom colors === */

.mphb_sc_search-wrapper input[type="submit"].button {
  background: #082567 !important;    /* тёмно-синий фон */
  color: #E0B849 !important;         /* золотой текст */
  border: 1px solid #082567 !important;
  font-weight: 600;
  letter-spacing: 0.3px;
  transition: all .25s ease;
}

/* Hover: инверсия цветов */
.mphb_sc_search-wrapper input[type="submit"].button:hover,
.mphb_sc_search-wrapper input[type="submit"].button:focus {
  background: #E0B849 !important;
  color: #082567 !important;
  border-color: #E0B849 !important;
}

/* На клике - мягкий сдвиг */
.mphb_sc_search-wrapper input[type="submit"].button:active {
  transform: translateY(1px);
}

/* === BSBT: Rounded form elements === */

/* Поля формы */
.mphb_sc_search-wrapper input[type="text"],
.mphb_sc_search-wrapper input[type="number"],
.mphb_sc_search-wrapper input[type="email"],
.mphb_sc_search-wrapper input[type="date"],
.mphb_sc_search-wrapper select {
  border-radius: 10px !important;
}

/* Кнопка */
.mphb_sc_search-wrapper input[type="submit"].button {
  border-radius: 10px !important;
}

/* Мягкая тень */
.mphb_sc_search-wrapper input,
.mphb_sc_search-wrapper select,
.mphb_sc_search-wrapper input[type="submit"].button {
  box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

/* === BSBT: Calendar fixes (Month + Year in one line) === */

/* Поправка заголовков календаря Keith Wood Datepick */
.datepick .datepick-month-header,
.datepick .datepick-month-row {
  display: flex !important;
  flex-wrap: nowrap !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 6px !important;
}

/* Селекты месяца и года — в ряд */
.datepick select.datepick-month,
.datepick select.datepick-year,
.datepick .datepick-month-header select,
.datepick .datepick-month-row select {
  display: inline-block !important;
  width: auto !important;
  max-width: none !important;
  margin: 0 !important;
  white-space: nowrap !important;
  float: none !important;
}

/* На всякий случай — ручной "молоток" для выравнивания */
.datepick select.datepick-month {
  float: left !important;
}
.datepick select.datepick-year {
  float: left !important;
  margin-left: 6px !important;
}

/* Чуть шире окно календаря */
.datepick {
  min-width: 340px !important;
}

/* Запрет переноса текста Month Year */
.datepick .datepick-month-header .datepick-month,
.datepick .datepick-month-header .datepick-year {
  white-space: nowrap !important;
  display: inline-block !important;
}

</style>
<?php
});

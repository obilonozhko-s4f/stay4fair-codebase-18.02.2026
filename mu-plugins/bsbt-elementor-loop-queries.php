<?php
/**
 * Plugin Name: BSBT – Elementor Loop Queries (Room Types)
 * Description: Серверные запросы для Elementor Loop Grid под MotoPress HB:
 *              - Блок 6 (рандом 3, только с миниатюрой)
 *              - Каталог (все, с пагинацией/сортировкой по дате по умолчанию)
 * Author: BS Business Travelling
 * Version: 1.1.0
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Текущая страница пагинации (подстраховка для каталога)
 */
if ( ! function_exists('bsbt__get_paged') ) {
	function bsbt__get_paged() {
		$paged = get_query_var('paged');
		if ( ! $paged ) { $paged = get_query_var('page'); }
		$paged = (int) $paged;
		return $paged > 0 ? $paged : 1;
	}
}

/**
 * Блок 6: 3 случайные карточки, только с миниатюрой.
 * В Elementor: Loop Grid → Erweitert → Abfrage ID = bsbt_home_block6
 */
add_action('elementor/query/bsbt_home_block6', function( $query ){
	if ( ! ( $query instanceof WP_Query ) ) return;

	$query->set('post_type',   'mphb_room_type');
	$query->set('post_status', 'publish');
	$query->set('orderby',     'rand');
	$query->set('ignore_sticky_posts', true);

	// Если в виджете не задано — ставим 3
	if ( ! $query->get('posts_per_page') ) {
		$query->set('posts_per_page', 3);
	}

	// Требуем наличие миниатюры (иначе «пустышки»)
	$meta_query   = (array) $query->get('meta_query');
	$meta_query[] = array(
		'key'     => '_thumbnail_id',
		'compare' => 'EXISTS',
	);
	$query->set('meta_query', $meta_query);
});

/**
 * Каталог: все карточки, базовая пагинация.
 * В Elementor: Loop Grid → Erweitert → Abfrage ID = bsbt_catalog
 * Кол-во на страницу и пагинация задаются в самом виджете.
 */
add_action('elementor/query/bsbt_catalog', function( $query ){
	if ( ! ( $query instanceof WP_Query ) ) return;

	$query->set('post_type',   'mphb_room_type');
	$query->set('post_status', 'publish');
	$query->set('ignore_sticky_posts', true);

	// Дефолт: сортировка по дате (если в виджете не переопределили)
	if ( ! $query->get('orderby') ) {
		$query->set('orderby', 'date');
		$query->set('order',   'DESC');
	}

	// Подстрахуемся на случай, если пагинация не прилетела из виджета
	if ( ! $query->get('paged') ) {
		$query->set('paged', bsbt__get_paged() );
	}
});

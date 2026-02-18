<?php
/**
 * Plugin Name: BSBT – Accommodation List Shortcode (Search + Availability + Filters)
 * Description: [bsbt_accommodation_list] – карточки mphb_room_type в стиле BSBT. Режимы: featured, catalog, search (учёт дат, доступности, города, дистанции и Apartment Type).
 * Author: BS Business Travelling
 * Version: 5.6.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * 1) Утилиты: дата, адрес, город, дистанция
 * ========================================================================== */

function bsbt_parse_date( $raw ) {
	if ( empty( $raw ) ) return null;
	if ( is_array( $raw ) ) {
		$raw = end( $raw );
	}
	$raw = trim( (string) $raw );
	if ( $raw === '' ) return null;

	$formats = array( 'Y-m-d', 'd/m/Y', 'd.m.Y' );
	foreach ( $formats as $format ) {
		$dt = DateTime::createFromFormat( $format, $raw );
		if ( $dt instanceof DateTime ) {
			return $dt;
		}
	}

	$ts = strtotime( $raw );
	if ( $ts ) {
		$dt = new DateTime();
		$dt->setTimestamp( $ts );
		return $dt;
	}
	return null;
}

/**
 * Полный адрес из ACF (field_68fccddecdffd)
 */
function bsbt_get_address( $post_id ) {
	if ( function_exists( 'get_field' ) ) {
		$addr = get_field( 'field_68fccddecdffd', $post_id );
		if ( ! empty( $addr ) ) {
			return trim( (string) $addr );
		}
	}
	return '';
}

/**
 * Город из адреса
 */
function bsbt_get_city_from_address( $address ) {
	if ( ! $address ) return '';

	$address = trim( $address );

	if ( preg_match( '~\b(\d{5})\s+([^\d,]+)$~u', $address, $m ) ) {
		return trim( $m[2] );
	}

	$parts = explode( ',', $address );
	$last  = trim( end( $parts ) );
	if ( $last ) {
		return $last;
	}

	$words = preg_split( '/\s+/', $address );
	return trim( end( $words ) );
}

/**
 * Нормализация города
 */
function bsbt_normalize_city( $city ) {
	$city = strtolower( trim( (string) $city ) );
	$city = preg_replace( '~\s+~', ' ', $city );
	return $city;
}

/**
 * Дистанция до Messe
 */
function bsbt_get_distance_km( $post_id ) {
	$distance = null;

	if ( function_exists( 'get_field' ) ) {
		$distance = get_field( 'bsbt_distance', $post_id );
	} else {
		$distance = get_post_meta( $post_id, 'bsbt_distance', true );
	}

	if ( $distance === '' || $distance === null ) {
		return null;
	}

	$distance = str_replace( ',', '.', (string) $distance );
	$val      = floatval( $distance );

	return $val > 0 ? $val : null;
}

/**
 * МИНИМАЛЬНАЯ ЦЕНА ЗА НОЧЬ
 * Интеграция с Core Pricing Engine (Model A/B)
 */
function bsbt_get_min_price_raw( $room_type_id ) {
	
	// 1. Проверяем бизнес-модель
	$model = get_post_meta( $room_type_id, '_bsbt_business_model', true );

	// 2. Если Модель Б — считаем через ядро
	if ( $model === 'model_b' && function_exists( 'bsbt_calculate_model_b_price' ) ) {
		$owner_price = (float) get_post_meta( $room_type_id, 'owner_price_per_night', true );
		if ( $owner_price > 0 ) {
			return bsbt_calculate_model_b_price( $owner_price );
		}
	}

	// 3. Fallback: Стандартная логика MotoPress
	if ( ! function_exists( 'mphb_prices_facade' ) ) return false;

	$facade = mphb_prices_facade();
	if ( ! is_object( $facade ) || ! method_exists( $facade, 'getActiveRatesByRoomTypeId' ) ) {
		return false;
	}

	$today = new DateTime( current_time( 'Y-m-d' ) );
	$rates = $facade->getActiveRatesByRoomTypeId( $room_type_id );
	if ( empty( $rates ) ) return false;

	$min = false;
	foreach ( $rates as $rate ) {
		if ( method_exists( $rate, 'isExistsFrom' ) && ! $rate->isExistsFrom( $today ) ) {
			continue;
		}
		if ( method_exists( $rate, 'getMinBasePrice' ) ) {
			$p = $rate->getMinBasePrice( $today );
			if ( is_numeric( $p ) ) {
				if ( $min === false || $p < $min ) {
					$min = $p;
				}
			}
		}
	}

	return $min === false ? false : (float) $min;
}

/**
 * Вместимость
 */
function bsbt_get_room_capacity( $post_id ) {
	$capacity_raw = get_post_meta( $post_id, 'mphb_total_capacity', true );

	if ( $capacity_raw === '' || $capacity_raw === null ) {
		$capacity_raw = get_post_meta( $post_id, 'mphb_adults_capacity', true );
	}

	if ( ! is_numeric( $capacity_raw ) ) {
		return 0;
	}

	return max( 0, intval( $capacity_raw ) );
}

/* ==========================================================================
 * 3) Шорткод: [bsbt_accommodation_list]
 * ========================================================================== */

add_shortcode( 'bsbt_accommodation_list', 'bsbt_render_accommodation_list' );

function bsbt_render_accommodation_list( $atts ) {

	$atts = shortcode_atts(
		array(
			'mode'     => 'featured', 
			'count'    => 3,
			'per_page' => 12,
		),
		$atts,
		'bsbt_accommodation_list'
	);

	$mode     = sanitize_key( $atts['mode'] );
	$count    = max( 1, intval( $atts['count'] ) );
	$per_page = max( 1, intval( $atts['per_page'] ) );

	$sort = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : '';

	$filter_city_raw = isset( $_GET['bsbt_city'] ) ? sanitize_text_field( wp_unslash( $_GET['bsbt_city'] ) ) : '';
	$filter_city     = bsbt_normalize_city( $filter_city_raw );
	$filter_max_dist = isset( $_GET['bsbt_max_distance'] ) ? str_replace( ',', '.', wp_unslash( $_GET['bsbt_max_distance'] ) ) : '';
	$filter_max_dist = ( $filter_max_dist !== '' ) ? floatval( $filter_max_dist ) : null;

	$check_in  = null;
	$check_out = null;
	$nights    = 0;
	$search    = ( $mode === 'search' );

	$adults           = isset( $_GET['mphb_adults'] )   ? intval( $_GET['mphb_adults'] )   : 0;
	$children          = isset( $_GET['mphb_children'] ) ? intval( $_GET['mphb_children'] ) : 0;
	$guests_requested = max( 0, $adults + $children );

	if ( $search ) {
		$check_in  = bsbt_parse_date( $_GET['mphb_check_in_date']  ?? '' );
		$check_out = bsbt_parse_date( $_GET['mphb_check_out_date'] ?? '' );
		if ( $check_in && $check_out ) {
			$diff   = $check_in->diff( $check_out );
			$nights = max( 0, (int) $diff->days );
		}
	}

	$paged = ( $mode === 'catalog' || $mode === 'search' )
		? max( 1, intval( get_query_var( 'paged' ) ?: get_query_var( 'page' ) ?: 1 ) )
		: 1;

	if ( ! $search ) {

		$query_args = array(
			'post_type'   => 'mphb_room_type',
			'post_status' => 'publish',
		);

		if ( $mode === 'catalog' ) {
			$query_args['posts_per_page'] = $per_page;
			$query_args['orderby']        = 'date';
			$query_args['order']          = 'DESC';
			$query_args['paged']          = $paged;
		} else {
			$query_args['posts_per_page'] = $count;
			$query_args['orderby']        = 'rand';
		}

		$q = new WP_Query( $query_args );
		if ( ! $q->have_posts() ) {
			return '';
		}

		$html  = bsbt_acc_list_styles();
		$html .= '<div class="bsbt-acc-list">';

		while ( $q->have_posts() ) {
			$q->the_post();
			$post_id   = get_the_ID();
			$item_data = bsbt_build_card_data( $post_id, $check_in, $check_out, $nights, $search );
			$html     .= bsbt_render_card_html( $item_data );
		}

		$html .= '</div>';

		if ( $mode === 'catalog' && $q->max_num_pages > 1 ) {
			$html .= bsbt_render_pagination( $q->max_num_pages, $paged );
		}

		wp_reset_postdata();
		return $html;
	}

	$filter_apt_name = '';
	if ( isset( $_GET['mphb_attributes']['apartment-type'] ) ) {
		$apt_param = wp_unslash( $_GET['mphb_attributes']['apartment-type'] );
		if ( is_array( $apt_param ) ) {
			$apt_param = reset( $apt_param );
		}
		$apt_param = trim( (string) $apt_param );
		if ( $apt_param !== '' ) {
			$attr_tax = function_exists( 'mphb_attribute_taxonomy_name' )
				? mphb_attribute_taxonomy_name( 'apartment-type' )
				: 'mphb_apartment-type';

			$term = is_numeric( $apt_param ) ? get_term( (int) $apt_param, $attr_tax ) : get_term_by( 'slug', $apt_param, $attr_tax );

			if ( $term && ! is_wp_error( $term ) ) {
				$filter_apt_name = strtolower( trim( $term->name ) );
			}
		}
	}

	$all_ids = get_posts( array(
		'post_type'      => 'mphb_room_type',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	if ( empty( $all_ids ) ) return '';

	$items = array();
	foreach ( $all_ids as $post_id ) {
		$address = bsbt_get_address( $post_id );
		$city    = bsbt_get_city_from_address( $address );
		$city_n  = bsbt_normalize_city( $city );

		if ( $filter_city && $city_n && $city_n !== $filter_city ) continue;

		if ( $filter_apt_name !== '' ) {
			$cats = wp_get_post_terms( $post_id, 'mphb_room_type_category' );
			if ( is_wp_error( $cats ) || empty( $cats ) ) continue;
			$matched = false;
			foreach ( $cats as $cat ) {
				if ( strtolower( trim( $cat->name ) ) === $filter_apt_name ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) continue;
		}

		if ( ! $filter_apt_name && $guests_requested > 0 ) {
			$capacity = bsbt_get_room_capacity( $post_id );
			if ( $capacity > 0 && $capacity < $guests_requested ) continue;
		}

		$distance = bsbt_get_distance_km( $post_id );
		if ( $filter_max_dist !== null && $distance !== null && $distance > $filter_max_dist ) continue;

		if ( $check_in && $check_out && function_exists( 'mphb_is_room_type_available' ) ) {
			if ( ! mphb_is_room_type_available( $post_id, $check_in, $check_out ) ) continue;
		}

		$price_raw = bsbt_get_min_price_raw( $post_id );

		$items[] = array(
			'id'        => $post_id,
			'address'   => $address,
			'city'      => $city,
			'price_raw' => $price_raw,
		);
	}

	if ( $sort === 'price_asc' || $sort === 'price_desc' ) {
		usort( $items, function( $a, $b ) use ( $sort ) {
			$pa = is_numeric( $a['price_raw'] ) ? $a['price_raw'] : PHP_FLOAT_MAX;
			$pb = is_numeric( $b['price_raw'] ) ? $b['price_raw'] : PHP_FLOAT_MAX;
			if ( $pa === $pb ) return 0;
			return ( 'price_asc' === $sort ) ? ( ($pa < $pb) ? -1 : 1 ) : ( ($pa > $pb) ? -1 : 1 );
		});
	}

	$total_items = count( $items );
	$total_pages = (int) ceil( $total_items / $per_page );
	$paged       = min( $paged, $total_pages );
	$offset      = ( $paged - 1 ) * $per_page;
	$page_items  = array_slice( $items, $offset, $per_page );

	$html  = bsbt_acc_list_styles();
	$html .= '<div class="bsbt-acc-list">';
	foreach ( $page_items as $item ) {
		$item_data = bsbt_build_card_data( $item['id'], $check_in, $check_out, $nights, true, $item['price_raw'] );
		$html     .= bsbt_render_card_html( $item_data );
	}
	$html .= '</div>';

	if ( $total_pages > 1 ) $html .= bsbt_render_pagination( $total_pages, $paged );

	$html .= '<script>
	(function(){
		function bsbtSetSearchCount(){
			var chip = document.querySelector(".bsbt-search-header__chip--count");
			if(!chip) return;
			var count = ' . (int)$total_items . ';
			chip.textContent = count + " " + (count === 1 ? "apartment" : "apartments") + " found";
		}
		window.addEventListener("DOMContentLoaded", bsbtSetSearchCount);
	})();
	</script>';

	return $html;
}

/* ==========================================================================
 * 4) Стили
 * ========================================================================== */

function bsbt_acc_list_styles() {
	static $printed = false;
	if ( $printed ) return '';
	$printed = true;

	return '<style>
	.bsbt-acc-list{ width:100%; display:flex; flex-direction:column; gap:15px; }
	.bsbt-acc-card{ display:flex; flex-direction:row; gap:20px; background:#F8F8F8; border-radius:10px; padding:18px; align-items:stretch; height:250px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.08); transition:0.2s ease; width:100%; }
	.bsbt-acc-card:hover{ transform:translateY(-3px); box-shadow:0 8px 20px rgba(0,0,0,0.12); }
	.bsbt-acc-image-wrap{ flex:0 0 20%; max-width:20%; height:100%; }
	.bsbt-acc-image-wrap img{ width:100%; height:100%; object-fit:cover; border-radius:10px; display:block; }
	.bsbt-acc-content{ flex:1; display:flex; flex-direction:column; justify-content:space-between; }
	.bsbt-acc-title{ margin:0 0 8px; font-size:20px; font-weight:600; line-height:1.3; }
	.bsbt-acc-title a{ color:#082567; text-decoration:none; }
	.bsbt-acc-excerpt{ font-size:14px; color:#333; line-height:1.45; max-height:85px; overflow:hidden; margin-bottom:14px; }
	.bsbt-acc-bottom{ margin-top:auto; display:flex; align-items:flex-end; justify-content:space-between; gap:20px; }
	.bsbt-acc-price{ font-size:15px; font-weight:600; color:#082567; margin:0; }
	.bsbt-acc-tax-note{ font-size:12px; font-weight:400; color:#555; margin-left:4px; }
	.bsbt-acc-button{ padding:10px 16px; background:#082567; color:#E0B849; border-radius:10px; text-decoration:none; font-weight:600; transition:0.2s ease; white-space:nowrap; display:inline-flex; align-items:center; justify-content:center; min-width:120px; text-align:center; }
	.bsbt-acc-button:hover{ background:#E0B849; color:#082567; }
	.bsbt-acc-pagination{ margin-top:25px; text-align:center; }
	.bsbt-acc-pagination .page-numbers{ display:inline-block; margin:0 4px; padding:6px 10px; border-radius:4px; text-decoration:none; font-size:14px; color:#082567; background:#F0F0F0; }
	.bsbt-acc-pagination .page-numbers.current{ background:#082567; color:#E0B849; font-weight:600; }
	@media(max-width:768px){ .bsbt-acc-card{ flex-direction:column; height:auto; } .bsbt-acc-image-wrap{ max-width:100%; height:180px; flex:0 0 auto; } .bsbt-acc-bottom{ flex-direction:column; align-items:flex-start; } .bsbt-acc-button{ width:100%; min-width:0; } }
	</style>';
}

/* ==========================================================================
 * 5) Построение карточки
 * ========================================================================== */

function bsbt_build_card_data( $post_id, $check_in, $check_out, $nights, $search_mode, $price_raw_override = null ) {
	$title     = get_the_title( $post_id );
	$permalink = get_permalink( $post_id );
	$thumb     = get_the_post_thumbnail( $post_id, 'large' );
	$excerpt   = get_the_excerpt( $post_id ) ?: wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 28, '…' );

	$price_raw  = is_numeric( $price_raw_override ) ? $price_raw_override : bsbt_get_min_price_raw( $post_id );
	$price_html = '';

	if ( is_numeric( $price_raw ) ) {
		$is_total_mode = ( $search_mode && $nights > 0 );
		$display_price = $is_total_mode ? ( $price_raw * $nights ) : $price_raw;
		$formatted     = number_format_i18n( $display_price, 2 ) . ' €';

		if ( ! $is_total_mode ) {
			$price_html = 'from ' . $formatted . ' / night';
		} else {
			$price_html = $formatted . '<span class="bsbt-acc-tax-note"> (total for ' . intval( $nights ) . ' nights)</span>';
		}
		
		$price_html .= '<span class="bsbt-acc-tax-note"> incl. VAT</span>';
	} else {
		$price_html = 'Price N/A';
	}

	return array( 'id' => $post_id, 'title' => $title, 'link' => $permalink, 'thumb' => $thumb, 'excerpt' => $excerpt, 'price_html' => $price_html );
}

function bsbt_render_card_html( $data ) {
	$html = '<div class="bsbt-acc-card">';
	if ( $data['thumb'] ) $html .= '<div class="bsbt-acc-image-wrap"><a href="' . esc_url( $data['link'] ) . '">' . $data['thumb'] . '</a></div>';
	$html .= '<div class="bsbt-acc-content"><div class="bsbt-acc-top"><h3 class="bsbt-acc-title"><a href="' . esc_url( $data['link'] ) . '">' . esc_html( $data['title'] ) . '</a></h3>';
	if ( $data['excerpt'] ) $html .= '<div class="bsbt-acc-excerpt">' . esc_html( $data['excerpt'] ) . '</div>';
	$html .= '</div><div class="bsbt-acc-bottom"><div class="bsbt-acc-price">' . $data['price_html'] . '</div><a class="bsbt-acc-button" href="' . esc_url( $data['link'] ) . '">Check availability</a></div></div></div>';
	return $html;
}

/* ==========================================================================
 * 6) Пагинация
 * ========================================================================== */

function bsbt_render_pagination( $total_pages, $current_page ) {
	$big = 999999999;
	$links = paginate_links( array( 'base' => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ), 'format' => '?paged=%#%', 'current' => max( 1, $current_page ), 'total' => $total_pages, 'type' => 'array', 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) );
	if ( empty( $links ) ) return '';
	return '<div class="bsbt-acc-pagination">' . implode('', $links) . '</div>';
}
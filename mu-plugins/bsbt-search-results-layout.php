<?php
/**
 * Plugin Name: BSBT – Search Header
 * Description: Шорткод [bsbt_search_header] – компактный хедер с параметрами поиска + подпись и (опц.) счётчик найденных квартир.
 * Author: BS Business Travelling
 * Version: 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Локальный парсер даты
 */
function bsbt_sh_parse_date( $raw ) {
	if ( empty( $raw ) ) return null;
	if ( is_array( $raw ) ) {
		$raw = end( $raw );
	}
	$raw = trim( (string) $raw );
	if ( $raw === '' ) return null;

	$formats = array( 'Y-m-d', 'd/m/Y', 'd.m.Y' );
	foreach ( $formats as $f ) {
		$dt = DateTime::createFromFormat( $f, $raw );
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
 * Шорткод [bsbt_search_header]
 */
function bsbt_render_search_header() {

	// Даты / ночи
	$check_in_raw  = isset( $_GET['mphb_check_in_date'] )  ? wp_unslash( $_GET['mphb_check_in_date'] )  : '';
	$check_out_raw = isset( $_GET['mphb_check_out_date'] ) ? wp_unslash( $_GET['mphb_check_out_date'] ) : '';

	$check_in  = bsbt_sh_parse_date( $check_in_raw );
	$check_out = bsbt_sh_parse_date( $check_out_raw );

	$dates_label  = '';
	$nights_label = '';
	$nights       = 0;

	if ( $check_in && $check_out ) {
		$diff   = $check_in->diff( $check_out );
		$nights = max( 0, (int) $diff->days );
		$dates_label = $check_in->format( 'd.m.Y' ) . ' – ' . $check_out->format( 'd.m.Y' );
		if ( $nights > 0 ) {
			$nights_label = $nights . ' ' . ( $nights === 1 ? 'night' : 'nights' );
		}
	} elseif ( $check_in ) {
		$dates_label = $check_in->format( 'd.m.Y' );
	}

	// Гости
	$adults   = isset( $_GET['mphb_adults'] )   ? (int) $_GET['mphb_adults']   : 1;
	$children = isset( $_GET['mphb_children'] ) ? (int) $_GET['mphb_children'] : 0;

	$guests_label = '';
	if ( $adults > 0 || $children > 0 ) {
		$parts = array();
		if ( $adults > 0 ) {
			$parts[] = $adults . ' ' . ( $adults === 1 ? 'adult' : 'adults' );
		}
		if ( $children > 0 ) {
			$parts[] = $children . ' ' . ( $children === 1 ? 'child' : 'children' );
		}
		$guests_label = implode( ', ', $parts );
	}

	// Город / дистанция (на будущее)
	$city_raw   = isset( $_GET['bsbt_city'] ) ? sanitize_text_field( wp_unslash( $_GET['bsbt_city'] ) ) : '';
	$dist_raw   = isset( $_GET['bsbt_max_distance'] ) ? str_replace( ',', '.', wp_unslash( $_GET['bsbt_max_distance'] ) ) : '';
	$dist_label = '';
	if ( $dist_raw !== '' ) {
		$dist_val = floatval( $dist_raw );
		if ( $dist_val > 0 ) {
			$dist_label = '≤ ' . $dist_val . ' km to fairground';
		}
	}

	// Apartment type: берём id из GET и вытаскиваем имя терма атрибута
	$apt_type_label = '';
	if ( isset( $_GET['mphb_attributes']['apartment-type'] ) ) {

		$apt_param = wp_unslash( $_GET['mphb_attributes']['apartment-type'] );
		if ( is_array( $apt_param ) ) {
			$apt_param = reset( $apt_param );
		}
		$apt_param = trim( (string) $apt_param );

		if ( $apt_param !== '' ) {

			// Имя таксономии атрибутов MotoPress
			$taxonomy = function_exists( 'mphb_attribute_taxonomy_name' )
				? mphb_attribute_taxonomy_name( 'apartment-type' )
				: 'mphb_apartment-type';

			$term = null;
			if ( is_numeric( $apt_param ) ) {
				$term = get_term( (int) $apt_param, $taxonomy );
			} else {
				$term = get_term_by( 'slug', $apt_param, $taxonomy );
			}

			if ( $term && ! is_wp_error( $term ) ) {
				$apt_type_label = 'Apartment type: ' . $term->name;
			} else {
				// fallback — показываем как есть
				$apt_type_label = 'Apartment type: ' . $apt_param;
			}
		}
	}

	// Собираем чипы
	$chips = array();

	if ( $dates_label ) {
		$chips[] = $dates_label;
	}
	if ( $nights_label ) {
		$chips[] = $nights_label;
	}
	if ( $guests_label ) {
		$chips[] = $guests_label;
	}
	if ( $city_raw ) {
		$chips[] = $city_raw;
	}
	if ( $dist_label ) {
		$chips[] = $dist_label;
	}
	if ( $apt_type_label ) {
		$chips[] = $apt_type_label;
	}

	// Заголовок
	$title = $city_raw ? 'Apartments in ' . $city_raw : 'Apartments in Hannover';

	ob_start();
	?>
	<section class="bsbt-search-header">
		<div class="bsbt-search-header__top">
			<div class="bsbt-search-header__title">
				<?php echo esc_html( $title ); ?>
			</div>

			<div class="bsbt-search-header__chips">
				<?php foreach ( $chips as $chip ) : ?>
					<span class="bsbt-search-header__chip"><?php echo esc_html( $chip ); ?></span>
				<?php endforeach; ?>

				<span
					class="bsbt-search-header__chip bsbt-search-header__chip--count"
					data-singular="apartment"
					data-plural="apartments"
				></span>
			</div>
		</div>

		<div class="bsbt-search-header__note">
			Your booking will be confirmed by the accommodation provider within 24 hours.
			If it is not confirmed, we will offer you an alternative apartment.
		</div>
	</section>

	<style>
		.bsbt-search-header{
			margin:0 0 24px 0;
			padding:12px 18px;
			border-radius:16px;
			background:#f4f5f7;
			display:flex;
			flex-direction:column;
			justify-content:center;
			gap:8px;
			max-height:120px;
			overflow:hidden;
		}
		.bsbt-search-header__top{
			display:flex;
			align-items:center;
			justify-content:space-between;
			gap:12px;
		}
		.bsbt-search-header__title{
			font-size:20px; /* было 18 */
			font-weight:600;
			white-space:nowrap;
			overflow:hidden;
			text-overflow:ellipsis;
		}
		.bsbt-search-header__chips{
			display:flex;
			flex-wrap:wrap;
			gap:8px;
			align-items:baseline; /* чтобы текст во всех чипах был по одной линии */
			justify-content:flex-end;
		}
		.bsbt-search-header__chip{
			padding:3px 10px;     /* было 2px 8px */
			border-radius:999px;
			font-size:13px;       /* было 12 */
			line-height:1.4;
			background:#ffffff;
			box-shadow:0 0 0 1px rgba(15,23,42,0.04);
			white-space:nowrap;
		}
		.bsbt-search-header__chip--count{
			background:#212F54;
			color:#E0B849;
			font-weight:600;
		}
		/* если по какой-то причине текст не поставился → не показываем чип */
		.bsbt-search-header__chip--count:empty{
			display:none;
		}
		.bsbt-search-header__note{
			font-size:13px; /* было 11 */
			opacity:0.8;
			white-space:nowrap;
			overflow:hidden;
			text-overflow:ellipsis;
		}
		@media(max-width:768px){
			.bsbt-search-header{
				max-height:none;
				padding:10px 14px;
			}
			.bsbt-search-header__top{
				flex-direction:column;
				align-items:flex-start;
				gap:6px;
			}
			.bsbt-search-header__chips{
				justify-content:flex-start;
			}
			.bsbt-search-header__title{
				font-size:18px;   /* чуть меньше на мобиле, чтобы не ломало строку */
			}
			.bsbt-search-header__chip{
				font-size:12px;   /* чипы чуть компактнее на мобиле */
				padding:3px 8px;
			}
			.bsbt-search-header__note{
				font-size:12px;
				white-space:normal;
			}
		}
	</style>

	<script>
		// Запускаем после полной загрузки страницы, когда карточки уже в DOM
		window.addEventListener('load', function(){
			try{
				var countChip = document.querySelector('.bsbt-search-header__chip--count');
				if(!countChip) return;

				var cards = document.querySelectorAll('.bsbt-acc-card');
				var count = cards.length;

				if(count <= 0){
					// Нет квартир – чип останется пустым и скроется по CSS :empty
					return;
				}

				var singular = countChip.getAttribute('data-singular') || 'apartment';
				var plural   = countChip.getAttribute('data-plural')   || 'apartments';

				var label = (count === 1 ? singular : plural);
				countChip.textContent = count + ' ' + label + ' found';
			} catch(e){
				console.error('BSBT search header count error:', e);
			}
		});
	</script>
	<?php

	return ob_get_clean();
}
add_shortcode( 'bsbt_search_header', 'bsbt_render_search_header' );

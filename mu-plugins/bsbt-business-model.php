<?php
/**
 * Plugin Name: BSBT – Business Model Provider (V5.4.2 - Guards + Stable Sync)
 * Description: Полностью отключает налоговый статус для Model B и восстанавливает описание моделей в админке.
 * Version: 5.4.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BSBT_FEE', 0.15 );
define( 'BSBT_VAT_ON_FEE', 0.19 );
define( 'BSBT_META_MODEL', '_bsbt_business_model' );
define( 'BSBT_META_OWNER_PRICE', 'owner_price_per_night' );

/**
 * 1. РАСЧЕТ И СИНХРОНИЗАЦИЯ (Model B)
 */
function bsbt_calculate_final_gross_price( $owner_price ) {
    $owner_price = (float) $owner_price;
    if ( $owner_price <= 0 ) return 0;
    return round( $owner_price + ( $owner_price * BSBT_FEE * ( 1 + BSBT_VAT_ON_FEE ) ), 2 );
}

add_action( 'acf/save_post', function( $post_id ) {

    // ACF может вызывать save_post для 'options' и т.п.
    if ( ! is_numeric( $post_id ) ) return;

    $post_id = (int) $post_id;

    if ( get_post_type( $post_id ) !== 'mphb_room_type' ) return;

    // Безопасность от автосейва/ревизий (на всякий случай)
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    $model = get_post_meta( $post_id, BSBT_META_MODEL, true ) ?: 'model_a';

    // Синхронизация только для Model B
    if ( $model === 'model_b' ) {
        $owner_price = get_post_meta( $post_id, BSBT_META_OWNER_PRICE, true );
        $final_price = bsbt_calculate_final_gross_price( $owner_price );
        if ( $final_price > 0 ) {
            bsbt_sync_to_mphb_database( $post_id, $final_price );
        }
    }

}, 30 );

function bsbt_sync_to_mphb_database( $room_type_id, $price ) {
    if ( ! function_exists( 'MPHB' ) ) return;

    $room_type_id = (int) $room_type_id;
    $price        = (float) $price;

    if ( $room_type_id <= 0 || $price <= 0 ) return;

    $repo  = MPHB()->getRateRepository();
    $rates = $repo->findAllByRoomType( $room_type_id );

    foreach ( $rates as $rate ) {
        $rate_id = (int) $rate->getId();
        if ( $rate_id > 0 ) {
            update_post_meta( $rate_id, 'mphb_price', $price );
        }
    }
}

/**
 * 2. ЯДЕРНЫЙ ФИКС НАЛОГОВ (Nuclear Tax Fix)
 */
add_filter( 'woocommerce_product_is_taxable', 'bsbt_make_model_b_non_taxable', 10, 2 );
function bsbt_make_model_b_non_taxable( $is_taxable, $product ) {

    if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
        return $is_taxable;
    }

    $room_type_id = get_post_meta( (int) $product->get_id(), '_mphb_room_type_id', true );

    if ( $room_type_id ) {
        $model = get_post_meta( (int) $room_type_id, BSBT_META_MODEL, true ) ?: 'model_a';
        if ( $model === 'model_b' ) {
            return false;
        }
    }

    return $is_taxable;
}

add_action( 'woocommerce_before_calculate_totals', function( $cart ) {

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! $cart || ! is_object( $cart ) ) return;

    foreach ( $cart->get_cart() as $item ) {

        if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) continue;

        $product = $item['data'];

        if ( ! method_exists( $product, 'get_id' ) ) continue;

        $room_type_id = get_post_meta( (int) $product->get_id(), '_mphb_room_type_id', true );

        if ( $room_type_id ) {
            $model = get_post_meta( (int) $room_type_id, BSBT_META_MODEL, true ) ?: 'model_a';
            if ( $model === 'model_b' ) {
                // Для Model B: никаких налогов на товар в WooCommerce.
                $product->set_tax_status( 'none' );
                $product->set_tax_class( '' );
            }
        }
    }

}, 99 );

/**
 * 3. АДМИНКА (Метабокс с описанием)
 */
add_action( 'add_meta_boxes', function () {

    add_meta_box(
        'bsbt_m',
        'BSBT Business Model',
        function( $post ) {

            $m = get_post_meta( $post->ID, BSBT_META_MODEL, true ) ?: 'model_a';
            ?>
            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                    <input type="radio" name="bsbt_model" value="model_a" <?php checked($m,'model_a')?>>
                    Model A (Standard)
                </label>
                <p class="description" style="margin-left: 20px;">
                    VAT (7%) is calculated from the <strong>total price</strong>. Normal MPHB behavior.
                </p>
            </div>

            <div style="margin-bottom: 10px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                    <input type="radio" name="bsbt_model" value="model_b" <?php checked($m,'model_b')?>>
                    Model B (Tax on Fee)
                </label>
                <p class="description" style="margin-left: 20px;">
                    VAT is calculated <strong>only from the Service Fee (19%)</strong>. WooCommerce taxes are forced OFF.
                </p>
            </div>

            <hr>
            <p style="font-size: 11px; color: #666;">
                <em>Note: For Model B, make sure "Owner Price" field is filled. Prices will sync to Rates automatically on update.</em>
            </p>

            <input type="hidden" name="bsbt_nonce" value="<?php echo esc_attr( wp_create_nonce('bsbt_s') ); ?>">
            <?php
        },
        'mphb_room_type',
        'side',
        'high'
    );

});

add_action( 'save_post_mphb_room_type', function( $post_id ){

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    if ( empty($_POST['bsbt_model']) || empty($_POST['bsbt_nonce']) ) return;

    $nonce = sanitize_text_field( (string) $_POST['bsbt_nonce'] );
    if ( ! wp_verify_nonce( $nonce, 'bsbt_s' ) ) return;

    update_post_meta( (int) $post_id, BSBT_META_MODEL, sanitize_key( (string) $_POST['bsbt_model'] ) );

}, 10 );

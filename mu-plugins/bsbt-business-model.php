<?php
/**
 * Plugin Name: BSBT – Business Model Provider (V5.6.0 - Meta UI Only)
 * Description: Полностью отключает налоговый статус для Model B и восстанавливает описание моделей в админке.
 * Version: 5.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BSBT_FEE', 0.15 );
define( 'BSBT_VAT_ON_FEE', 0.19 );
define( 'BSBT_META_MODEL', '_bsbt_business_model' );
define( 'BSBT_META_OWNER_PRICE', 'owner_price_per_night' );

/**
 * 1. АДМИНКА (Метабокс с описанием)
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
                    <input type="radio" name="bsbt_model" value="model_a" <?php checked($m,'model_a')?> >
                    Model A (Standard)
                </label>
                <p class="description" style="margin-left: 20px;">
                    VAT (7%) is calculated from the <strong>total price</strong>. Normal MPHB behavior.
                </p>
            </div>

            <div style="margin-bottom: 10px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                    <input type="radio" name="bsbt_model" value="model_b" <?php checked($m,'model_b')?> >
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

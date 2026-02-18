<?php
/**
 * Plugin Name: BSBT – Owner Core (Binding + Resolver)
 * Description: RU: Ядро владельцев. Привязка Owner (WP User) ↔ Apartment (mphb_room_type) через bsbt_owner_id + единый провайдер данных владельца с fallback на старые meta-поля квартиры.
 * Version: 1.0.1
 * Author: BSBT / Stay4Fair
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
   0) КОНСТАНТЫ / META KEYS
   ========================================================= */

if ( ! defined( 'BSBT_META_OWNER_ID' ) ) {
    define( 'BSBT_META_OWNER_ID', 'bsbt_owner_id' );
}

// Старые (legacy) поля владельца, которые исторически лежат в meta квартиры (mphb_room_type)
if ( ! defined( 'BSBT_LEGACY_OWNER_NAME' ) ) {
    define( 'BSBT_LEGACY_OWNER_NAME', 'owner_name' );
}
if ( ! defined( 'BSBT_LEGACY_OWNER_EMAIL' ) ) {
    define( 'BSBT_LEGACY_OWNER_EMAIL', 'owner_email' );
}
if ( ! defined( 'BSBT_LEGACY_OWNER_PHONE' ) ) {
    define( 'BSBT_LEGACY_OWNER_PHONE', 'owner_phone' );
}

// Пользовательские meta ключи владельца (user_meta) — будем наполнять позже регистрацией/профилем
if ( ! defined( 'BSBT_USER_META_PHONE' ) ) {
    define( 'BSBT_USER_META_PHONE', 'bsbt_phone' );      // рекомендуемый ключ под телефон
}
if ( ! defined( 'BSBT_USER_META_WHATS' ) ) {
    define( 'BSBT_USER_META_WHATS', 'bsbt_whatsapp' );   // если нужен отдельный WhatsApp (можно = phone)
}

/* =========================================================
   1) METABOX: ВЫБОР OWNER ДЛЯ mphb_room_type
   ========================================================= */

add_action( 'add_meta_boxes_mphb_room_type', function( $post ) {

    add_meta_box(
        'bsbt_owner_binding',
        'BSBT – Apartment Owner',
        'bsbt_owner_core_render_metabox',
        'mphb_room_type',
        'side',
        'high'
    );

});

/**
 * Metabox renderer
 */
function bsbt_owner_core_render_metabox( $post ) {

    $current_owner_id = (int) get_post_meta( $post->ID, BSBT_META_OWNER_ID, true );

    wp_nonce_field( 'bsbt_owner_core_save', 'bsbt_owner_core_nonce' );

    // RU: Роль владельца у тебя сейчас называется "owner".
    // Если позже сменишь на bsbt_owner — поменяй здесь role => 'bsbt_owner'
    $owners = get_users([
        'role'    => 'owner',
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'display_name', 'user_email'],
    ]);

    echo '<p style="margin:0 0 8px 0;"><strong>Select Owner:</strong></p>';
    echo '<select name="bsbt_owner_id" style="width:100%;">';
    echo '<option value="">— Not assigned —</option>';

    foreach ( $owners as $owner ) {
        printf(
            '<option value="%d" %s>%s (#%d)</option>',
            (int) $owner->ID,
            selected( $current_owner_id, (int) $owner->ID, false ),
            esc_html( $owner->display_name ),
            (int) $owner->ID
        );
    }

    echo '</select>';

    echo '<p style="margin-top:10px;font-size:12px;color:#64748b;line-height:1.35;">';
    echo 'Meta key: <code>' . esc_html( BSBT_META_OWNER_ID ) . '</code><br>';
    echo 'Current Owner ID: <strong>' . ( $current_owner_id ? (int)$current_owner_id : '—' ) . '</strong>';
    echo '</p>';
}

/* =========================================================
   2) SAVE: СОХРАНЕНИЕ OWNER ID (АДМИН ИМЕЕТ МАКС. ГИБКОСТЬ)
   ========================================================= */

add_action( 'save_post_mphb_room_type', function( $post_id ) {

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    // RU: Не ломаем поток, но базовую безопасность добавим.
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( ! isset($_POST['bsbt_owner_core_nonce']) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field($_POST['bsbt_owner_core_nonce']), 'bsbt_owner_core_save' ) ) return;

    // RU: Админ может менять owner всегда — по твоему ТЗ.
    // Доп. проверку прав можно добавить позже, но сейчас не ограничиваем, чтобы не сломать поток.
    if ( isset($_POST['bsbt_owner_id']) ) {

        $owner_id = (int) absint( $_POST['bsbt_owner_id'] );

        if ( $owner_id > 0 ) {
            update_post_meta( $post_id, BSBT_META_OWNER_ID, $owner_id );
        } else {
            delete_post_meta( $post_id, BSBT_META_OWNER_ID );
        }
    }

}, 10 );

/* =========================================================
   3) AUTO-BIND: ЕСЛИ OWNER САМ СОЗДАЛ КВАРТИРУ — ПРИВЯЗЫВАЕМ АВТОМАТИЧЕСКИ
   ========================================================= */

add_action( 'save_post_mphb_room_type', function( $post_id ) {

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    // Уже назначено — не трогаем
    $existing = (int) get_post_meta( $post_id, BSBT_META_OWNER_ID, true );
    if ( $existing > 0 ) return;

    // RU: Только если реальный owner создавал/сохранял пост
    $user = wp_get_current_user();
    if ( ! $user || empty($user->ID) ) return;

    if ( in_array( 'owner', (array)$user->roles, true ) ) {
        update_post_meta( $post_id, BSBT_META_OWNER_ID, (int) $user->ID );
    }

}, 20 );

/* =========================================================
   4) RESOLVER: ЕДИНЫЙ ИСТОЧНИК ДАННЫХ ВЛАДЕЛЬЦА (С FALLBACK)
   ========================================================= */

/**
 * RU: Возвращает owner_id для Room Type.
 */
function bsbt_owner_core_get_owner_id( $room_type_id ) {
    $room_type_id = (int) $room_type_id;
    if ( $room_type_id <= 0 ) return 0;

    $owner_id = (int) get_post_meta( $room_type_id, BSBT_META_OWNER_ID, true );
    return $owner_id > 0 ? $owner_id : 0;
}

/**
 * RU: Универсальный провайдер данных владельца по room_type_id.
 * - Сначала берём WP User (bsbt_owner_id)
 * - Телефон/WhatsApp — из user_meta (если есть)
 * - Если чего-то нет — fallback на legacy meta квартиры (owner_name/email/phone)
 *
 * Возвращает массив:
 * [
 *   'id'      => int,
 *   'name'    => string,
 *   'email'   => string,
 *   'phone'   => string,
 *   'whatsapp'=> string,
 *   'source'  => 'user'|'legacy'
 * ]
 */
function bsbt_owner_core_get_owner_data( $room_type_id ) {

    $room_type_id = (int) $room_type_id;
    if ( $room_type_id <= 0 ) {
        return [
            'id'       => 0,
            'name'     => '',
            'email'    => '',
            'phone'    => '',
            'whatsapp' => '',
            'source'   => 'legacy',
        ];
    }

    $owner_id = bsbt_owner_core_get_owner_id( $room_type_id );

    // =========================
    // 1) NEW: WP USER
    // =========================
    if ( $owner_id ) {

        $user = get_userdata( $owner_id );

        if ( $user ) {

            $phone = (string) get_user_meta( $owner_id, BSBT_USER_META_PHONE, true );
            $wa    = (string) get_user_meta( $owner_id, BSBT_USER_META_WHATS, true );

            // RU: Если WhatsApp пустой — можно считать, что он равен телефону.
            if ( $wa === '' ) $wa = $phone;

            // RU: Fallback на legacy-поля квартиры, если user_meta ещё не заполнены
            $legacy_name  = (string) get_post_meta( $room_type_id, BSBT_LEGACY_OWNER_NAME, true );
            $legacy_email = (string) get_post_meta( $room_type_id, BSBT_LEGACY_OWNER_EMAIL, true );
            $legacy_phone = (string) get_post_meta( $room_type_id, BSBT_LEGACY_OWNER_PHONE, true );

            $name  = $user->display_name ? (string)$user->display_name : $legacy_name;
            $email = $user->user_email   ? (string)$user->user_email   : $legacy_email;

            if ( $phone === '' ) $phone = $legacy_phone;
            if ( $wa === '' )    $wa    = $legacy_phone;

            return [
                'id'       => (int) $owner_id,
                'name'     => $name,
                'email'    => $email,
                'phone'    => $phone,
                'whatsapp' => $wa,
                'source'   => 'user',
            ];
        }
    }

    // =========================
    // 2) LEGACY: ROOM META
    // =========================
    return [
        'id'       => 0,
        'name'     => (string) get_post_meta( $room_type_id, BSBT_LEGACY_OWNER_NAME, true ),
        'email'    => (string) get_post_meta( $room_type_id, BSBT_LEGACY_OWNER_EMAIL, true ),
        'phone'    => (string) get_post_meta( $room_type_id, BSBT_LEGACY_OWNER_PHONE, true ),
        'whatsapp' => (string) get_post_meta( $room_type_id, BSBT_LEGACY_OWNER_PHONE, true ),
        'source'   => 'legacy',
    ];
}

/* =========================================================
   5) СОВМЕСТИМОСТЬ: МИНИ-ХЕЛПЕР ДЛЯ БЫСТРОГО ВНЕДРЕНИЯ
   ========================================================= */

/**
 * RU: Быстрый хелпер, чтобы в новых местах не писать массивы.
 */
function bsbt_owner_core_get_owner_name( $room_type_id ) {
    $d = bsbt_owner_core_get_owner_data( $room_type_id );
    return (string) ($d['name'] ?? '');
}

/* =========================================================
   6) OWNER PROFILE EXTENSION (IBAN + TAX)
   ========================================================= */

add_action( 'show_user_profile', 'bsbt_owner_core_user_fields' );
add_action( 'edit_user_profile', 'bsbt_owner_core_user_fields' );

function bsbt_owner_core_user_fields( $user ) {

    if ( ! in_array( 'owner', (array) $user->roles, true ) ) return;

    ?>
    <h2>Bank- und Steuerdaten des Eigentümers</h2>

    <table class="form-table">
        <tr>
            <th><label for="bsbt_iban">IBAN</label></th>
            <td>
                <input type="text" name="bsbt_iban" id="bsbt_iban"
                       value="<?php echo esc_attr( get_user_meta( $user->ID, 'bsbt_iban', true ) ); ?>"
                       class="regular-text" />
            </td>
        </tr>

        <tr>
            <th><label for="bsbt_account_holder">Kontoinhaber</label></th>
            <td>
                <input type="text" name="bsbt_account_holder" id="bsbt_account_holder"
                       value="<?php echo esc_attr( get_user_meta( $user->ID, 'bsbt_account_holder', true ) ); ?>"
                       class="regular-text" />
            </td>
        </tr>

        <tr>
            <th><label for="bsbt_tax_number">Steuernummer</label></th>
            <td>
                <input type="text" name="bsbt_tax_number" id="bsbt_tax_number"
                       value="<?php echo esc_attr( get_user_meta( $user->ID, 'bsbt_tax_number', true ) ); ?>"
                       class="regular-text" />
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'bsbt_owner_core_save_user_fields' );
add_action( 'edit_user_profile_update', 'bsbt_owner_core_save_user_fields' );

function bsbt_owner_core_save_user_fields( $user_id ) {

    if ( ! current_user_can( 'edit_user', $user_id ) ) return;

    update_user_meta( $user_id, 'bsbt_iban',
        sanitize_text_field( $_POST['bsbt_iban'] ?? '' )
    );

    update_user_meta( $user_id, 'bsbt_account_holder',
        sanitize_text_field( $_POST['bsbt_account_holder'] ?? '' )
    );

    update_user_meta( $user_id, 'bsbt_tax_number',
        sanitize_text_field( $_POST['bsbt_tax_number'] ?? '' )
    );
}

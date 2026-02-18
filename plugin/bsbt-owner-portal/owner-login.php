<?php
/**
 * Plugin Name: BSBT – Owner Login & Portal Access
 * Description: Версия 2.3.2: Центровка футера формы (Passwort vergessen).
 * Version: 2.3.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'logout-button.php';

final class BSBT_Owner_Login_System {

    private $navy = '#082567';
    private $gold = '#E0B849';

    public function __construct() {
        add_shortcode( 'bsbt_owner_login', [ $this, 'render_login_form' ] );
        add_action( 'wp_head', [ $this, 'inject_global_styles' ] );
        add_filter( 'gettext', [ $this, 'translate_wc_texts' ], 999, 3 );
        add_action( 'init', [$this, 'handle_owner_logout'] );
        add_action( 'woocommerce_lostpassword_form', [ $this, 'add_back_to_login_link' ] );
    }

    /* ... handle_owner_logout, translate_wc_texts, add_back_to_login_link остаются без изменений ... */
    public function handle_owner_logout() {
        if ( isset($_GET['action']) && $_GET['action'] === 'owner_logout' ) {
            wp_logout();
            wp_safe_redirect( site_url('/owner-login/?logout=success') );
            exit;
        }
    }

    public function translate_wc_texts( $translated, $text, $domain ) {
        if ( $domain === 'woocommerce' || $domain === 'default' ) {
            $maps = [
                'Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.' =>
                    'Passwort vergessen? Bitte gib deinen Benutzernamen oder deine E-Mail-Adresse ein.',
                'Username or email' => 'Benutzername oder E-Mail',
                'Reset password' => 'Passwort zurücksetzen'
            ];
            if ( isset($maps[$text]) ) return $maps[$text];
        }
        return $translated;
    }

    public function add_back_to_login_link() {
        echo '<div class="bsbt-back-to-login">
                <a href="'.esc_url(site_url('/owner-login/')).'">← Zurück zum Login</a>
              </div>';
    }

    public function render_login_form() {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( in_array('owner', (array)$user->roles, true) || current_user_can('manage_options') ) {
                wp_safe_redirect( site_url('/owner-dashboard/') );
                exit;
            }
            return '<div class="bsbt-error-box">Zugriff verweigert. Kein Owner-Konto.</div>';
        }

        $error = '';
        $redirect_to = isset($_GET['redirect_to']) ? wp_validate_redirect(wp_unslash($_GET['redirect_to']), '') : '';
        if ( ! $redirect_to ) $redirect_to = site_url('/owner-dashboard/');

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bsbt_login_submit']) ) {
            $post_redirect = isset($_POST['redirect_to']) ? wp_validate_redirect(wp_unslash($_POST['redirect_to']), '') : '';
            if ( $post_redirect ) $redirect_to = $post_redirect;

            $creds = [
                'user_login'    => sanitize_text_field($_POST['log']),
                'user_password' => $_POST['pwd'],
                'remember'      => isset($_POST['rememberme'])
            ];

            $user = wp_signon($creds, is_ssl());

            if ( is_wp_error($user) ) {
                $error = 'Falsche Zugangsdaten.';
            } else {
                if ( ! in_array('owner', (array)$user->roles, true) && ! current_user_can('manage_options') ) {
                    wp_logout();
                    $error = 'Zugriff verweigert. Kein Owner-Konto.';
                } else {
                    wp_safe_redirect( $redirect_to );
                    exit;
                }
            }
        }

        ob_start(); ?>
        <div class="bsbt-login-page-wrapper">
            <div class="bsbt-login-card">
                <div class="bsbt-login-header" style="text-align: center; margin-bottom: 30px;">
                    <h2 style="color: <?= $this->navy ?>; font-size: 28px; font-weight: 800; margin: 0;">Eigentümer Login</h2>
                    <p style="color: #64748b; font-size: 14px;">Partner-Portal Stay4Fair</p>
                </div>

                <?php if ( $error ) : ?><div class="bsbt-error-box"><?= esc_html($error) ?></div><?php endif; ?>
                <?php if ( isset($_GET['logout']) ) : ?><div class="bsbt-success-box">Abgemeldet.</div><?php endif; ?>

                <form method="post">
                    <input type="hidden" name="redirect_to" value="<?= esc_attr($redirect_to) ?>">
                    <div class="bsbt-input-group">
                        <label>Benutzername oder E-Mail</label>
                        <input type="text" name="log" required>
                    </div>
                    <div class="bsbt-input-group">
                        <label>Passwort</label>
                        <input type="password" name="pwd" required>
                    </div>
                    <button type="submit" name="bsbt_login_submit" class="bsbt-cta-button">Einloggen</button>
                    
                    <div class="bsbt-form-footer">
                        <a href="<?= esc_url( wp_lostpassword_url() ) ?>">Passwort vergessen?</a>
                    </div>
                </form>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    public function inject_global_styles() {
        if ( ! is_page('owner-login') && ! is_page('lost-password') ) return;
        ?>
        <style>
        .bsbt-login-page-wrapper { display:flex; align-items:center; justify-content:center; min-height:80vh; padding:40px 20px; background:#fff; }
        .bsbt-login-card { width:100%; max-width:440px; padding:40px; border-radius:20px; background:#fff; box-shadow:0 20px 50px rgba(0,0,0,0.1); border:1px solid #f0f0f0; }
        .bsbt-input-group { margin-bottom:20px; }
        .bsbt-input-group label { display:block; font-weight:600; margin-bottom:8px; color: <?= $this->navy ?>; }
        .bsbt-input-group input { width:100%; height:52px; border-radius:10px; border:1px solid #ddd; padding:0 15px; }

        .bsbt-cta-button {
            position:relative; display:flex; align-items:center; justify-content:center; width:100%; height:60px;
            border-radius:12px; border:none; cursor:pointer; font-weight:800; text-transform:uppercase;
            background-color: <?= $this->navy ?>; color: <?= $this->gold ?>;
            background-image: linear-gradient(180deg, rgba(255,255,255,0.25) 0%, rgba(0,0,0,0.2) 100%);
            box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30);
            transition:all 0.25s ease;
        }
        .bsbt-cta-button:hover { background-color: <?= $this->gold ?>; color: <?= $this->navy ?>; transform:translateY(-2px); }

        /* ЦЕНТРОВКА И ОТСТУП ДЛЯ ССЫЛКИ */
        .bsbt-form-footer {
            margin-top: 25px;
            text-align: center;
            width: 100%;
        }
        .bsbt-form-footer a {
            color: #94a3b8;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .bsbt-form-footer a:hover { color: <?= $this->navy ?>; text-decoration: underline; }

        .bsbt-error-box, .bsbt-success-box { padding:15px; border-radius:10px; margin-bottom:20px; text-align:center; }
        .bsbt-error-box { background:#fef2f2; color:#b91c1c; }
        .bsbt-success-box { background:#f0fdf4; color:#166534; }

        @media (max-width:480px){ .bsbt-login-card { padding:28px; } .bsbt-cta-button { height:54px; font-size:14px; } }
        </style>
        <?php
    }
}
new BSBT_Owner_Login_System();
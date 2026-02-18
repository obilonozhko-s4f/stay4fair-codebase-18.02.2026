<?php
/**
 * Plugin Name: BSBT – Security Guard (Owner Login)
 * Description: Brute force protection + rate limiting + throttled email alerts for /owner-login/.
 * Version: 1.2.1
 * Author: BSBT / Stay4Fair
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class BSBT_Security_Guard_Owner_Login
 * * RU: Профессиональная система защиты фронтенд-логина.
 * Включает: изоляцию эндпоинта, ограничение частоты (Rate Limit), защиту от подбора (Brute Force)
 * и умные уведомления с защитой от почтового спама.
 * * EN: Professional frontend login protection system.
 * Includes: endpoint isolation, Rate Limiting, Brute Force protection,
 * and smart notifications with email spam protection (throttling).
 */
final class BSBT_Security_Guard_Owner_Login {

    /* =========================
     * SETTINGS / НАСТРОЙКИ
     * ========================= */
    
    // RU: URL-slug страницы логина. EN: Login page URL slug.
    const OWNER_LOGIN_SLUG = 'owner-login';

    // RU: Brute Force: попыток до блокировки. EN: Brute Force: attempts before lockout.
    const MAX_FAILS        = 5;
    
    // RU: Brute Force: время блокировки (мин). EN: Brute Force: lockout duration (min).
    const LOCK_MINUTES     = 15;

    // RU: Rate Limit: пауза между запросами (сек). EN: Rate Limit: pause between requests (sec).
    const MIN_INTERVAL_SEC = 3;

    // RU: Лимит отправки писем (раз в 30 мин). EN: Email throttle (once per 30 min).
    const EMAIL_THROTTLE_MIN = 30;

    // RU: Логирование в error.log. EN: Logging to error.log.
    const DEBUG_LOG        = false;

    public function __construct() {
        // RU: Проверка лимитов ПЕРЕД аутентификацией. EN: Check limits BEFORE auth.
        add_filter( 'authenticate', [ $this, 'guard_before_auth' ], 1, 3 );

        // RU: Регистрация ошибки входа. EN: Register login failure.
        add_action( 'wp_login_failed', [ $this, 'on_login_failed' ], 10, 1 );

        // RU: Очистка счетчиков при успехе. EN: Clear counters on success.
        add_action( 'wp_login', [ $this, 'on_login_success' ], 10, 2 );
    }

    /* =========================
     * CORE LOGIC / ЯДРО
     * ========================= */

    public function guard_before_auth( $user, $username, $password ) {

        // RU: Защищаем только портал владельца. EN: Only protect the owner portal.
        if ( ! $this->is_owner_login_request() ) {
            return $user;
        }

        $ip = $this->get_ip();
        if ( ! $ip ) return $user;

        $username = is_string($username) ? trim($username) : '';
        $id_login = $username !== '' ? $username : '__unknown__';

        // --- 1. Rate Limiting (Anti-Spam) ---
        $rl_key = $this->key('rl', $ip);
        $now    = time();
        $last   = (int) get_transient( $rl_key );

        if ( $last > 0 && ( $now - $last ) < self::MIN_INTERVAL_SEC ) {
            $this->log('RATE_LIMIT', ['ip' => $ip, 'username' => $id_login]);
            return new WP_Error(
                'bsbt_rate_limited',
                'Zu viele Versuche. Bitte warten Sie kurz.'
            );
        }
        set_transient( $rl_key, $now, self::MIN_INTERVAL_SEC + 2 );

        // --- 2. Brute Force Lockout ---
        $lock_key = $this->key('lock', $ip . '|' . strtolower($id_login));
        $locked_until = (int) get_transient( $lock_key );

        if ( $locked_until && $locked_until > $now ) {
            $mins = (int) ceil( ($locked_until - $now) / 60 );
            $this->log('LOCKED_ACCESS_DENIED', ['ip' => $ip, 'username' => $id_login]);
            return new WP_Error(
                'bsbt_locked',
                'Zu viele Fehlversuche. Bitte warten Sie ' . $mins . ' Min.'
            );
        }

        return $user;
    }

    public function on_login_failed( $username ) {
        if ( ! $this->is_owner_login_request() ) return;

        $ip = $this->get_ip();
        if ( ! $ip ) return;

        $id_login = is_string($username) ? strtolower(trim($username)) : '__unknown__';

        // RU: Увеличиваем счетчик ошибок. EN: Increment fail counter.
        $fail_key = $this->key('fails', $ip . '|' . $id_login);
        $fails    = (int) get_transient( $fail_key );
        $fails++;

        set_transient( $fail_key, $fails, self::LOCK_MINUTES * 60 );
        $this->log('LOGIN_FAIL', ['ip' => $ip, 'user' => $id_login, 'count' => $fails]);

        // RU: Если лимит превышен — блокируем и уведомляем. EN: If limit reached — lock and notify.
        if ( $fails >= self::MAX_FAILS ) {
            $lock_key = $this->key('lock', $ip . '|' . $id_login);
            set_transient( $lock_key, time() + (self::LOCK_MINUTES * 60), self::LOCK_MINUTES * 60 );
            
            $this->log('LOCKOUT_TRIGGERED', ['ip' => $ip, 'user' => $id_login]);
            $this->send_lock_notification($id_login, $ip);
        }
    }

    public function on_login_success( $user_login, $user ) {
        if ( ! $this->is_owner_login_request() ) return;

        $ip = $this->get_ip();
        if ( ! $ip ) return;

        $id_login = strtolower(trim($user_login));

        // RU: Сброс всех блокировок при успешном входе. EN: Reset all locks on successful login.
        delete_transient( $this->key('fails', $ip . '|' . $id_login) );
        delete_transient( $this->key('lock',  $ip . '|' . $id_login) );
        
        $this->log('LOGIN_SUCCESS_CLEARED', ['ip' => $ip, 'user' => $id_login]);
    }

    /* =========================
     * NOTIFICATIONS / ПОЧТА
     * ========================= */

    private function send_lock_notification( $username, $ip ) {
        // RU: Троттлинг писем — не чаще раза в 30 мин для пары IP+Логин.
        // EN: Email throttling — max once per 30 min for IP+Login pair.
        $throttle_key = $this->key('email_sent', $username . '|' . $ip);
        if ( get_transient($throttle_key) ) {
            $this->log('EMAIL_THROTTLED', ['user' => $username]);
            return;
        }

        $user = get_user_by( 'login', $username );
        if ( ! $user ) $user = get_user_by( 'email', $username );

        $to = [ get_option( 'admin_email' ) ];
        if ( $user ) $to[] = $user->user_email;

        $subject = 'Stay4Fair: Login-Sperre aktiv';
        $message = "Sicherheits-Benachrichtigung für Ihr Stay4Fair Partner-Portal.\n\n" .
                   "Konto: " . $username . "\n" .
                   "IP-Adresse: " . $ip . "\n" .
                   "Status: Gesperrt für " . self::LOCK_MINUTES . " Minuten.\n\n" .
                   "Falls Sie dies nicht waren, ändern Sie bitte Ihr Passwort.\n" .
                   "Ihr Stay4Fair Team";

        if ( wp_mail( $to, $subject, $message ) ) {
            set_transient($throttle_key, 1, self::EMAIL_THROTTLE_MIN * MINUTE_IN_SECONDS);
            $this->log('EMAIL_SENT', ['to' => $to]);
        }
    }

    /* =========================
     * HELPERS / ВСПОМОГАТЕЛЬНЫЕ
     * ========================= */

    private function is_owner_login_request(): bool {
        if ( is_admin() ) return false;
        $uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string)$_SERVER['REQUEST_URI']) : '';
        
        $is_uri  = ( strpos($uri, '/' . strtolower(self::OWNER_LOGIN_SLUG)) !== false );
        $is_post = ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bsbt_login_submit']) );

        return ( $is_uri || $is_post );
    }

    private function get_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return ( strlen($ip) > 6 && strlen($ip) < 64 ) ? trim($ip) : '';
    }

    private function key(string $prefix, string $raw): string {
        return 'bsbt_sg_' . $prefix . '_' . substr( md5( $raw ), 0, 16 );
    }

    private function log(string $event, array $ctx = []): void {
        if ( ! self::DEBUG_LOG ) return;
        error_log('[BSBT_SG] ' . $event . ' ' . wp_json_encode($ctx));
    }
}

// RU: Инициализация. EN: Initialization.
new BSBT_Security_Guard_Owner_Login();
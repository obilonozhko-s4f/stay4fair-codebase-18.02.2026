<?php
/**
 * Plugin Name: BSBT – Access Guard
 * Description: Глобальный контроль доступа STAY4FAIR (Secure & Role-Safe)
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class BSBT_Access_Guard {

    /**
     * Слаги страниц кабинета (защищаем фронт)
     * ВАЖНО: тут именно SLUG страницы (а не URL)
     */
    private $protected_slugs = [
        'owner-dashboard',
        'owner-bookings',
        'owner-dashboard-finanzen',
        'apartment-status',
        'owner-profile',
        'owner-calendar',
    ];

    public function __construct() {
        // template_redirect — нормальный момент для редиректов
        add_action('template_redirect', [$this, 'enforce_owner_access'], 1);
    }

    public function enforce_owner_access() {

        // 0) Мы вообще не на странице? (страховка)
        if ( ! is_page() ) return;

        // 1) Если страница не из защищённых — ничего не делаем
        if ( ! is_page( $this->protected_slugs ) ) return;

        // 2) Если не залогинен — на кастомный логин + redirect обратно
        if ( ! is_user_logged_in() ) {
            $this->redirect_to_login();
        }

        // 3) Залогинен, но роль не owner/admin — запрещаем (или можно редиректить)
        if ( ! $this->is_owner_or_admin() ) {
            // Безопаснее не показывать детали, и не давать увидеть кабинет даже частично.
            // Можно редиректить на /owner-login/ с флагом "forbidden".
            $login_page   = site_url('/owner-login/');
            $redirect_url = add_query_arg('forbidden', '1', $login_page);
            wp_safe_redirect($redirect_url);
            exit;
        }

        // Всё ок — пропускаем.
    }

    private function is_owner_or_admin(): bool {
        if ( current_user_can('manage_options') ) return true;

        $u = wp_get_current_user();
        if ( ! $u || empty($u->roles) ) return false;

        return in_array('owner', (array)$u->roles, true);
    }

    private function redirect_to_login(): void {
        // Собираем текущий URL максимально безопасно:
        // home_url( add_query_arg([], $wp->request) ) часто норм, но тут проще и надежнее:
        $scheme = is_ssl() ? 'https' : 'http';

        // sanitize + защита от мусора
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field($_SERVER['HTTP_HOST']) : parse_url(home_url(), PHP_URL_HOST);
        $uri  = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '/';

        // Собираем полный URL
        $current_url = $scheme . '://' . $host . $uri;

        // Доп. защита: возвращаем только если это наш домен
        $home_host = parse_url(home_url(), PHP_URL_HOST);
        $cur_host  = parse_url($current_url, PHP_URL_HOST);

        if ( $home_host && $cur_host && strtolower($home_host) !== strtolower($cur_host) ) {
            // если вдруг не совпало — возвращаем на дашборд по умолчанию
            $current_url = site_url('/owner-dashboard/');
        }

        $login_page = site_url('/owner-login/');
        $redirect_url = add_query_arg(
            [
                'redirect_to' => rawurlencode($current_url),
                'reason'      => 'session',
            ],
            $login_page
        );

        wp_safe_redirect($redirect_url);
        exit;
    }
}

new BSBT_Access_Guard();

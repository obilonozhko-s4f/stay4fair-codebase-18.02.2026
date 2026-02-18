<?php
/**
 * Часть системы BSBT Owner Portal
 * Кнопка с минимальным фреймом (схлопывание под контент)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode('bsbt_logout_button', function() {
    if (!is_user_logged_in()) return '';

    ob_start(); ?>
    <style>
        /* Убираем расширение контейнера: фрейм будет размером с кнопку */
        .bsbt-logout-wrapper { 
            display: inline-flex !important; /* Ключевое: схлопывает розовый фрейм */
            margin: 0 !important;
            padding: 0 !important;
            vertical-align: middle !important;
            line-height: 1 !important;
        }

        .bsbt-logout-link {
            /* TOTAL 3D VOLUME SYSTEM V3.1 */
            position: relative !important;
            overflow: hidden !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 10px !important;
            
            /* ТОЧНЫЕ РАЗМЕРЫ (подгони под синюю) */
            padding: 10px 20px !important; 
            border-radius: 10px !important;
            border: none !important;
            
            /* СТИЛЬ: Светло-серый Slate */
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            background-image: linear-gradient(180deg, rgba(255,255,255,0.4) 0%, rgba(0,0,0,0.1) 100%) !important;
            background-blend-mode: overlay;

            /* ТВОИ ФИРМЕННЫЕ ТЕНИ */
            box-shadow: 0 10px 20px rgba(0,0,0,0.1), 
                        0 4px 6px rgba(0,0,0,0.05), 
                        inset 0 -4px 8px rgba(0,0,0,0.15), 
                        inset 0 1px 0 rgba(255,255,255,0.8), 
                        inset 0 0 0 1px rgba(255,255,255,0.06) !important;
            
            transition: all 0.25s ease !important;
            cursor: pointer !important;
            text-decoration: none !important;
            font-weight: 800 !important;
            font-size: 14px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            z-index: 2;
            white-space: nowrap !important; /* Чтобы текст не переносился */
        }

        /* ЭФФЕКТ КУПОЛА V3.1 */
        .bsbt-logout-link::before {
            content: "" !important;
            position: absolute !important;
            top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important;
            background: radial-gradient(ellipse at center, rgba(255,255,255,0.8) 0%, rgba(255,255,255,0.00) 72%) !important;
            transform: scaleY(0.48) !important;
            filter: blur(5px) !important;
            opacity: 0.65 !important;
            z-index: 1 !important;
            pointer-events: none !important;
        }

        .bsbt-logout-link:hover {
            background-color: #ffffff !important;
            color: #e11d48 !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 12px 24px rgba(225, 29, 72, 0.15), 
                        inset 0 -4px 8px rgba(0,0,0,0.10) !important;
        }

        .bsbt-logout-link:active {
            transform: translateY(2px) !important;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
        }

        .bsbt-logout-link svg {
            position: relative;
            z-index: 3;
            stroke-width: 3px;
        }
    </style>
    
    <div class="bsbt-logout-wrapper">
        <a href="<?php echo esc_url(add_query_arg('action', 'owner_logout', home_url('/'))); ?>" class="bsbt-logout-link">
            <span>Abmelden</span>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
        </a>
    </div>
    <?php
    return ob_get_clean();
});
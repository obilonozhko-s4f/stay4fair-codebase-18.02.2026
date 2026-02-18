<?php
/**
 * Plugin Name: BSBT – Owner Dashboard (Total 3D Aligned)
 * Version: 18.0.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class BSBT_Aligned_3D_Dashboard {

    public function __construct() {
        add_shortcode( 'bsbt_owner_dashboard', [ $this, 'render' ] );
    }

    public function render() {

        if ( ! is_user_logged_in() ) {
            $current_url = home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ) );
            wp_safe_redirect( site_url('/owner-login/?redirect_to=' . urlencode( $current_url ) ) );
            exit;
        }

        $user = wp_get_current_user();
        $navy = '#082567'; 
        $gold = '#E0B849'; 
        
        ob_start(); ?>

        <style>
            .bsbt-3d-btn {
                position: relative !important;
                overflow: hidden !important;
                border-radius: 10px !important;
                border: none !important;
                box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important;
                transition: all 0.25s ease !important;
                cursor: pointer !important;
                display: inline-block;
                padding: 12px 24px;
                background-color: <?php echo $gold; ?> !important;
                color: <?php echo $navy; ?> !important;
                background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important;
                background-blend-mode: overlay;
                font-weight: 700;
                text-decoration: none;
                text-align: center;
            }

            .bsbt-3d-btn::before {
                content: "" !important;
                position: absolute !important;
                top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important;
                background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important;
                transform: scaleY(0.48) !important;
                filter: blur(5px) !important;
                opacity: 0.55 !important;
                z-index: 1 !important;
            }

            .bsbt-3d-btn:hover {
                background-color: <?php echo $navy; ?> !important;
                color: <?php echo $gold; ?> !important;
                transform: translateY(-2px) !important;
            }

            .bsbt-viewport { padding-top: 18vh; padding-bottom: 40px; background: #ffffff; width: 100%; box-sizing: border-box; overflow-x: hidden; }
            #bsbt-container { font-family: 'Segoe UI', Roboto, sans-serif; max-width: 1150px; margin: 0 auto; padding: 0 25px; box-sizing: border-box; }

            .bsbt-header-row {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 15px !important;
            }

            .bsbt-title { font-size: 32px; font-weight: 800; margin: 0; color: <?php echo $navy; ?>; }

            .bsbt-lang-box {
                border: 1px solid #f1f5f9;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 11px;
                color: #94a3b8;
                font-weight: 600;
                background: #fff;
            }

            .bsbt-header-right {
                display: flex;
                flex-direction: column;
                align-items: flex-end;
                gap: 8px;
            }

            .bsbt-partner-tag {
                background: <?php echo $navy; ?>;
                color: #fff;
                padding: 7px 15px;
                border-radius: 8px;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 1px;
            }

            .bsbt-grid {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 20px !important;
                margin: 30px 0 !important;
            }

            .bsbt-glass-card {
                background: #ffffff !important;
                border: 1px solid #f1f5f9 !important;
                border-radius: 24px !important;
                padding: 30px 15px !important;
                text-align: center !important;
                text-decoration: none !important;
                transition: 0.4s !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                box-shadow: 0 4px 20px rgba(0,0,0,0.03) !important;
            }

            .bsbt-glass-card:hover {
                transform: translateY(-8px) !important;
                border-color: <?php echo $gold; ?> !important;
                box-shadow: 0 20px 40px rgba(8, 37, 103, 0.1) !important;
            }

            .bsbt-bubble-icon {
                width: 70px;
                height: 70px;
                background: radial-gradient(circle at 30% 30%, #ffffff 0%, #f1f5f9 100%);
                border-radius: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 32px;
                margin-bottom: 15px;
            }

            .bsbt-footer {
                background: #f8fafc !important;
                border-radius: 24px !important;
                padding: 25px 35px !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                border: 1px solid #e2e8f0 !important;
                margin-top: 30px;
            }

            /* =========================
               MOBILE FIX (2 CARDS PER ROW)
               ========================= */
            @media (max-width: 767px) {

                .bsbt-header-row {
                    flex-direction: column !important;
                    align-items: flex-start !important;
                    gap: 15px !important;
                }

                .bsbt-header-right {
                    align-items: flex-start !important;
                    width: 100%;
                }

                .bsbt-grid {
                    grid-template-columns: repeat(2, 1fr) !important;
                    gap: 15px !important;
                }

                .bsbt-glass-card {
                    padding: 25px 10px !important;
                }

                .bsbt-bubble-icon {
                    width: 60px;
                    height: 60px;
                    font-size: 26px;
                }
            }

        </style>

        <div class="bsbt-viewport">
            <div id="bsbt-container">
                
                <div class="bsbt-header-row">
                    <h2 class="bsbt-title">Owner Dashboard</h2>
                    <div class="bsbt-lang-box">🌐 DE / EN / RU</div>
                    <div class="bsbt-header-right">
                        <div class="bsbt-partner-tag">Stay4Fair Partner</div>
                        <?php echo do_shortcode('[bsbt_logout_button]'); ?>
                    </div>
                </div>

                <p style="font-size: 15px; color: #64748b; margin: 0 0 30px 0;">
                    Willkommen zurück, 
                    <span style="font-weight: 700; color: <?php echo $navy; ?>;">
                        <?php echo esc_html($user->display_name); ?>
                    </span>
                </p>

                <div class="bsbt-grid">
                    <?php
                    $items = [
                        ['Meine Buchungen', '📅', '/owner-bookings/'],
                        ['Apartments', '🏢', '#'],
                        ['Finanzen', '💳', '/owner-dashboard-finanzen/'],
                        ['Kalender', '🗓️', '#'],
                        ['Mein Profil', '👤', '#'],
                        ['Support', '🎧', '#']
                    ];
                    foreach ($items as $item) : ?>
                        <a href="<?php echo $item[2]; ?>" class="bsbt-glass-card">
                            <div class="bsbt-bubble-icon"><?php echo $item[1]; ?></div>
                            <h4 style="margin:0 0 5px 0; font-size: 18px; color: <?php echo $navy; ?>;"><?php echo $item[0]; ?></h4>
                            <span style="font-size: 10px; color: #cbd5e1; font-weight: 700; text-transform: uppercase;">Öffnen</span>
                        </a>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <?php
        return ob_get_clean();
    }
}

new BSBT_Aligned_3D_Dashboard();

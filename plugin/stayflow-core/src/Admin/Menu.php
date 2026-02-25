<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use StayFlow\Registry\ModuleRegistry;

final class Menu
{
    public function register(): void
    {
        add_menu_page(
            'StayFlow',
            'StayFlow',
            'manage_options',
            'stayflow-core',
            [$this, 'renderDashboard'],
            'dashicons-admin-generic',
            58
        );

        add_submenu_page(
            'stayflow-core',
            'Settings',
            'Settings',
            'manage_options',
            'stayflow-core-settings',
            [$this, 'renderSettings']
        );

        add_submenu_page(
            'stayflow-core',
            'Content Registry',
            'Content Registry',
            'manage_options',
            'stayflow-core-content-registry',
            [$this, 'renderContentRegistry']
        );

        add_submenu_page(
            'stayflow-core',
            'Policies',
            'Policies',
            'manage_options',
            'stayflow-core-policies',
            [$this, 'renderPolicies']
        );

        add_submenu_page(
            'stayflow-core',
            'Owners',
            'Owners',
            'manage_options',
            'edit.php?post_type=stayflow_owner'
        );
    }

    public function renderDashboard(): void
    {
        $modules = ModuleRegistry::all();

        ?>
        <div class="wrap stayflow-dashboard">

            <div class="sf-hero">
                <div>
                    <h1>StayFlow Control Center</h1>
                    <p>SaaS-ready enterprise architecture core</p>
                </div>
                <span class="sf-version">v<?php echo esc_html(STAYFLOW_CORE_VERSION); ?></span>
            </div>

            <div class="sf-kpi-grid">
                <?php $this->kpi('Modules', count($modules)); ?>
                <?php $this->kpi('Active', $this->countByStatus($modules, 'active')); ?>
                <?php $this->kpi('Pending', $this->countByStatus($modules, 'pending')); ?>
                <?php $this->kpi('Coming Soon', $this->countByStatus($modules, 'coming')); ?>
            </div>

            <div class="sf-grid">
                <?php foreach ($modules as $module) {
                    $this->card($module);
                } ?>
            </div>

        </div>

        <style>
            .stayflow-dashboard {
                max-width: 1200px;
            }

            /* Hide empty WP notices inside dashboard */
            .stayflow-dashboard .notice {
                display: none;
            }

            .sf-hero {
                background: #212F54;
                color: white;
                padding: 30px;
                border-radius: 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
            }

            .sf-hero h1 {
                margin: 0 0 6px;
                font-size: 26px;
                color: #ffffff !important;
            }

            .sf-hero p {
                margin: 0;
                opacity: 0.85;
            }

            .sf-version {
                background: #E0B849;
                color: #111;
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }

            .sf-kpi-grid,
            .sf-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            .sf-kpi {
                background: white;
                padding: 20px;
                border-radius: 16px;
                box-shadow: 0 6px 18px rgba(0,0,0,0.06);
            }

            .sf-kpi-value {
                font-size: 22px;
                font-weight: 600;
            }

            .sf-kpi-label {
                font-size: 13px;
                color: #6b7280;
            }

            .sf-card {
                display: block;
                background: #ffffff;
                border-radius: 16px;
                padding: 24px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.06);
                transition: all 0.2s ease;
                text-decoration: none;
                color: inherit;
            }

            .sf-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 14px 36px rgba(0,0,0,0.12);
            }

            .sf-card.sf-disabled {
                opacity: 0.6;
                cursor: not-allowed;
                pointer-events: none;
            }

            .sf-icon {
                font-size: 26px;
                margin-bottom: 12px;
            }

            .sf-card h3 {
                margin: 0 0 6px;
                font-size: 16px;
                color: #111827;
            }

            .sf-card p {
                margin: 0;
                font-size: 13px;
                color: #6b7280;
            }

            .sf-badge {
                display: inline-block;
                margin-top: 10px;
                padding: 4px 10px;
                font-size: 11px;
                border-radius: 20px;
                font-weight: 600;
            }

            .badge-active {
                background: #e6f4ea;
                color: #1e7e34;
            }

            .badge-pending {
                background: #fff3cd;
                color: #856404;
            }

            .badge-coming {
                background: #e2e3e5;
                color: #6c757d;
            }

            @media (max-width: 782px) {
                .sf-hero {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 12px;
                }
            }
        </style>
        <?php
    }

    private function kpi(string $label, int $value): void
    {
        echo '<div class="sf-kpi">';
        echo '<div class="sf-kpi-value">' . esc_html((string)$value) . '</div>';
        echo '<div class="sf-kpi-label">' . esc_html($label) . '</div>';
        echo '</div>';
    }

    private function card(array $module): void
    {
        $isClickable = $module['link'] !== '#';

        $tagStart = $isClickable
            ? '<a href="' . esc_url(admin_url($module['link'])) . '" class="sf-card">'
            : '<div class="sf-card sf-disabled">';

        $tagEnd = $isClickable ? '</a>' : '</div>';

        echo $tagStart;
        echo '<div class="sf-icon">' . esc_html($module['icon']) . '</div>';
        echo '<h3>' . esc_html($module['title']) . '</h3>';
        echo '<p>' . esc_html($module['desc']) . '</p>';
        echo '<span class="sf-badge badge-' . esc_attr($module['status']) . '">' . esc_html(ucfirst($module['status'])) . '</span>';
        echo $tagEnd;
    }

    private function countByStatus(array $modules, string $status): int
    {
        return count(array_filter($modules, fn($m) => $m['status'] === $status));
    }

    public function renderSettings(): void
    {
        echo '<div class="wrap"><h1>Settings</h1></div>';
    }

    public function renderContentRegistry(): void
    {
        echo '<div class="wrap"><h1>Content Registry</h1></div>';
    }

    public function renderPolicies(): void
    {
        echo '<div class="wrap"><h1>Policies</h1></div>';
    }
}
<?php

declare(strict_types=1);

namespace StayFlow\Registry;

final class ModuleRegistry
{
    public static function all(): array
    {
        return [
            self::make(
                'settings',
                '⚙',
                'Settings',
                'Platform configuration & VAT setup',
                'active',
                'admin.php?page=stayflow-core-settings'
            ),
            self::make(
                'content',
                '🧱',
                'Content Registry',
                'Centralized content management',
                'active',
                'admin.php?page=stayflow-core-content-registry'
            ),
            self::make(
                'policies',
                '📜',
                'Policies',
                'Cancellation & business rules',
                'pending',
                'admin.php?page=stayflow-core-policies'
            ),
            self::make(
                'owners',
                '👤',
                'Owners',
                'Owner entities & compliance',
                'active',
                'edit.php?post_type=stayflow_owner'
            ),
            self::make(
                'models',
                '💼',
                'Business Models',
                'A/B/C architecture',
                'coming',
                '#'
            ),
            self::make(
                'compliance',
                '🛡',
                'Compliance',
                'Legal & VAT validation layer',
                'coming',
                '#'
            ),
        ];
    }

    private static function make(
        string $key,
        string $icon,
        string $title,
        string $desc,
        string $status,
        string $link
    ): array {
        return [
            'key'    => $key,
            'icon'   => $icon,
            'title'  => $title,
            'desc'   => $desc,
            'status' => $status,
            'link'   => $link,
        ];
    }
}
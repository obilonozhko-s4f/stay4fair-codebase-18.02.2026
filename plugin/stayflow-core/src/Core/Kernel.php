<?php

declare(strict_types=1);

namespace StayFlow\Core;

use StayFlow\Admin\Menu;
use StayFlow\BusinessModel\BusinessModelServiceProvider;
use StayFlow\CPT\OwnerPostType;
use StayFlow\FeatureFlags\FeatureFlagStore;
use StayFlow\Settings\SettingsStore;

final class Kernel
{
    public function boot(): void
    {
        $ownerPostType = new OwnerPostType();
        $settingsStore = new SettingsStore();
        $featureFlagStore = new FeatureFlagStore();
        $menu = new Menu();

        add_action('init', [$ownerPostType, 'register']);
        add_action('admin_init', [$settingsStore, 'register']);
        add_action('admin_init', [$featureFlagStore, 'register']);
        add_action('admin_menu', [$menu, 'register']);

        (new BusinessModelServiceProvider())->boot();
    }
}

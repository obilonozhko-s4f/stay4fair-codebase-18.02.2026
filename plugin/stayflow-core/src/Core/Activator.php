<?php

declare(strict_types=1);

namespace StayFlow\Core;

use StayFlow\Core\FeatureFlags\FeatureFlagStore;
use StayFlow\Core\Settings\SettingsStore;

/**
 * RU: Активация выполняет только безопасную первичную инициализацию.
 * EN: Activation performs only safe first-time initialization.
 */
final class Activator
{
    /**
     * RU:
     * Создает опции только если они еще не существуют.
     * Никаких update, никаких перезаписей.
     *
     * EN:
     * Creates options only if they do not exist.
     * No updates, no overwrites.
     */
    public static function activate(): void
    {
        SettingsStore::ensureDefaultsIfMissing();
        FeatureFlagStore::ensureDefaultsIfMissing();
    }
}
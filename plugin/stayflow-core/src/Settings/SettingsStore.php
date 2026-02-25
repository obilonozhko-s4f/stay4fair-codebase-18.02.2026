<?php

declare(strict_types=1);

namespace StayFlow\Settings;

/**
 * RU: Типизированное хранилище настроек без runtime-интеграций.
 * EN: Typed settings storage with zero runtime integration.
 */
final class SettingsStore
{
    public const OPTION_KEY = 'stayflow_core_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'platform_country'   => '',
            'base_currency'      => 'EUR',
            'platform_vat_rate'  => 0.0,
            'commission_default' => 0.0,
            'commission_min'     => 5.0,
            'commission_max'     => 100.0,
            'reverse_charge_mode'=> 'pending',
            'enabled_models'     => ['A','B','C'],
        ];
    }

    /**
     * RU: Регистрирует option через Settings API.
     * EN: Registers option via Settings API.
     */
    public function register(): void
    {
        register_setting(
            'stayflow_core_settings_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'default' => self::defaults(),
                'show_in_rest' => false,
            ]
        );
    }

    /**
     * RU: Создает option только если отсутствует.
     * EN: Creates option only if missing.
     */
    public static function ensureDefaultsIfMissing(): void
    {
        if (get_option(self::OPTION_KEY, null) === null) {
            add_option(self::OPTION_KEY, self::defaults(), '', false);
        }
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $settings = get_option(self::OPTION_KEY, self::defaults());

        return is_array($settings)
            ? ($settings[$key] ?? $fallback)
            : $fallback;
    }

    /**
     * @param mixed $input
     * @return array<string,mixed>
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];

        $platformCountry = isset($input['platform_country'])
            ? sanitize_text_field((string)$input['platform_country'])
            : '';

        $baseCurrency = isset($input['base_currency'])
            ? strtoupper(sanitize_text_field((string)$input['base_currency']))
            : 'EUR';

        $vat = isset($input['platform_vat_rate'])
            ? (float)$input['platform_vat_rate']
            : 0.0;

        $commissionDefault = isset($input['commission_default'])
            ? (float)$input['commission_default']
            : 0.0;

        $commissionMin = isset($input['commission_min'])
            ? (float)$input['commission_min']
            : 5.0;

        $commissionMax = isset($input['commission_max'])
            ? (float)$input['commission_max']
            : 100.0;

        $reverseChargeMode = isset($input['reverse_charge_mode'])
            ? sanitize_text_field((string)$input['reverse_charge_mode'])
            : 'pending';

        $enabledModels = ['A','B','C'];
        if (isset($input['enabled_models']) && is_array($input['enabled_models'])) {
            $allowed = ['A','B','C'];
            $enabledModels = array_values(array_intersect(
                $allowed,
                array_map(static fn($v): string => strtoupper((string)$v), $input['enabled_models'])
            ));
            if ($enabledModels === []) {
                $enabledModels = $allowed;
            }
        }

        return [
            'platform_country'   => $platformCountry,
            'base_currency'      => $baseCurrency ?: 'EUR',
            'platform_vat_rate'  => $vat,
            'commission_default' => $commissionDefault,
            'commission_min'     => $commissionMin,
            'commission_max'     => $commissionMax,
            'reverse_charge_mode'=> $reverseChargeMode ?: 'pending',
            'enabled_models'     => $enabledModels,
        ];
    }
}
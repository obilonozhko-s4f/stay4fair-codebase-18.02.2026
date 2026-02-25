<?php

declare(strict_types=1);

namespace StayFlow\FeatureFlags;

/**
 * RU: Хранилище feature-флагов (все выключены по умолчанию).
 * EN: Feature flags storage (all disabled by default).
 */
final class FeatureFlagStore
{
    public const OPTION_KEY = 'stayflow_core_feature_flags';

    public const FLAG_PHASE_0_CONTENT_REGISTRY = 'phase_0_content_registry';
    public const FLAG_PHASE_1_RATES_VAT_SOURCE = 'phase_1_rates_vat_source';
    public const FLAG_PHASE_2_MODELS_RC_PIPELINE = 'phase_2_models_rc_pipeline';
    public const FLAG_PHASE_3_POLICY_ENGINE = 'phase_3_policy_engine';
    public const FLAG_PHASE_4_LEGACY_CLEANUP_ENABLE = 'phase_4_legacy_cleanup_enable';

    /**
     * @return array<string,bool>
     */
    public static function defaults(): array
    {
        return [
            self::FLAG_PHASE_0_CONTENT_REGISTRY => false,
            self::FLAG_PHASE_1_RATES_VAT_SOURCE => false,
            self::FLAG_PHASE_2_MODELS_RC_PIPELINE => false,
            self::FLAG_PHASE_3_POLICY_ENGINE => false,
            self::FLAG_PHASE_4_LEGACY_CLEANUP_ENABLE => false,
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
     * RU: Создает option только если он отсутствует.
     * EN: Creates option only if missing.
     */
    public static function ensureDefaultsIfMissing(): void
    {
        if (get_option(self::OPTION_KEY, null) === null) {
            add_option(self::OPTION_KEY, self::defaults(), '', false);
        }
    }

    public function isEnabled(string $flag): bool
    {
        $flags = get_option(self::OPTION_KEY, self::defaults());

        return is_array($flags)
            && isset($flags[$flag])
            && (bool) $flags[$flag];
    }

    /**
     * @param mixed $input
     * @return array<string,bool>
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $out = [];

        foreach ($defaults as $key => $_) {
            $out[$key] = isset($input[$key]) ? (bool)$input[$key] : false;
        }

        return $out;
    }
}
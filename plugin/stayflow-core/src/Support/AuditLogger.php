<?php

declare(strict_types=1);

namespace StayFlow\Support;

use StayFlow\Core\FeatureFlags\FeatureFlagStore;

/**
 * RU:
 * Audit logger scaffold.
 * По умолчанию ничего не записывает.
 *
 * EN:
 * Audit logger scaffold.
 * Does nothing unless explicitly enabled.
 */
final class AuditLogger
{
    /**
     * RU: Имя option для хранения логов.
     * EN: Option key for audit log storage.
     */
    private const OPTION_KEY = 'stayflow_audit_log';

    /**
     * RU:
     * Запись аудита.
     * Работает только если включен флаг phase_4_legacy_cleanup_enable.
     *
     * EN:
     * Writes audit entry only if explicitly enabled via feature flag.
     *
     * @param array<string,mixed> $context
     */
    public function log(string $event, array $context = []): void
    {
        $flags = new FeatureFlagStore();

        if (!$flags->isEnabled(FeatureFlagStore::FLAG_PHASE_4_LEGACY_CLEANUP_ENABLE)) {
            return;
        }

        $entries = get_option(self::OPTION_KEY, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'event'   => $event,
            'context' => $context,
            'time'    => current_time('mysql'),
        ];

        if (count($entries) > 500) {
            $entries = array_slice($entries, -500);
        }

        update_option(self::OPTION_KEY, $entries, false);
    }
}
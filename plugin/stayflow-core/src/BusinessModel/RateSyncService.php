<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

/**
 * RU:
 * Model B больше НЕ использует синхронизацию owner_price → mphb_price.
 * Источник истины = season_prices (Rates).
 *
 * Класс оставлен как безопасный no-op,
 * чтобы не ломать DI и архитектуру ядра.
 *
 * EN:
 * Model B no longer syncs owner_price into mphb_price.
 * Rates (season_prices) remain the single source of truth.
 */
final class RateSyncService
{
    /**
     * @deprecated Model B no longer performs rate sync.
     * Method intentionally left empty for backward compatibility.
     */
    public function syncToMphbDatabase(int $roomTypeId, float $price): void
    {
        // Intentionally no-op.
        return;
    }
}

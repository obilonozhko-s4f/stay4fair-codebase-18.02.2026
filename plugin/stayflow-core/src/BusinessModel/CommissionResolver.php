<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class CommissionResolver
{
    public function resolveFinalGrossPrice(float $ownerPrice, string $commissionMode): float
    {
        if ($ownerPrice <= 0) {
            return 0.0;
        }

        if ($commissionMode !== 'over') {
            return round($ownerPrice, 2);
        }

        /**
         * RU: Для Model B сохраняем легаси-формулу:
         * owner + owner * fee * (1 + vat)
         */
        return round($ownerPrice + ($ownerPrice * BSBT_FEE * (1 + BSBT_VAT_ON_FEE)), 2);
    }
}

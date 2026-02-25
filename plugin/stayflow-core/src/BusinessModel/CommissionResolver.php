<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

/**
 * RU:
 * Финальная чистая реализация Model B (commission-inside).
 *
 * Источник цены = Rates (season_prices).
 * Owner_price не используется как источник истины.
 *
 * EN:
 * Final clean Model B implementation (commission-inside).
 * Source of truth = Rates (season_prices).
 */
final class CommissionResolver
{
    /**
     * owner_price  = цена гостя (из Rates)
     * commission   = owner_price * BSBT_FEE
     * vat          = commission * BSBT_VAT_ON_FEE
     * owner_payout = owner_price - commission
     */
    public function resolveModelB(float $guestPrice): array
    {
        $guestPrice = round(max(0.0, $guestPrice), 2);

        if ($guestPrice <= 0.0) {
            return [
                'guest_price'  => 0.0,
                'commission'   => 0.0,
                'vat'          => 0.0,
                'owner_payout' => 0.0,
            ];
        }

        $commission  = round($guestPrice * BSBT_FEE, 2);
        $vat         = round($commission * BSBT_VAT_ON_FEE, 2);
        $ownerPayout = round($guestPrice - $commission, 2);

        return [
            'guest_price'  => $guestPrice,
            'commission'   => $commission,
            'vat'          => $vat,
            'owner_payout' => $ownerPayout,
        ];
    }
}

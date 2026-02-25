<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class CommissionResolver
{
    /**
     * RU:
     * Унифицированный расчёт комиссии для Model B.
     * База всегда guest_total (MPHB booking total).
     *
     * EN:
     * Unified commission calculation for Model B.
     * Base must always be guest_total (MPHB booking total).
     */
    public function resolveFromGuestTotal(float $guestTotal): array
    {
        $guestTotal = round(max(0.0, $guestTotal), 2);

        if ($guestTotal <= 0.0) {
            return [
                'guest_total'     => 0.0,
                'commission_net'  => 0.0,
                'commission_vat'  => 0.0,
                'commission_gross'=> 0.0,
                'owner_payout'    => 0.0,
            ];
        }

        $feeRate = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.0;
        $vatRate = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.0;

        $commissionNet   = round($guestTotal * max(0.0, $feeRate), 2);
        $commissionVat   = round($commissionNet * max(0.0, $vatRate), 2);
        $commissionGross = round($commissionNet + $commissionVat, 2);
        $ownerPayout     = round($guestTotal - $commissionNet, 2);

        return [
            'guest_total'      => $guestTotal,
            'commission_net'   => $commissionNet,
            'commission_vat'   => $commissionVat,
            'commission_gross' => $commissionGross,
            'owner_payout'     => $ownerPayout,
        ];
    }
}

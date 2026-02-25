<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class RateSyncService
{
    public function syncToMphbDatabase(int $roomTypeId, float $price): void
    {
        if (!function_exists('MPHB')) {
            return;
        }

        if ($roomTypeId <= 0 || $price <= 0) {
            return;
        }

        $repo = MPHB()->getRateRepository();
        $rates = $repo->findAllByRoomType($roomTypeId);

        foreach ($rates as $rate) {
            $rateId = (int) $rate->getId();
            if ($rateId > 0) {
                update_post_meta($rateId, 'mphb_price', $price);
            }
        }
    }
}

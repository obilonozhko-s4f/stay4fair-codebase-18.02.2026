<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class BusinessModelEngine
{
    private static ?self $instance = null;

    public function __construct(
        private readonly CommissionResolver $commissionResolver = new CommissionResolver(),
        private readonly VatResolver $vatResolver = new VatResolver(),
        private readonly RateSyncService $rateSyncService = new RateSyncService(),
        private readonly WooTaxAdapter $wooTaxAdapter = new WooTaxAdapter(),
    ) {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function resolveCommissionMode(string $businessModel): string
    {
        return match ($businessModel) {
            'model_b' => 'over',
            'model_c' => 'included',
            default => 'margin',
        };
    }

    public function resolveFinalGrossPrice(float $ownerPrice, string $businessModel): float
    {
        return $this->commissionResolver->resolveFinalGrossPrice(
            $ownerPrice,
            $this->resolveCommissionMode($businessModel)
        );
    }

    public function resolveVatProfile(string $businessModel): string
    {
        return $this->vatResolver->resolveVatProfile($businessModel);
    }

    public function rates(): RateSyncService
    {
        return $this->rateSyncService;
    }

    public function wooTax(): WooTaxAdapter
    {
        return $this->wooTaxAdapter;
    }
}

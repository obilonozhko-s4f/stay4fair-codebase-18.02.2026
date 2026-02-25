<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class VatResolver
{
    public function resolveVatProfile(string $businessModel): string
    {
        return match ($businessModel) {
            'model_b' => 'vat_on_fee',
            'model_c' => 'included',
            default => 'margin',
        };
    }
}

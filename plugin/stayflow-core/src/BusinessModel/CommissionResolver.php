<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class VatResolver
{
    /**
     * RU: В Model B НДС применяется только к комиссии платформы.
     * В Model A профиль VAT в рамках этой логики не решаем (это уровень инвойсов/учёта).
     */
    public function isVatOnFee(string $businessModel): bool
    {
        return trim(strtolower($businessModel)) === 'model_b';
    }
}

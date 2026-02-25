<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class VatResolver
{
    public function vatOnFee(string $model): bool
    {
        return $model === 'model_b';
    }
}

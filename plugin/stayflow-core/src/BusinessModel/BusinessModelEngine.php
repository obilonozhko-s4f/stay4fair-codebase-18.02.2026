<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class BusinessModelEngine
{
    private static ?self $instance = null;

    public function __construct(
        private readonly VatResolver $vatResolver = new VatResolver(),
    ) {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function isModelA(string $businessModel): bool
    {
        $m = trim(strtolower($businessModel));
        return $m === '' || $m === 'model_a';
    }

    public function isModelB(string $businessModel): bool
    {
        return trim(strtolower($businessModel)) === 'model_b';
    }

    public function vat(): VatResolver
    {
        return $this->vatResolver;
    }
}

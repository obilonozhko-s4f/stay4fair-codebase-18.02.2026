<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class BusinessModelEngine
{
    private static ?self $instance = null;

    private VatResolver $vatResolver;
    private WooTaxAdapter $wooTaxAdapter;

    public function __construct()
    {
        $this->vatResolver   = new VatResolver();
        $this->wooTaxAdapter = new WooTaxAdapter();
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

    /**
     * RU: Доступ к VAT-логике.
     */
    public function vat(): VatResolver
    {
        return $this->vatResolver;
    }

    /**
     * RU: Возвращаем WooTaxAdapter для совместимости ServiceProvider.
     * EN: Kept for backward compatibility.
     */
    public function wooTax(): WooTaxAdapter
    {
        return $this->wooTaxAdapter;
    }
}

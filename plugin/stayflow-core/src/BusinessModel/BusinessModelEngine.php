<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class BusinessModelEngine
{
    private static ?self $instance = null;

    private VatResolver $vatResolver;
    private WooTaxAdapter $wooTaxAdapter;

    /** @var object|null */
    private $ratesNoop = null;

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
     * RU: VAT-логика (в Model B VAT только на комиссию).
     */
    public function vat(): VatResolver
    {
        return $this->vatResolver;
    }

    /**
     * RU: Оставлено для совместимости с BusinessModelServiceProvider.
     */
    public function wooTax(): WooTaxAdapter
    {
        return $this->wooTaxAdapter;
    }

    /**
     * RU: BACKWARD-COMPAT API.
     *
     * Исторически ServiceProvider мог передавать сюда:
     * - roomTypeId (int)
     * - owner_price_per_night (float)
     *
     * НОВОЕ ПРАВИЛО:
     * - Мы НЕ синхроним цены в MPHB (ни mphb_price, ни season_prices) ни для Model A, ни для Model B.
     *
     * Поэтому метод должен быть безопасным и никогда не падать.
     * Возвращаем "как есть" число, если оно похоже на цену, иначе 0.0.
     *
     * @param mixed $value
     */
    public function resolveMphbPrice($value): float
    {
        if (is_numeric($value)) {
            $v = (float)$value;
            return round(max(0.0, $v), 2);
        }

        return 0.0;
    }

    /**
     * RU: BACKWARD-COMPAT API.
     *
     * ServiceProvider ожидает engine->rates()->... (раньше это был rate sync / запись в MPHB).
     * По новой зафиксированной архитектуре:
     * - Model B: НИКАКОГО sync
     * - Model A: тоже не синхроним owner_price с rates (guest price = MPHB rates)
     *
     * Поэтому возвращаем безопасный NO-OP объект.
     * Любые вызовы методов будут "проглочены" без фаталов.
     */
    public function rates()
    {
        if ($this->ratesNoop !== null) {
            return $this->ratesNoop;
        }

        $this->ratesNoop = new class {
            public function __call($name, $arguments)
            {
                // RU: ничего не делаем, чтобы не было sync и не было падений.
                return null;
            }
        };

        return $this->ratesNoop;
    }
}

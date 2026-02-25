<?php

declare(strict_types=1);

namespace StayFlow\Money;

/**
 * RU: Форматтер денег (только форматирование, без влияния на Woo totals).
 * EN: Money formatter (formatting only, no Woo totals impact).
 */
final class Formatter
{
    /**
     * RU: Форматирует сумму в указанной валюте.
     * EN: Formats amount for the provided currency.
     */
    public function format(float $amount, string $currency = 'EUR'): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = 'EUR';
        }

        return number_format($amount, 2, '.', ' ') . ' ' . $currency;
    }
}

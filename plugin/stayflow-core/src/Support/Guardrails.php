<?php

declare(strict_types=1);

namespace StayFlow\Support;

/**
 * RU: Каркас guardrails для валидации границ настроек.
 * EN: Guardrails scaffold for validating settings boundaries.
 */
final class Guardrails
{
    /**
     * RU: Проверка диапазона комиссии (5..100).
     * EN: Validates commission range (5..100).
     */
    public function validateCommissionRange(float $min, float $max): bool
    {
        if ($min < 5.0) {
            return false;
        }

        if ($max > 100.0) {
            return false;
        }

        return $min <= $max;
    }
}

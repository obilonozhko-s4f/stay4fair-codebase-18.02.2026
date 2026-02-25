<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class BusinessModelEngine
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isModelB(string $model): bool
    {
        return $model === 'model_b';
    }

    public function isModelA(string $model): bool
    {
        return $model !== 'model_b';
    }
}

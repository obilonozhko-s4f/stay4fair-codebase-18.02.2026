<?php

declare(strict_types=1);

namespace StayFlow\Core\Registry;

/**
 * RU: Реестр бизнес-моделей (A/B/C) — пока только хранение.
 * EN: Business model registry (A/B/C) — storage scaffold only.
 */
final class BusinessModelRegistry extends AbstractRegistry
{
    protected function optionKey(): string
    {
        return 'stayflow_registry_business_models';
    }
}

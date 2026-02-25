<?php

declare(strict_types=1);

namespace StayFlow\Core\Registry;

/**
 * RU: Реестр compliance-полей владельца — scaffold.
 * EN: Owner compliance fields registry — scaffold.
 */
final class ComplianceRegistry extends AbstractRegistry
{
    protected function optionKey(): string
    {
        return 'stayflow_registry_compliance';
    }
}

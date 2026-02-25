<?php

declare(strict_types=1);

namespace StayFlow\Core\Registry;

/**
 * RU: Реестр политик отмены — пока только хранение.
 * EN: Cancellation policy registry — storage scaffold only.
 */
final class PolicyRegistry extends AbstractRegistry
{
    protected function optionKey(): string
    {
        return 'stayflow_registry_policies';
    }
}

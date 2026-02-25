<?php

declare(strict_types=1);

namespace StayFlow\Core\Registry;

/**
 * RU: Реестр контента (тексты/лейблы) — без runtime-интеграции.
 * EN: Content registry (texts/labels) — without runtime integration.
 */
final class ContentRegistry extends AbstractRegistry
{
    protected function optionKey(): string
    {
        return 'stayflow_registry_content';
    }
}

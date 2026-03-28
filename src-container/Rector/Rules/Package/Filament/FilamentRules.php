<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Filament;

use AppContainer\Rector\Rules\Package\AbstractPackageRuleSet;

/**
 * PHP rule set descriptor for filament/filament.
 */
final class FilamentRules extends AbstractPackageRuleSet
{
    public function getPackageName(): string
    {
        return 'filament/filament';
    }

    /**
     * @return list<string>
     */
    public function getRuleClasses(string $hop): array
    {
        return match ($hop) {
            '9-to-10' => [
                FilamentFormTableNamespaceRector::class,
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function supportedHops(): array
    {
        return ['9-to-10'];
    }
}

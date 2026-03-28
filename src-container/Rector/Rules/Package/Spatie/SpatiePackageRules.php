<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Spatie;

use AppContainer\Rector\Rules\Package\AbstractPackageRuleSet;

/**
 * PHP rule set descriptor for Spatie packages.
 *
 * Covers: spatie/laravel-medialibrary and spatie/laravel-permission.
 * Each package has its own rule classes; this set covers medialibrary's V9→V10 migration.
 */
final class SpatiePackageRules extends AbstractPackageRuleSet
{
    public function getPackageName(): string
    {
        return 'spatie/laravel-medialibrary';
    }

    /**
     * @return list<string>
     */
    public function getRuleClasses(string $hop): array
    {
        return match ($hop) {
            '9-to-10' => [
                HasMediaTraitRector::class,
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

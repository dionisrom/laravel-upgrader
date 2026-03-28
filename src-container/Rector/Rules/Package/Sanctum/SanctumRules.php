<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Sanctum;

use AppContainer\Rector\Rules\Package\AbstractPackageRuleSet;

/**
 * PHP rule set descriptor for laravel/sanctum.
 *
 * Sanctum V2→V3 (released with Laravel 10): The public PHP API is unchanged.
 * Breaking changes are configuration-level (middleware, token model) rather
 * than AST-fixable code transforms. No auto-fix rules are registered here —
 * the version matrix emits warnings for manual review.
 */
final class SanctumRules extends AbstractPackageRuleSet
{
    public function getPackageName(): string
    {
        return 'laravel/sanctum';
    }

    /**
     * @return list<string>
     */
    public function getRuleClasses(string $hop): array
    {
        // Sanctum has no auto-fixable AST transformations across currently supported hops.
        // Config-level changes are handled by the ConfigMigrator (P1-13).
        return [];
    }

    /**
     * @return list<string>
     */
    public function supportedHops(): array
    {
        return ['9-to-10', '10-to-11'];
    }
}

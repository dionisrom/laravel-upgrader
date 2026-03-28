<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Nova;

use AppContainer\Rector\Rules\Package\AbstractPackageRuleSet;

/**
 * PHP rule set descriptor for laravel/nova.
 *
 * Nova V4 (Laravel 9+): Field API changes are mostly additive.
 * Version-specific breaking changes in Nova are predominantly configuration
 * and Blade-level, not PHP AST-transformable.
 */
final class NovaRules extends AbstractPackageRuleSet
{
    public function getPackageName(): string
    {
        return 'laravel/nova';
    }

    /**
     * @return list<string>
     */
    public function getRuleClasses(string $hop): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function supportedHops(): array
    {
        return ['9-to-10', '10-to-11', '11-to-12'];
    }
}

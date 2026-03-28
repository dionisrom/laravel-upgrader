<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Horizon;

use AppContainer\Rector\Rules\Package\AbstractPackageRuleSet;

/**
 * PHP rule set descriptor for laravel/horizon.
 *
 * Horizon V5 changes are primarily configuration-level (balancing strategies,
 * queue worker config keys). No PHP AST auto-fixable transformations are needed.
 * Config changes are handled by the ConfigMigrator subsystem.
 */
final class HorizonRules extends AbstractPackageRuleSet
{
    public function getPackageName(): string
    {
        return 'laravel/horizon';
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
        return ['9-to-10', '10-to-11'];
    }
}

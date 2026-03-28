<?php

declare(strict_types=1);

namespace AppContainer\Rector\Rules\Package\Livewire;

use AppContainer\Rector\Rules\Package\AbstractPackageRuleSet;

/**
 * PHP rule set descriptor for livewire/livewire.
 *
 * Lists all Rector rules available for Livewire migration across upgrade hops.
 * The JSON matrix at config/package-rules/livewire-livewire.json references these FQCNs.
 */
final class LivewireRuleSet extends AbstractPackageRuleSet
{
    public function getPackageName(): string
    {
        return 'livewire/livewire';
    }

    /**
     * @return list<string>
     */
    public function getRuleClasses(string $hop): array
    {
        return match ($hop) {
            '9-to-10' => [
                EmitToDispatchRector::class,
                ComputedPropertyRector::class,
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

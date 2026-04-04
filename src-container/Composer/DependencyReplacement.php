<?php

declare(strict_types=1);

namespace AppContainer\Composer;

/**
 * Represents a package replacement that may require code updates.
 */
final class DependencyReplacement
{
    /**
     * @param string $oldPackage The package being replaced
     * @param string|null $newPackage The replacement package (if known)
     * @param string|null $oldConstraint The old version constraint
     * @param string|null $newConstraint The new version constraint
     * @param bool $rectorRulesAvailable Whether Rector rules exist for this replacement
     */
    public function __construct(
        public readonly string $oldPackage,
        public readonly ?string $newPackage,
        public readonly ?string $oldConstraint,
        public readonly ?string $newConstraint,
        public readonly bool $rectorRulesAvailable,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'old_package' => $this->oldPackage,
            'new_package' => $this->newPackage,
            'old_constraint' => $this->oldConstraint,
            'new_constraint' => $this->newConstraint,
            'rector_rules_available' => $this->rectorRulesAvailable,
            'code_update_required' => !$this->rectorRulesAvailable,
        ];
    }
}

<?php

declare(strict_types=1);

namespace AppContainer\Composer;

/**
 * Represents a single dependency change.
 */
final class DependencyChange
{
    /**
     * @param string $package The package name (e.g., "laravel/framework")
     * @param string|null $oldConstraint The previous version constraint (null for additions)
     * @param string|null $newConstraint The new version constraint (null for removals)
     * @param ChangeType $type The type of change
     * @param string $reason Human-readable explanation for the change
     * @param int $timestamp Unix timestamp of when the change was recorded
     */
    public function __construct(
        public readonly string $package,
        public readonly ?string $oldConstraint,
        public readonly ?string $newConstraint,
        public readonly ChangeType $type,
        public readonly string $reason,
        public readonly int $timestamp,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'old_constraint' => $this->oldConstraint,
            'new_constraint' => $this->newConstraint,
            'type' => $this->type->value,
            'reason' => $this->reason,
            'timestamp' => $this->timestamp,
        ];
    }
}

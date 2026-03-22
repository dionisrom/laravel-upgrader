<?php

declare(strict_types=1);

namespace AppContainer\Composer;

final readonly class UpgradeResult
{
    /**
     * @param DependencyBlocker[] $blockers
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $packagesUpdated,
        public readonly array $blockers,
        public readonly string|null $errorMessage,
    ) {}

    /** @param DependencyBlocker[] $blockers */
    public static function success(int $packagesUpdated, array $blockers): self
    {
        return new self(
            success: true,
            packagesUpdated: $packagesUpdated,
            blockers: $blockers,
            errorMessage: null,
        );
    }

    /** @param DependencyBlocker[] $blockers */
    public static function failure(string $message, array $blockers): self
    {
        return new self(
            success: false,
            packagesUpdated: 0,
            blockers: $blockers,
            errorMessage: $message,
        );
    }
}

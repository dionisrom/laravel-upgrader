<?php

declare(strict_types=1);

namespace AppContainer\Config;

final readonly class MigrationResult
{
    /**
     * @param string[] $appliedMigrations
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $appliedMigrations,
        public readonly string|null $errorMessage,
        public readonly string|null $rolledBackFrom,
    ) {}

    /** @param string[] $appliedMigrations */
    public static function success(array $appliedMigrations): self
    {
        return new self(
            success: true,
            appliedMigrations: $appliedMigrations,
            errorMessage: null,
            rolledBackFrom: null,
        );
    }

    public static function failure(string $message, string $snapshotPath): self
    {
        return new self(
            success: false,
            appliedMigrations: [],
            errorMessage: $message,
            rolledBackFrom: $snapshotPath,
        );
    }
}

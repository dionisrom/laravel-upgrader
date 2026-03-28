<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class EloquentDetectionResult
{
    public function __construct(
        public readonly bool $eloquentEnabled,
        public readonly bool $databaseConfigExists,
        public readonly string|null $warning,
    ) {}

    public static function enabled(bool $dbConfigExists): self
    {
        return new self(
            eloquentEnabled: true,
            databaseConfigExists: $dbConfigExists,
            warning: $dbConfigExists ? null : 'withEloquent() called but config/database.php not found',
        );
    }

    public static function disabled(): self
    {
        return new self(
            eloquentEnabled: false,
            databaseConfigExists: false,
            warning: 'withEloquent() was not called in bootstrap/app.php — Eloquent may be disabled in the migrated app',
        );
    }
}

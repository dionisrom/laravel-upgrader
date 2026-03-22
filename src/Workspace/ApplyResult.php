<?php

declare(strict_types=1);

namespace App\Workspace;

final readonly class ApplyResult
{
    public function __construct(
        public int $appliedCount,
        public int $skippedCount,
        public int $failedCount,
        public ?string $failedFile,
    ) {}

    public function hasFailure(): bool
    {
        return $this->failedCount > 0;
    }
}

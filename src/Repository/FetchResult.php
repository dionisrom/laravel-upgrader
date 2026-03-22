<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class FetchResult
{
    public function __construct(
        public string $workspacePath,
        public string $lockFilePath,
        public string $defaultBranch,
        public string $resolvedCommitSha,
    ) {}
}

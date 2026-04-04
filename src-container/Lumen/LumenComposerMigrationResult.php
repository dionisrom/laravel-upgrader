<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class LumenComposerMigrationResult
{
    /**
     * @param list<string> $removedPackages
     * @param list<LumenManualReviewItem> $manualReviewItems
     */
    public function __construct(
        public readonly array $removedPackages,
        public readonly array $manualReviewItems,
    ) {}
}
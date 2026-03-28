<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class RoutesMigrationResult
{
    /**
     * @param int $migratedCount  routes successfully converted to Route:: facade syntax
     * @param int $flaggedCount   routes flagged for manual review
     * @param string[] $outputFiles  target route files written
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $migratedCount,
        public readonly int $flaggedCount,
        public readonly array $outputFiles,
        public readonly array $manualReviewItems,
        public readonly string|null $errorMessage,
    ) {}

    /**
     * @param string[] $outputFiles
     * @param LumenManualReviewItem[] $manualReviewItems
     */
    public static function success(int $migrated, int $flagged, array $outputFiles, array $manualReviewItems): self
    {
        return new self(
            success: true,
            migratedCount: $migrated,
            flaggedCount: $flagged,
            outputFiles: $outputFiles,
            manualReviewItems: $manualReviewItems,
            errorMessage: null,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            migratedCount: 0,
            flaggedCount: 0,
            outputFiles: [],
            manualReviewItems: [],
            errorMessage: $message,
        );
    }
}

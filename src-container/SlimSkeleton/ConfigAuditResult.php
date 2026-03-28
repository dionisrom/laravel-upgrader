<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class ConfigAuditResult
{
    /**
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     */
    public function __construct(
        public readonly bool $success,
        public readonly string|null $errorMessage,
        public readonly array $manualReviewItems,
    ) {}

    /**
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     */
    public static function success(array $manualReviewItems): self
    {
        return new self(
            success: true,
            errorMessage: null,
            manualReviewItems: $manualReviewItems,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            manualReviewItems: [],
        );
    }
}

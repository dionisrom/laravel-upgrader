<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

final class SlimSkeletonAuditResult
{
    /**
     * @param SlimSkeletonManualReviewItem[] $manualReviewItems
     * @param array<string, mixed>           $summary
     */
    public function __construct(
        public readonly int $totalManualReviewItems,
        public readonly int $errorCount,
        public readonly int $warningCount,
        public readonly int $infoCount,
        public readonly array $manualReviewItems,
        public readonly array $summary,
    ) {}

    /**
     * @param SlimSkeletonManualReviewItem[] $items
     * @param array<string, mixed>           $summary
     */
    public static function fromItems(array $items, array $summary): self
    {
        $errors   = array_filter($items, fn(SlimSkeletonManualReviewItem $i) => $i->severity === 'error');
        $warnings = array_filter($items, fn(SlimSkeletonManualReviewItem $i) => $i->severity === 'warning');
        $infos    = array_filter($items, fn(SlimSkeletonManualReviewItem $i) => $i->severity === 'info');

        return new self(
            totalManualReviewItems: count($items),
            errorCount: count($errors),
            warningCount: count($warnings),
            infoCount: count($infos),
            manualReviewItems: $items,
            summary: $summary,
        );
    }
}

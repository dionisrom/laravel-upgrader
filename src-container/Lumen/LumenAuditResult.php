<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final readonly class LumenAuditResult
{
    /**
     * @param LumenManualReviewItem[] $manualReviewItems
     * @param array<string, mixed>    $summary
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
     * @param LumenManualReviewItem[] $items
     * @param array<string, mixed>    $summary
     */
    public static function fromItems(array $items, array $summary): self
    {
        $errors = array_filter($items, fn(LumenManualReviewItem $i) => $i->severity === 'error');
        $warnings = array_filter($items, fn(LumenManualReviewItem $i) => $i->severity === 'warning');
        $infos = array_filter($items, fn(LumenManualReviewItem $i) => $i->severity === 'info');

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

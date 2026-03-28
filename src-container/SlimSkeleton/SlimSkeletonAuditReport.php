<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

/**
 * Collects all manual-review items from each slim skeleton migration step
 * and emits a final `slim_skeleton_audit` JSON-ND event.
 *
 * Usage:
 *   $report = new SlimSkeletonAuditReport();
 *   $report->addItems($kernelResult->manualReviewItems);
 *   $report->addItems($handlerResult->manualReviewItems);
 *   $result = $report->generate($workspacePath, $migrationSummary);
 */
final class SlimSkeletonAuditReport
{
    /** @var SlimSkeletonManualReviewItem[] */
    private array $items = [];

    /**
     * @param SlimSkeletonManualReviewItem[] $items
     */
    public function addItems(array $items): void
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
    }

    public function addItem(SlimSkeletonManualReviewItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * Generate the audit report and emit `slim_skeleton_audit` JSON-ND event.
     *
     * @param array<string, mixed> $migrationSummary  counts and metadata from all migration steps
     */
    public function generate(string $workspacePath, array $migrationSummary = []): SlimSkeletonAuditResult
    {
        $byCategory = $this->groupByCategory();
        $summary    = array_merge($migrationSummary, [
            'workspace'   => $workspacePath,
            'total_items' => count($this->items),
            'by_category' => array_map('count', $byCategory),
            'by_severity' => $this->countBySeverity(),
        ]);

        $result = SlimSkeletonAuditResult::fromItems($this->items, $summary);

        $this->emitAuditEvent($result, $workspacePath);

        return $result;
    }

    /**
     * @return array<string, SlimSkeletonManualReviewItem[]>
     */
    private function groupByCategory(): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $groups[$item->category][] = $item;
        }
        return $groups;
    }

    /**
     * @return array<string, int>
     */
    private function countBySeverity(): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($this->items as $item) {
            $counts[$item->severity]++;
        }
        return $counts;
    }

    private function emitAuditEvent(SlimSkeletonAuditResult $result, string $workspacePath): void
    {
        $serialisedItems = array_map(
            fn(SlimSkeletonManualReviewItem $item) => [
                'category'    => $item->category,
                'file'        => $item->file,
                'line'        => $item->line,
                'description' => $item->description,
                'severity'    => $item->severity,
                'suggestion'  => $item->suggestion,
            ],
            $result->manualReviewItems
        );

        $event = [
            'event'        => 'slim_skeleton_audit',
            'workspace'    => $workspacePath,
            'total_items'  => $result->totalManualReviewItems,
            'errors'       => $result->errorCount,
            'warnings'     => $result->warningCount,
            'infos'        => $result->infoCount,
            'items'        => $serialisedItems,
            'summary'      => $result->summary,
        ];

        echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

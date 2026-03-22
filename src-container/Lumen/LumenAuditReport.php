<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

/**
 * Collects all manual-review items from each migration step and emits a
 * final `lumen_audit` JSON-ND event summarising the full migration (F-08).
 *
 * Usage:
 *   $report = new LumenAuditReport();
 *   $report->addItems($routesResult->manualReviewItems);
 *   $report->addItems($providersResult->manualReviewItems);
 *   // … add items from all migration steps …
 *   $result = $report->generate($workspacePath, $migrationSummary);
 */
final class LumenAuditReport
{
    /** @var LumenManualReviewItem[] */
    private array $items = [];

    /**
     * @param LumenManualReviewItem[] $items
     */
    public function addItems(array $items): void
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
    }

    public function addItem(LumenManualReviewItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * Generate the audit report and emit `lumen_audit` JSON-ND event.
     *
     * @param array<string, mixed> $migrationSummary  counts and metadata from all migration steps
     */
    public function generate(string $workspacePath, array $migrationSummary = []): LumenAuditResult
    {
        $byCategory = $this->groupByCategory();
        $summary = array_merge($migrationSummary, [
            'workspace'       => $workspacePath,
            'total_items'     => count($this->items),
            'by_category'     => array_map('count', $byCategory),
            'by_severity'     => $this->countBySeverity(),
        ]);

        $result = LumenAuditResult::fromItems($this->items, $summary);

        $this->emitAuditEvent($result, $workspacePath);

        return $result;
    }

    /**
     * @return array<string, LumenManualReviewItem[]>
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

    private function emitAuditEvent(LumenAuditResult $result, string $workspacePath): void
    {
        $serialisedItems = array_map(
            fn(LumenManualReviewItem $item) => [
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
            'event'        => 'lumen_audit',
            'workspace'    => $workspacePath,
            'total_items'  => $result->totalManualReviewItems,
            'errors'       => $result->errorCount,
            'warnings'     => $result->warningCount,
            'infos'        => $result->infoCount,
            'summary'      => $result->summary,
            'items'        => $serialisedItems,
            'ts'           => time(),
        ];

        echo json_encode($event) . "\n";
    }
}

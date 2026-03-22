<?php

declare(strict_types=1);

namespace AppContainer\Report\Formatters;

use AppContainer\Report\ConfidenceScorer;
use AppContainer\Report\ReportData;

final class MarkdownFormatter
{
    private ConfidenceScorer $scorer;

    public function __construct()
    {
        $this->scorer = new ConfidenceScorer();
    }

    /**
     * Produce `manual-review.md` sorted by severity descending (blockers/high first).
     */
    public function format(ReportData $data): string
    {
        $score = $this->scorer->score($data);
        $label = $this->scorer->label($score);

        $header = $this->buildHeader($data, $score, $label);

        if (empty($data->manualReviewItems)) {
            return $header . "\n---\n\nNo manual review required. All changes were applied automatically.\n";
        }

        // Partition items into high-severity (blockers) and medium.
        /** @var list<array{id: string, automated: bool, reason: string, files: list<string>}> $blockers */
        $blockers = [];
        /** @var list<array{id: string, automated: bool, reason: string, files: list<string>}> $warnings */
        $warnings = [];

        foreach ($data->manualReviewItems as $item) {
            if ($this->isHighSeverity($item)) {
                $blockers[] = $item;
            } else {
                $warnings[] = $item;
            }
        }

        $output = $header . "\n---\n\n";

        if (!empty($blockers)) {
            $count   = count($blockers);
            $output .= "## Blockers ({$count}) — Must fix before deployment\n\n";
            foreach ($blockers as $index => $item) {
                $output .= $this->renderItem($item, 'BLOCKER-' . ($index + 1));
            }
        }

        if (!empty($warnings)) {
            $count   = count($warnings);
            $output .= "## Warnings ({$count}) — Recommended fixes\n\n";
            foreach ($warnings as $index => $item) {
                $output .= $this->renderItem($item, 'WARNING-' . ($index + 1));
            }
        }

        return $output;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function buildHeader(ReportData $data, int $score, string $label): string
    {
        return <<<MD
        # Manual Review Required — {$data->repoName} L{$data->fromVersion}→L{$data->toVersion}

        Generated: {$data->timestamp}
        Confidence: {$score}% ({$label})

        MD;
    }

    /**
     * @param array{id: string, automated: bool, reason: string, files: list<string>} $item
     */
    private function renderItem(array $item, string $displayLabel): string
    {
        $id     = $item['id'];
        $reason = $item['reason'];
        $files  = array_map(fn(string $f) => "- `{$f}`", $item['files']);
        $fileList = implode("\n", $files);

        return <<<MD
        ### {$displayLabel}: {$id}
        **Description:** {$reason}
        **Files:**
        {$fileList}

        ---

        MD;
    }

    /**
     * High severity: id starts with BC- or BLOCKER, or reason contains "incompatible".
     *
     * @param array{id: string, automated: bool, reason: string, files: list<string>} $item
     */
    private function isHighSeverity(array $item): bool
    {
        return str_starts_with($item['id'], 'BC-')
            || stripos($item['id'], 'BLOCKER') !== false
            || stripos($item['reason'], 'incompatible') !== false;
    }
}

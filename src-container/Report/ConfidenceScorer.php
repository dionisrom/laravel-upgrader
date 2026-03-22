<?php

declare(strict_types=1);

namespace AppContainer\Report;

final class ConfidenceScorer
{
    /**
     * Compute the overall confidence score (0–100) for a completed upgrade run.
     *
     * Algorithm (TRD-REPORT-003):
     *   base = 100
     *   − 2 per file with manual_review_required (max deduction 30)
     *   − 10 per unresolved dependency_blocker
     *   − 15 per phpstan_regression
     *   Hard override: syntax_error → 0
     */
    public function score(ReportData $data): int
    {
        if ($data->hasSyntaxError) {
            return 0;
        }

        $base = 100;

        // Collect unique files that appear in manual review items.
        $manualReviewFiles = [];
        foreach ($data->manualReviewItems as $item) {
            foreach ($item['files'] as $file) {
                $manualReviewFiles[$file] = true;
            }
        }

        $manualDeduction = min(count($manualReviewFiles) * 2, 30);
        $blockerDeduction = count($data->dependencyBlockers) * 10;
        $regressionDeduction = count($data->phpstanRegressions) * 15;

        $score = $base - $manualDeduction - $blockerDeduction - $regressionDeduction;

        return max(0, min(100, $score));
    }

    /**
     * Per-file confidence score (0–100).
     * A file with any manual review item gets a lower score.
     *
     * @param non-empty-string $relativeFilePath
     */
    public function fileScore(string $relativeFilePath, ReportData $data): int
    {
        if ($data->hasSyntaxError) {
            return 0;
        }

        foreach ($data->manualReviewItems as $item) {
            if (in_array($relativeFilePath, $item['files'], true)) {
                return 40;
            }
        }

        return 100;
    }

    /**
     * Human-readable label for a score.
     * High ≥ 80, Medium 50–79, Low < 50.
     */
    public function label(int $score): string
    {
        if ($score >= 80) {
            return 'High';
        }

        if ($score >= 50) {
            return 'Medium';
        }

        return 'Low';
    }
}

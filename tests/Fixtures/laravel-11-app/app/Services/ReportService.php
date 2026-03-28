<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Fixture service with static-cache memoization pattern.
 * OnceHelperIntroducer should detect this for once() suggestion.
 */
class ReportService
{
    /**
     * Expensive computation using static-cache pattern.
     * In Laravel 12 this can be: return once(fn() => $this->buildReport());
     */
    public function getReport(): array
    {
        static $report = null;
        if ($report === null) {
            $report = $this->buildReport();
        }
        return $report;
    }

    private function buildReport(): array
    {
        return ['total' => 42, 'items' => []];
    }
}

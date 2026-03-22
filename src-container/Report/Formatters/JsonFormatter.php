<?php

declare(strict_types=1);

namespace AppContainer\Report\Formatters;

use AppContainer\Report\ConfidenceScorer;
use AppContainer\Report\ReportData;

final class JsonFormatter
{
    public function __construct(private readonly ConfidenceScorer $scorer) {}

    /**
     * Produce a machine-readable JSON report for CI/CD consumption.
     * SECURITY: source code, file contents, tokens, and absolute paths are
     * never emitted (TRD-SEC-003).
     */
    public function format(ReportData $data): string
    {
        $score = $this->scorer->score($data);
        $label = $this->scorer->label($score);

        $manualReviewItems = array_map(
            fn(array $item): array => [
                'id'       => $item['id'],
                'reason'   => $item['reason'],
                'files'    => $item['files'],     // relative paths only — no absolute paths
                'severity' => $this->deriveSeverity($item),
            ],
            $data->manualReviewItems,
        );

        $dependencyBlockers = array_map(
            fn(array $b): array => [
                'package'         => $b['package'],
                'current_version' => $b['current_version'],
                'severity'        => $b['severity'],
            ],
            $data->dependencyBlockers,
        );

        $verificationResults = array_map(
            fn(array $v): array => [
                'step'        => $v['step'],
                'passed'      => $v['passed'],
                'issue_count' => $v['issue_count'],
            ],
            $data->verificationResults,
        );

        $payload = [
            'schema_version' => '1',
            'run_id'         => $data->runId,
            'repo'           => [
                'name' => $data->repoName,
                'sha'  => $data->repoSha,
            ],
            'upgrade' => [
                'from'         => $data->fromVersion,
                'to'           => $data->toVersion,
                'timestamp'    => $data->timestamp,
                'host_version' => $data->hostVersion,
            ],
            'confidence' => [
                'score' => $score,
                'label' => $label,
            ],
            'summary' => [
                'total_files_scanned'   => $data->totalFilesScanned,
                'files_changed'         => $data->totalFilesChanged,
                'auto_fixed'            => max(0, count($data->fileDiffs) - count($data->manualReviewItems)),
                'manual_review_required' => count($data->manualReviewItems),
                'dependency_blockers'   => count($data->dependencyBlockers),
                'phpstan_regressions'   => count($data->phpstanRegressions),
                'syntax_errors'         => $data->hasSyntaxError,
            ],
            'manual_review_items'  => $manualReviewItems,
            'dependency_blockers'  => $dependencyBlockers,
            'verification_results' => $verificationResults,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * Derive severity from item id / reason.
     * Items whose id starts with BC- or whose reason contains "incompatible" → high.
     * All others → medium.
     *
     * @param array{id: string, automated: bool, reason: string, files: list<string>} $item
     */
    private function deriveSeverity(array $item): string
    {
        if (
            str_starts_with($item['id'], 'BC-')
            || stripos($item['reason'], 'incompatible') !== false
            || stripos($item['id'], 'BLOCKER') !== false
        ) {
            return 'high';
        }

        return 'medium';
    }
}

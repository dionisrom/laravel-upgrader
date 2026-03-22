<?php

declare(strict_types=1);

namespace AppContainer\Report;

final readonly class ReportData
{
    /**
     * @param list<array{file: string, diff: string, rules: list<string>}> $fileDiffs
     * @param list<array{id: string, automated: bool, reason: string, files: list<string>}> $manualReviewItems
     * @param list<array{package: string, current_version: string, severity: string}> $dependencyBlockers
     * @param list<array{before_count: int, after_count: int, new_errors: list<string>}> $phpstanRegressions
     * @param list<array{step: string, passed: bool, issue_count: int}> $verificationResults
     * @param list<array<string, mixed>> $auditEvents Raw JSON-ND events from pipeline
     */
    public function __construct(
        public string $repoName,
        public string $fromVersion,
        public string $toVersion,
        public string $runId,
        public string $repoSha,
        public string $hostVersion,
        public string $timestamp,
        public array $fileDiffs,
        public array $manualReviewItems,
        public array $dependencyBlockers,
        public array $phpstanRegressions,
        public array $verificationResults,
        public array $auditEvents,
        public bool $hasSyntaxError,
        public int $totalFilesScanned,
        public int $totalFilesChanged,
    ) {}
}

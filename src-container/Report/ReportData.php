<?php

declare(strict_types=1);

namespace AppContainer\Report;

final class ReportData
{
    /**
     * @param list<array{file: string, diff: string, rules: list<string>}> $fileDiffs
     * @param list<array{id: string, automated: bool, reason: string, files: list<string>, snippet?: string}> $manualReviewItems
     * @param list<array{package: string, current_version: string, severity: string}> $dependencyBlockers
     * @param list<array{before_count: int, after_count: int, new_errors: list<string>}> $phpstanRegressions
     * @param list<array{step: string, passed: bool, issue_count: int}> $verificationResults
     * @param list<array<string, mixed>> $auditEvents Raw JSON-ND events from pipeline
     */
    public function __construct(
        public readonly string $repoName,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly string $runId,
        public readonly string $repoSha,
        public readonly string $hostVersion,
        public readonly string $timestamp,
        public readonly array $fileDiffs,
        public readonly array $manualReviewItems,
        public readonly array $dependencyBlockers,
        public readonly array $phpstanRegressions,
        public readonly array $verificationResults,
        public readonly array $auditEvents,
        public readonly bool $hasSyntaxError,
        public readonly int $totalFilesScanned,
        public readonly int $totalFilesChanged,
    ) {}
}

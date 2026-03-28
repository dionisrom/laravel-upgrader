<?php

declare(strict_types=1);

namespace App\Report;

use App\State\HopResult;

final class ChainReportArtifactWriter
{
    private readonly DiffViewerV2Generator $diffViewer;

    public function __construct(
        private readonly ChainEventAggregator $aggregator = new ChainEventAggregator(),
        private readonly DirectoryDiffCollector $diffCollector = new DirectoryDiffCollector(),
        ?DiffViewerV2Generator $diffViewer = null,
    ) {
        if ($diffViewer !== null) {
            $this->diffViewer = $diffViewer;
            return;
        }

        $workspaceRoot     = dirname(__DIR__, 2);
        $this->diffViewer = new DiffViewerV2Generator(
            assetsDir: $workspaceRoot . '/assets',
            templatesDir: $workspaceRoot . '/templates',
        );
    }

    /**
     * @param list<HopResult> $hopResults
     * @return array{html: string, json: string}
     */
    public function write(
        string $chainId,
        string $sourceVersion,
        string $targetVersion,
        array $hopResults,
        string $outputDir,
    ): array {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true)) {
            throw new \RuntimeException(sprintf('Failed to create chain report directory: %s', $outputDir));
        }

        $hopStreams = [];
        $hopFileDiffs = [];

        foreach ($hopResults as $hopResult) {
            $hopKey = sprintf('%s->%s', $hopResult->fromVersion, $hopResult->toVersion);
            $hopStreams[$hopKey] = $hopResult->events;

            if ($hopResult->inputPath !== null && is_dir($hopResult->inputPath) && is_dir($hopResult->outputPath)) {
                $hopFileDiffs[$hopKey] = $this->diffCollector->collect($hopResult->inputPath, $hopResult->outputPath);
            } else {
                $hopFileDiffs[$hopKey] = [];
            }
        }

        $chainReport = $this->aggregator->aggregate($chainId, $sourceVersion, $targetVersion, $hopStreams);
        $html        = $this->diffViewer->generate($chainReport, $hopFileDiffs);

        $hopSummaries = array_map(
            fn (HopReport $hopReport): array => $this->buildHopSummary(
                $hopReport,
                $hopFileDiffs[$hopReport->hopKey] ?? [],
            ),
            $chainReport->hopReports,
        );

        $totalFilesChanged = array_sum(array_map(
            static fn (array $hopSummary): int => $hopSummary['files_changed'],
            $hopSummaries,
        ));
        $totalManualReviewItems = array_sum(array_map(
            static fn (array $hopSummary): int => $hopSummary['manual_review'],
            $hopSummaries,
        ));

        $jsonPayload = [
            'chain_id'                  => $chainReport->chainId,
            'source_version'            => $chainReport->sourceVersion,
            'target_version'            => $chainReport->targetVersion,
            'total_events'              => $chainReport->totalEvents,
            'total_files_changed'       => $totalFilesChanged,
            'total_manual_review_items' => $totalManualReviewItems,
            'hops'                      => $hopSummaries,
        ];

        $json = json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode chain report JSON.');
        }

        $htmlPath = $outputDir . '/chain-report.html';
        $jsonPath = $outputDir . '/chain-report.json';

        file_put_contents($htmlPath, $html);
        file_put_contents($jsonPath, $json);

        return ['html' => $htmlPath, 'json' => $jsonPath];
    }

    /**
     * @param list<array{file: string, diff: string, rules: list<string>}> $fileDiffs
     * @return array<string, mixed>
     */
    private function buildHopSummary(HopReport $hopReport, array $fileDiffs): array
    {
        $manualReviewItems = [];
        $resourceUsage = null;

        foreach ($hopReport->events as $event) {
            if (($event['event'] ?? '') === 'manual_review_required') {
                $manualReviewItems[] = [
                    'id' => (string) ($event['id'] ?? ''),
                    'reason' => (string) ($event['reason'] ?? ''),
                    'files' => array_values(array_filter(
                        (array) ($event['files'] ?? []),
                        static fn (mixed $file): bool => is_string($file),
                    )),
                ];
                continue;
            }

            if (($event['event'] ?? '') === 'container_resource_usage') {
                $resourceUsage = [
                    'memory_peak_bytes' => isset($event['memory_peak_bytes']) ? (int) $event['memory_peak_bytes'] : null,
                    'memory_limit_bytes' => isset($event['memory_limit_bytes']) ? (int) $event['memory_limit_bytes'] : null,
                    'source' => (string) ($event['source'] ?? 'unknown'),
                ];
            }
        }

        $changedFiles = array_map(
            static fn (array $diff): string => $diff['file'],
            $fileDiffs,
        );

        return [
            'hop' => $hopReport->hopKey,
            'from' => $hopReport->fromVersion,
            'to' => $hopReport->toVersion,
            'event_count' => $hopReport->eventCount,
            'files_changed' => count($changedFiles),
            'changed_files' => $changedFiles,
            'manual_review' => count($manualReviewItems),
            'manual_review_items' => $manualReviewItems,
            'resource_usage' => $resourceUsage,
        ];
    }
}
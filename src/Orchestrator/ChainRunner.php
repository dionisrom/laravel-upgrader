<?php

declare(strict_types=1);

namespace App\Orchestrator;

use App\Report\ChainReportArtifactWriter;
use App\State\ChainCheckpoint;
use App\State\HopResult;
use Ramsey\Uuid\Uuid;

/**
 * Executes a multi-hop upgrade chain by running containers sequentially.
 *
 * Each hop's output workspace becomes the next hop's input workspace.
 * A {@see ChainCheckpoint} is persisted in {@code $checkpointDir} after every
 * hop so that a failed chain can be resumed via {@code --resume}.
 *
 * Verification gate: if the container does not emit a {@code pipeline_complete}
 * event with {@code passed: true}, the chain is aborted before the next hop.
 */
final class ChainRunner
{
    public function __construct(
        private readonly MultiHopPlanner $planner,
        private readonly DockerRunner $dockerRunner,
        private readonly EventStreamer $streamer,
        private readonly ChainResumeHandler $resumeHandler,
        /** Base directory under which per-hop output dirs are created. */
        private readonly string $outputBaseDir,
        /** Stable directory where chain-checkpoint.json is persisted. */
        private readonly string $checkpointDir,
        private readonly ?ChainReportArtifactWriter $reportWriter = null,
    ) {}

    /**
     * Runs (or resumes) the full upgrade chain from $fromVersion to $toVersion.
     *
     * @param bool $resume When true, an existing {@see ChainCheckpoint} is read
     *                     from {@code $checkpointDir} and execution resumes from
     *                     the first incomplete hop.
     *
     * @throws OrchestratorException on planning errors, hop failures, or
     *                               verification gate failures
     */
    public function run(
        string $workspacePath,
        string $fromVersion,
        string $toVersion,
        bool $resume = false,
    ): ChainRunResult {
        try {
            $sequence = $this->planner->plan($fromVersion, $toVersion);
        } catch (InvalidHopException $e) {
            throw new OrchestratorException(
                sprintf('Invalid chain plan: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        // -----------------------------------------------------------------------
        // Initialise or restore checkpoint
        // -----------------------------------------------------------------------
        $checkpoint = null;
        $startIndex = 0;

        if ($resume) {
            $checkpoint = $this->resumeHandler->readCheckpoint($this->checkpointDir);
        }

        if ($checkpoint !== null) {
            if ($checkpoint->sourceVersion !== $fromVersion || $checkpoint->targetVersion !== $toVersion) {
                throw new OrchestratorException(sprintf(
                    'Checkpoint version mismatch: checkpoint is for %s→%s but requested %s→%s. '
                    . 'Delete the checkpoint file or use matching --from/--to flags.',
                    $checkpoint->sourceVersion,
                    $checkpoint->targetVersion,
                    $fromVersion,
                    $toVersion,
                ));
            }

            $startIndex    = $this->resumeHandler->findResumeIndex($checkpoint, $sequence);
            $chainId       = $checkpoint->chainId;
            $workspacePath = $checkpoint->workspacePath;
        } else {
            $chainId    = Uuid::uuid4()->toString();
            $checkpoint = new ChainCheckpoint(
                chainId:       $chainId,
                sourceVersion: $fromVersion,
                targetVersion: $toVersion,
                completedHops: [],
                currentHop:    null,
                workspacePath: $workspacePath,
                startedAt:     new \DateTimeImmutable(),
                updatedAt:     null,
            );
        }

        // -----------------------------------------------------------------------
        // Attach an event collector to the shared streamer
        // -----------------------------------------------------------------------
        $collector = new EventCollector();
        $this->streamer->addConsumer($collector);

        // Pre-populate hopEvents with data from already-completed hops (resume).
        /** @var array<string, list<array<string, mixed>>> $hopEvents */
        $hopEvents = [];

        foreach ($checkpoint->completedHops as $completedHop) {
            $key             = sprintf('%s->%s', $completedHop->fromVersion, $completedHop->toVersion);
            $hopEvents[$key] = $completedHop->events;
        }

        $currentWorkspace = $workspacePath;

        // -----------------------------------------------------------------------
        // Execute hops sequentially
        // -----------------------------------------------------------------------
        foreach ($sequence->hops as $index => $hop) {
            if ($index < $startIndex) {
                // Already completed in a previous run — skip without re-running.
                continue;
            }

            $hopKey    = sprintf('%s->%s', $hop->fromVersion, $hop->toVersion);
            $hopDirName = sprintf('%s-to-%s', $hop->fromVersion, $hop->toVersion);
            $outputDir = implode(\DIRECTORY_SEPARATOR, [
                rtrim($this->outputBaseDir, \DIRECTORY_SEPARATOR),
                $chainId,
                $hopDirName,
            ]);

            if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true)) {
                throw new OrchestratorException(sprintf(
                    'Failed to create output directory for hop %s: %s',
                    $hopKey,
                    $outputDir,
                ));
            }

            // Stage the current workspace into the output directory so the
            // container can transform it in-place at UPGRADER_WORKSPACE=/repo.
            // This preserves the original $currentWorkspace snapshot for report
            // diffing while ensuring the next hop receives a populated workspace.
            $this->copyDirectory($currentWorkspace, $outputDir);

            // Persist "in-progress" state before running the container.
            $checkpoint = $checkpoint->withCurrentHop($hopKey);
            $this->resumeHandler->writeCheckpoint($checkpoint, $this->checkpointDir);

            $collector->reset();

            try {
                $this->dockerRunner->run($hop, $currentWorkspace, $outputDir, $this->streamer);
            } catch (HopFailureException $e) {
                $this->writePartialReport($chainId, $fromVersion, $toVersion, $checkpoint);

                throw new OrchestratorException(
                    sprintf(
                        'Chain aborted: hop %s failed with exit code %d. Last stderr: %s',
                        $hopKey,
                        $e->getExitCode(),
                        implode(' | ', $e->getLastStderrLines()),
                    ),
                    0,
                    $e,
                );
            }

            // Verification gate: abort if container did not signal a passing run.
            if (!$collector->isVerificationPassed()) {
                $this->writePartialReport($chainId, $fromVersion, $toVersion, $checkpoint);

                throw new OrchestratorException(sprintf(
                    'Chain aborted: hop %s completed but did not pass verification.',
                    $hopKey,
                ));
            }

            $hopEventList       = $collector->getEvents();
            $hopEvents[$hopKey] = $hopEventList;

            $hopResult = new HopResult(
                fromVersion: $hop->fromVersion,
                toVersion:   $hop->toVersion,
                dockerImage: $hop->dockerImage,
                outputPath:  $outputDir,
                completedAt: new \DateTimeImmutable(),
                events:      $hopEventList,
                inputPath:   $currentWorkspace,
            );

            // The output dir becomes the next hop's workspace.
            $currentWorkspace = $outputDir;

            // Persist completed state.
            $checkpoint = $checkpoint->withCompletedHop($hopResult, $currentWorkspace);
            $this->resumeHandler->writeCheckpoint($checkpoint, $this->checkpointDir);

            // TRD-P2MULTI-003: Incrementally persist report context after each hop.
            $this->writeReportContext($chainId, $fromVersion, $toVersion, $checkpoint);

            $this->streamer->dispatch([
                'event'   => 'chain_hop_complete',
                'hop'     => $hopKey,
                'chainId' => $chainId,
                'ts'      => time(),
            ]);
        }

        $reportHtmlPath = null;
        $reportJsonPath = null;

        if ($checkpoint->completedHops !== []) {
            $reportArtifacts = $this->reportWriter()->write(
                chainId: $chainId,
                sourceVersion: $fromVersion,
                targetVersion: $toVersion,
                hopResults: $checkpoint->completedHops,
                outputDir: implode(\DIRECTORY_SEPARATOR, [
                    rtrim($this->outputBaseDir, \DIRECTORY_SEPARATOR),
                    $chainId,
                ]),
            );

            $reportHtmlPath = $reportArtifacts['html'];
            $reportJsonPath = $reportArtifacts['json'];
        }

        return new ChainRunResult(
            chainId:       $chainId,
            sourceVersion: $fromVersion,
            targetVersion: $toVersion,
            hops:          $sequence->hops,
            hopEvents:     $hopEvents,
            workspacePath: $currentWorkspace,
            reportHtmlPath: $reportHtmlPath,
            reportJsonPath: $reportJsonPath,
        );
    }

    private function reportWriter(): ChainReportArtifactWriter
    {
        return $this->reportWriter ?? new ChainReportArtifactWriter();
    }

    /**
     * Writes a partial report for completed hops before aborting the chain.
     * Swallows exceptions so the original abort exception propagates cleanly.
     */
    private function writePartialReport(
        string $chainId,
        string $sourceVersion,
        string $targetVersion,
        ChainCheckpoint $checkpoint,
    ): void {
        if ($checkpoint->completedHops === []) {
            return;
        }

        try {
            $this->reportWriter()->write(
                chainId: $chainId,
                sourceVersion: $sourceVersion,
                targetVersion: $targetVersion,
                hopResults: $checkpoint->completedHops,
                outputDir: implode(\DIRECTORY_SEPARATOR, [
                    rtrim($this->outputBaseDir, \DIRECTORY_SEPARATOR),
                    $chainId,
                ]),
            );
        } catch (\Throwable) {
            // Best-effort — don't mask the original abort exception.
        }
    }

    /**
     * TRD-P2MULTI-003: Incrementally persists report-context.json after each hop.
     */
    private function writeReportContext(
        string $chainId,
        string $sourceVersion,
        string $targetVersion,
        ChainCheckpoint $checkpoint,
    ): void {
        $chainDir = implode(\DIRECTORY_SEPARATOR, [
            rtrim($this->outputBaseDir, \DIRECTORY_SEPARATOR),
            $chainId,
        ]);

        if (!is_dir($chainDir) && !mkdir($chainDir, 0700, true)) {
            return; // Best-effort.
        }

        $hopContexts = [];
        foreach ($checkpoint->completedHops as $hop) {
            $hopContexts[] = [
                'fromVersion' => $hop->fromVersion,
                'toVersion'   => $hop->toVersion,
                'dockerImage' => $hop->dockerImage,
                'outputPath'  => $hop->outputPath,
                'completedAt' => $hop->completedAt->format(\DateTimeInterface::ATOM),
                'eventCount'  => count($hop->events),
            ];
        }

        $context = [
            'chainId'       => $chainId,
            'sourceVersion' => $sourceVersion,
            'targetVersion' => $targetVersion,
            'completedHops' => $hopContexts,
            'updatedAt'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $json = json_encode($context, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($chainDir . \DIRECTORY_SEPARATOR . 'report-context.json', $json);
        }
    }

    /**
     * Recursively copies all files and directories from $src into $dst.
     * $dst must already exist. Existing files in $dst are overwritten.
     *
     * @throws OrchestratorException on copy failure
     */
    private function copyDirectory(string $src, string $dst): void
    {
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $rel  = substr((string) $item->getRealPath(), strlen(rtrim($src, \DIRECTORY_SEPARATOR)) + 1);
            $dest = $dst . \DIRECTORY_SEPARATOR . $rel;

            if ($item->isDir()) {
                if (!is_dir($dest) && !mkdir($dest, 0700, true)) {
                    throw new OrchestratorException(sprintf(
                        'Failed to create directory during workspace staging: %s',
                        $dest,
                    ));
                }
            } else {
                if (!copy((string) $item->getRealPath(), $dest)) {
                    throw new OrchestratorException(sprintf(
                        'Failed to copy file during workspace staging: %s → %s',
                        $item->getRealPath(),
                        $dest,
                    ));
                }
            }
        }
    }
}

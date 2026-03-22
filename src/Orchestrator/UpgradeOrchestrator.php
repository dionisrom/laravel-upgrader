<?php

declare(strict_types=1);

namespace App\Orchestrator;

use App\Workspace\WorkspaceManager;
use Ramsey\Uuid\Uuid;

final class UpgradeOrchestrator
{
    public function __construct(
        private readonly HopPlanner $hopPlanner,
        private readonly DockerRunner $dockerRunner,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EventStreamer $streamer,
        private readonly ?CheckpointManagerInterface $checkpoints = null,
    ) {}

    /**
     * Executes the full upgrade pipeline for $repoPath from $fromVersion to
     * $toVersion.
     *
     * The original repository is left unmodified until every hop has passed
     * verification. Write-back happens only after all hops succeed.
     *
     * @throws OrchestratorException on planning errors, hop failures, or
     *                               verification failures
     */
    public function run(
        string $repoPath,
        string $fromVersion,
        string $toVersion,
    ): OrchestratorResult {
        $runId = Uuid::uuid4()->toString();

        try {
            $sequence = $this->hopPlanner->plan($fromVersion, $toVersion);
        } catch (InvalidHopException $e) {
            throw new OrchestratorException(
                sprintf('Invalid hop plan: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        // Create a content-addressed, isolated copy of the source repository.
        // The original repo is never touched until writeBack() at the very end.
        $workspace = $this->workspaceManager->createWorkspace($repoPath, $toVersion);

        /** @var list<array<string, mixed>> $allEvents */
        $allEvents = [];

        // EventCollector is added once and reset between hops so we can detect
        // per-hop verification state without coupling the orchestrator to specific
        // consumer implementations.
        $collector = new EventCollector();
        $this->streamer->addConsumer($collector);

        $anyHopRan = false;

        foreach ($sequence->hops as $hop) {
            $hopKey = sprintf('%s->%s', $hop->fromVersion, $hop->toVersion);

            if ($this->checkpoints?->isCompleted($hop) === true) {
                $this->streamer->dispatch([
                    'event' => 'hop_skipped',
                    'hop'   => $hopKey,
                    'ts'    => time(),
                ]);
                continue;
            }

            // Each hop writes transformed output to its own temp directory, which
            // becomes the input workspace for the next hop.
            $outputPath = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR . 'upgrader-out'
                . DIRECTORY_SEPARATOR . hash('sha256', $runId . $hopKey);

            if (!is_dir($outputPath) && !mkdir($outputPath, 0700, true)) {
                throw new OrchestratorException(sprintf(
                    'Failed to create output directory for hop %s: %s',
                    $hopKey,
                    $outputPath,
                ));
            }

            $collector->reset();

            try {
                $this->dockerRunner->run($hop, $workspace, $outputPath, $this->streamer);
            } catch (HopFailureException $e) {
                throw new OrchestratorException(
                    sprintf(
                        'Hop %s failed with exit code %d. Last stderr: %s',
                        $hopKey,
                        $e->getExitCode(),
                        implode(' | ', $e->getLastStderrLines()),
                    ),
                    0,
                    $e,
                );
            }

            if (!$collector->isVerificationPassed()) {
                throw new OrchestratorException(sprintf(
                    'Verification failed for hop %s: no pipeline_complete event with passed=true was received.',
                    $hopKey,
                ));
            }

            /** @var list<array<string, mixed>> $hopEvents */
            $hopEvents = $collector->getEvents();
            $allEvents = array_values(array_merge($allEvents, $hopEvents));

            $this->checkpoints?->markCompleted($hop);

            // The output of this hop becomes the input workspace for the next hop.
            $workspace = $outputPath;
            $anyHopRan = true;
        }

        // Write-back to the original repository only after all hops succeed.
        if ($anyHopRan) {
            $this->workspaceManager->writeBack($workspace, $repoPath);
        }

        return new OrchestratorResult(
            success: true,
            runId: $runId,
            hops: $sequence->hops,
            events: $allEvents,
        );
    }
}

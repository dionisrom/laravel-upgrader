<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Orchestrator\ChainResumeHandler;
use App\Orchestrator\ChainRunner;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\MultiHopPlanner;
use App\State\ChainCheckpoint;
use App\State\HopResult;
use PHPUnit\Framework\Attributes\Group;

/**
 * E2E test: validates checkpoint/resume across all hop boundaries.
 *
 * Uses a fake DockerRunner so no Docker daemon is required. Simulates
 * an interrupted chain by writing a mid-hop checkpoint file before the
 * runner starts, then asserts that only remaining hops execute.
 *
 * @group e2e
 */
#[Group('e2e')]
final class ChainResumeE2ETest extends E2ETestCase
{
    // -------------------------------------------------------------------------
    // Resume from hop boundary
    // -------------------------------------------------------------------------

    public function testResumeFromHop9To10SkipsFirstHop(): void
    {
        $workspace     = $this->copyFixtureToTemp('fixture-minimal');
        $checkpointDir = $this->makeTempDir('-checkpoints');
        $outputDir     = $this->makeTempDir('-output');

        // Simulate that hop 8→9 has been completed
        $checkpoint = $this->makeCheckpointWithCompletedHops(
            chainId: 'e2e-resume-test-1',
            fromVersion: '8',
            toVersion: '13',
            workspacePath: $workspace,
            completedHops: [
                new HopResult('8', '9', 'upgrader/hop-8-to-9', $workspace, new \DateTimeImmutable()),
            ],
        );
        (new ChainResumeHandler())->writeCheckpoint($checkpoint, $checkpointDir);

        $executedHops = [];

        $dockerRunner = $this->makeTrackingDockerRunner($executedHops);
        $chainRunner  = $this->makeChainRunner($dockerRunner, $outputDir, $checkpointDir);

        $result = $chainRunner->run($workspace, '8', '13', resume: true);

        // Hop 8→9 must NOT have been re-executed
        self::assertNotContains('8->9', $executedHops, 'Hop 8→9 should have been skipped on resume');

        // Hops 9→10 through 12→13 must have executed
        self::assertContains('9->10', $executedHops);
        self::assertContains('10->11', $executedHops);
        self::assertContains('11->12', $executedHops);
        self::assertContains('12->13', $executedHops);
    }

    public function testResumeFromHop11To12SkipsFirstThreeHops(): void
    {
        $workspace     = $this->copyFixtureToTemp('fixture-minimal');
        $checkpointDir = $this->makeTempDir('-checkpoints');
        $outputDir     = $this->makeTempDir('-output');

        $checkpoint = $this->makeCheckpointWithCompletedHops(
            chainId: 'e2e-resume-test-2',
            fromVersion: '8',
            toVersion: '13',
            workspacePath: $workspace,
            completedHops: [
                new HopResult('8', '9', 'upgrader/hop-8-to-9', $workspace, new \DateTimeImmutable()),
                new HopResult('9', '10', 'upgrader/hop-9-to-10', $workspace, new \DateTimeImmutable()),
                new HopResult('10', '11', 'upgrader/hop-10-to-11', $workspace, new \DateTimeImmutable()),
            ],
        );
        (new ChainResumeHandler())->writeCheckpoint($checkpoint, $checkpointDir);

        $executedHops = [];
        $dockerRunner = $this->makeTrackingDockerRunner($executedHops);
        $chainRunner  = $this->makeChainRunner($dockerRunner, $outputDir, $checkpointDir);

        $chainRunner->run($workspace, '8', '13', resume: true);

        self::assertNotContains('8->9', $executedHops);
        self::assertNotContains('9->10', $executedHops);
        self::assertNotContains('10->11', $executedHops);
        self::assertContains('11->12', $executedHops);
        self::assertContains('12->13', $executedHops);
    }

    public function testFullyCompletedChainIsIdempotentOnResume(): void
    {
        $workspace     = $this->copyFixtureToTemp('fixture-minimal');
        $checkpointDir = $this->makeTempDir('-checkpoints');
        $outputDir     = $this->makeTempDir('-output');

        // All 5 hops already completed
        $checkpoint = $this->makeCheckpointWithCompletedHops(
            chainId: 'e2e-resume-test-idempotent',
            fromVersion: '8',
            toVersion: '13',
            workspacePath: $workspace,
            completedHops: [
                new HopResult('8', '9', 'upgrader/hop-8-to-9', $workspace, new \DateTimeImmutable()),
                new HopResult('9', '10', 'upgrader/hop-9-to-10', $workspace, new \DateTimeImmutable()),
                new HopResult('10', '11', 'upgrader/hop-10-to-11', $workspace, new \DateTimeImmutable()),
                new HopResult('11', '12', 'upgrader/hop-11-to-12', $workspace, new \DateTimeImmutable()),
                new HopResult('12', '13', 'upgrader/hop-12-to-13', $workspace, new \DateTimeImmutable()),
            ],
        );
        (new ChainResumeHandler())->writeCheckpoint($checkpoint, $checkpointDir);

        $executedHops = [];
        $dockerRunner = $this->makeTrackingDockerRunner($executedHops);
        $chainRunner  = $this->makeChainRunner($dockerRunner, $outputDir, $checkpointDir);

        // Should NOT throw even though no hops run
        $result = $chainRunner->run($workspace, '8', '13', resume: true);

        self::assertEmpty($executedHops, 'No hops should execute when chain is already complete');
        self::assertSame('8', $result->sourceVersion);
        self::assertSame('13', $result->targetVersion);
    }

    public function testResumeWithNoCheckpointRunsFromBeginning(): void
    {
        $workspace     = $this->copyFixtureToTemp('fixture-minimal');
        $checkpointDir = $this->makeTempDir('-empty-checkpoints');
        $outputDir     = $this->makeTempDir('-output');

        // No checkpoint file exists — resume should behave like a fresh run
        $executedHops = [];
        $dockerRunner = $this->makeTrackingDockerRunner($executedHops);
        $chainRunner  = $this->makeChainRunner($dockerRunner, $outputDir, $checkpointDir);

        $chainRunner->run($workspace, '8', '13', resume: true);

        // All 5 hops must have executed
        self::assertCount(5, $executedHops);
        self::assertSame(['8->9', '9->10', '10->11', '11->12', '12->13'], $executedHops);
    }

    public function testCheckpointIsWrittenAfterEachHop(): void
    {
        $workspace     = $this->copyFixtureToTemp('fixture-minimal');
        $checkpointDir = $this->makeTempDir('-checkpoints');
        $outputDir     = $this->makeTempDir('-output');

        $dockerRunner = $this->makeSuccessfulDockerRunner();
        $chainRunner  = $this->makeChainRunner($dockerRunner, $outputDir, $checkpointDir);

        // Run just two hops
        $chainRunner->run($workspace, '8', '10');

        // Checkpoint file must exist
        $checkpointFile = $checkpointDir . \DIRECTORY_SEPARATOR . 'chain-checkpoint.json';
        self::assertFileExists($checkpointFile);

        $data = json_decode((string) file_get_contents($checkpointFile), true);
        self::assertIsArray($data);

        // Both hops must be recorded
        $completedHops = $data['completedHops'] ?? [];
        self::assertCount(2, $completedHops);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function fixtureBoundaryProvider(): iterable
    {
        $fixtures = ['fixture-minimal', 'fixture-api', 'fixture-livewire', 'fixture-modular', 'fixture-monolith'];

        foreach ($fixtures as $fixture) {
            for ($completed = 0; $completed <= 4; $completed++) {
                yield sprintf('%s hop %d', $fixture, $completed) => [$fixture, $completed];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixtureBoundaryProvider')]
    public function testResumeSkipsCompletedHopBoundariesForEachFixture(string $fixtureName, int $completedHopCount): void
    {
        $workspace     = $this->copyFixtureToTemp($fixtureName);
        $checkpointDir = $this->makeTempDir('-checkpoints');
        $outputDir     = $this->makeTempDir('-output');

        $hopVersions = [
            ['8', '9'],
            ['9', '10'],
            ['10', '11'],
            ['11', '12'],
            ['12', '13'],
        ];

        $completedHops = [];
        $currentInput  = $workspace;

        for ($index = 0; $index < $completedHopCount; $index++) {
            [$from, $to] = $hopVersions[$index];
            $outputPath  = $outputDir . '/precompleted-' . $from . '-to-' . $to;
            mkdir($outputPath, 0700, true);
            $completedHops[] = new HopResult(
                $from,
                $to,
                sprintf('upgrader/hop-%s-to-%s', $from, $to),
                $outputPath,
                new \DateTimeImmutable(),
                [],
                $currentInput,
            );
            $currentInput = $outputPath;
        }

        $checkpoint = $this->makeCheckpointWithCompletedHops(
            chainId: 'fixture-resume-' . $fixtureName . '-' . $completedHopCount,
            fromVersion: '8',
            toVersion: '13',
            workspacePath: $currentInput,
            completedHops: $completedHops,
        );
        (new ChainResumeHandler())->writeCheckpoint($checkpoint, $checkpointDir);

        $executedHops = [];
        $dockerRunner = $this->makeTrackingDockerRunner($executedHops);
        $chainRunner  = $this->makeChainRunner($dockerRunner, $outputDir, $checkpointDir);

        $chainRunner->run($workspace, '8', '13', resume: true);

        $expectedExecuted = array_map(
            static fn (array $hop): string => $hop[0] . '->' . $hop[1],
            array_slice($hopVersions, $completedHopCount),
        );

        self::assertSame($expectedExecuted, $executedHops);
    }

    // -------------------------------------------------------------------------
    // Live Docker resume test (skipped if Docker unavailable)
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function liveDockerResumeFixtureProvider(): iterable
    {
        foreach (['fixture-minimal', 'fixture-api', 'fixture-livewire', 'fixture-modular', 'fixture-monolith'] as $fixture) {
            yield $fixture => [$fixture];
        }
    }

    /**
     * @group integration
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('liveDockerResumeFixtureProvider')]
    public function testFullLiveChainSupportsResumeAtEveryBoundaryForFixture(string $fixtureName): void
    {
        $this->requireDocker();

        $workspace     = $this->copyFixtureToTemp($fixtureName);
        $checkpointDir = $this->makeTempDir('-checkpoints');
        $outputDir     = $this->makeTempDir('-output');
        $resumeHandler = new ChainResumeHandler();
        $hopVersions = [
            ['8', '9'],
            ['9', '10'],
            ['10', '11'],
            ['11', '12'],
            ['12', '13'],
        ];

        $chainRunner = $this->buildRealChainRunner($outputDir, $checkpointDir);
        $result      = $chainRunner->run($workspace, '8', '13');

        $this->assertChainCompleted($result);
        self::assertFileExists($checkpointDir . '/chain-checkpoint.json');

        $checkpoint = $resumeHandler->readCheckpoint($checkpointDir);
        self::assertNotNull($checkpoint);
        self::assertCount(5, $checkpoint->completedHops);
        self::assertSame($result->workspacePath, $checkpoint->workspacePath);

        foreach ($checkpoint->completedHops as $completedHop) {
            self::assertDirectoryExists($completedHop->outputPath);
        }

        for ($completedHopCount = 1; $completedHopCount <= 5; $completedHopCount++) {
            $completedHops = array_slice($checkpoint->completedHops, 0, $completedHopCount);
            $boundaryWorkspace = $completedHops[$completedHopCount - 1]->outputPath;
            $boundaryCheckpointDir = $this->makeTempDir(sprintf('-resume-%s-%d', $fixtureName, $completedHopCount));
            $boundaryOutputDir = $this->makeTempDir(sprintf('-resume-output-%s-%d', $fixtureName, $completedHopCount));
            $boundaryCheckpoint = new ChainCheckpoint(
                chainId: $checkpoint->chainId,
                sourceVersion: $checkpoint->sourceVersion,
                targetVersion: $checkpoint->targetVersion,
                completedHops: $completedHops,
                currentHop: null,
                workspacePath: $boundaryWorkspace,
                startedAt: $checkpoint->startedAt,
                updatedAt: new \DateTimeImmutable(),
            );
            $resumeHandler->writeCheckpoint($boundaryCheckpoint, $boundaryCheckpointDir);

            $executedOnResume = [];
            $trackingRunner = $this->makeTrackingDockerRunner($executedOnResume);
            $resumeChainRunner = $this->makeChainRunner($trackingRunner, $boundaryOutputDir, $boundaryCheckpointDir);
            $resumeResult = $resumeChainRunner->run($boundaryWorkspace, '8', '13', resume: true);
            $expectedRemainingHops = array_map(
                static fn (array $hop): string => $hop[0] . '->' . $hop[1],
                array_slice($hopVersions, $completedHopCount),
            );

            self::assertSame(
                $expectedRemainingHops,
                $executedOnResume,
                sprintf('Fixture %s did not resume cleanly from boundary %d.', $fixtureName, $completedHopCount),
            );
            self::assertSame('13', $resumeResult->targetVersion);
            self::assertCount(5, $resumeResult->hops);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a DockerRunner that tracks which hops (by key "from->to") were executed,
     * and emits a successful pipeline_complete for each.
     *
     * @param list<string> $executedHops  Passed by reference; hop keys are appended here.
     */
    private function makeTrackingDockerRunner(array &$executedHops): DockerRunner
    {
        return new DockerRunner(
            processFactory: static function (array $command) use (&$executedHops): \Symfony\Component\Process\Process {
                // Extract hop from→to from env vars embedded in the command
                $from = '';
                $to   = '';

                foreach ($command as $i => $arg) {
                    if ($arg === '--env' && isset($command[$i + 1])) {
                        if (str_starts_with((string) $command[$i + 1], 'UPGRADER_HOP_FROM=')) {
                            $from = substr((string) $command[$i + 1], strlen('UPGRADER_HOP_FROM='));
                        }
                        if (str_starts_with((string) $command[$i + 1], 'UPGRADER_HOP_TO=')) {
                            $to = substr((string) $command[$i + 1], strlen('UPGRADER_HOP_TO='));
                        }
                    }
                }

                if ($from !== '' && $to !== '') {
                    $executedHops[] = "{$from}->{$to}";
                }

                $json    = addslashes(json_encode(['event' => 'pipeline_complete', 'passed' => true, 'ts' => time()]));
                return new \Symfony\Component\Process\Process(['php', '-r', "echo \"{$json}\\n\";"]);
            },
        );
    }

    /**
     * @param list<HopResult> $completedHops
     */
    private function makeCheckpointWithCompletedHops(
        string $chainId,
        string $fromVersion,
        string $toVersion,
        string $workspacePath,
        array $completedHops,
    ): ChainCheckpoint {
        return new ChainCheckpoint(
            chainId:       $chainId,
            sourceVersion: $fromVersion,
            targetVersion: $toVersion,
            completedHops: $completedHops,
            currentHop:    null,
            workspacePath: $workspacePath,
            startedAt:     new \DateTimeImmutable(),
            updatedAt:     new \DateTimeImmutable(),
        );
    }

    private function buildRealChainRunner(string $outputDir, string $checkpointDir): ChainRunner
    {
        return new ChainRunner(
            planner:       new MultiHopPlanner(),
            dockerRunner:  new DockerRunner(timeoutSeconds: 600),
            streamer:      new EventStreamer(),
            resumeHandler: new ChainResumeHandler(),
            outputBaseDir: $outputDir,
            checkpointDir: $checkpointDir,
        );
    }
}

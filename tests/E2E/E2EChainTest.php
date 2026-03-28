<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Orchestrator\ChainRunResult;
use App\Orchestrator\OrchestratorException;
use PHPUnit\Framework\Attributes\Group;

/**
 * E2E orchestrator: validates the full L8→L13 chain using a fake DockerRunner
 * that simulates successful hop containers.
 *
 * Docker-dependent variants are in the individual FixtureXxxTest files.
 *
 * @group e2e
 */
#[Group('e2e')]
final class E2EChainTest extends E2ETestCase
{
    // -------------------------------------------------------------------------
    // Full chain: fake runner (no Docker required)
    // -------------------------------------------------------------------------

    public function testFullChainL8ToL13CompletesWithFakeRunner(): void
    {
        $workspace = $this->copyFixtureToTemp('fixture-minimal');

        $events = [
            ['event' => 'pipeline_start', 'hop' => '8_to_9', 'ts' => microtime(true), 'seq' => 1, 'total_files' => 5, 'php_files' => 4, 'config_files' => 1],
            ['event' => 'stage_complete', 'hop' => '8_to_9', 'ts' => microtime(true), 'seq' => 2, 'stage' => 'rector', 'duration_seconds' => 0.5, 'issues_found' => 0],
            ['event' => 'pipeline_complete', 'passed' => true, 'hop' => '8_to_9', 'ts' => microtime(true), 'seq' => 3],
        ];

        $dockerRunner = $this->makeSuccessfulDockerRunner($events);
        $chainRunner  = $this->makeChainRunner($dockerRunner);

        $result = $chainRunner->run($workspace, '8', '13');

        self::assertInstanceOf(ChainRunResult::class, $result);
        self::assertSame('8', $result->sourceVersion);
        self::assertSame('13', $result->targetVersion);
        self::assertCount(5, $result->hops, 'Expected 5 hops for L8→L13');
    }

    public function testChainResultContainsAllHopKeys(): void
    {
        $workspace = $this->copyFixtureToTemp('fixture-minimal');
        $chainRunner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());

        $result = $chainRunner->run($workspace, '8', '13');

        $hopKeys = array_keys($result->hopEvents);

        self::assertContains('8->9', $hopKeys);
        self::assertContains('9->10', $hopKeys);
        self::assertContains('10->11', $hopKeys);
        self::assertContains('11->12', $hopKeys);
        self::assertContains('12->13', $hopKeys);
    }

    public function testChainAbortedOnHopFailure(): void
    {
        $workspace   = $this->copyFixtureToTemp('fixture-minimal');
        $chainRunner = $this->makeChainRunner($this->makeFailingDockerRunner(1));

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/hop 8->9 failed/i');

        $chainRunner->run($workspace, '8', '9');
    }

    public function testChainAbortedWhenVerificationNotPassed(): void
    {
        $workspace = $this->copyFixtureToTemp('fixture-minimal');

        // Returns pipeline_complete with passed=false
        $dockerRunner = $this->makeSuccessfulDockerRunner([
            ['event' => 'pipeline_complete', 'passed' => false, 'hop' => '8_to_9', 'ts' => microtime(true), 'seq' => 1],
        ]);
        $chainRunner = $this->makeChainRunner($dockerRunner);

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/did not pass verification/i');

        $chainRunner->run($workspace, '8', '9');
    }

    public function testPartialChainL9ToL11(): void
    {
        $workspace   = $this->copyFixtureToTemp('fixture-minimal');
        $chainRunner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());

        $result = $chainRunner->run($workspace, '9', '11');

        self::assertCount(2, $result->hops);
        self::assertSame('9', $result->sourceVersion);
        self::assertSame('11', $result->targetVersion);
        self::assertNotNull($result->reportHtmlPath);
        self::assertNotNull($result->reportJsonPath);
    }

    public function testChainResultWorkspacePathIsLastHopOutput(): void
    {
        $workspace   = $this->copyFixtureToTemp('fixture-minimal');
        $outputDir   = $this->makeTempDir('-output');
        $checkpointDir = $this->makeTempDir('-checkpoints');

        $chainRunner = $this->makeChainRunner(
            $this->makeSuccessfulDockerRunner(),
            $outputDir,
            $checkpointDir,
        );

        $result = $chainRunner->run($workspace, '8', '9');

        // The result workspace path should be under the output base dir
        self::assertStringStartsWith($outputDir, $result->workspacePath);
    }

    // -------------------------------------------------------------------------
    // Full chain with live Docker (skipped if Docker unavailable)
    // -------------------------------------------------------------------------

    /**
     * @group integration
     */
    public function testFullChainL8ToL13WithDockerMinimalFixture(): void
    {
        $this->requireDocker();

        $workspace   = $this->copyFixtureToTemp('fixture-minimal');
        $chainRunner = $this->makeRealChainRunner();

        $result = $chainRunner->run($workspace, '8', '13');

        self::assertSame('8', $result->sourceVersion);
        self::assertSame('13', $result->targetVersion);
        self::assertCount(5, $result->hops);
        $report = $this->assertUnifiedReportGenerated($result);
        self::assertCount(5, $report['hops']);

        // Each hop must have emitted at least a pipeline_complete event
        foreach ($result->hopEvents as $hopKey => $events) {
            $types = array_column($events, 'event');
            self::assertContains(
                'pipeline_complete',
                $types,
                "Hop {$hopKey} did not emit pipeline_complete",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

}

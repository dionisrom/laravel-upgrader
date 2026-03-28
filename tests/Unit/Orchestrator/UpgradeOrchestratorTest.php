<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\CheckpointManagerInterface;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\Hop;
use App\Orchestrator\HopPlanner;
use App\Orchestrator\OrchestratorException;
use App\Orchestrator\UpgradeOrchestrator;
use App\Workspace\WorkspaceManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Tests the UpgradeOrchestrator using real HopPlanner and WorkspaceManager
 * operating on temp directories, and DockerRunner with injected process factories.
 */
final class UpgradeOrchestratorTest extends TestCase
{
    private string $repoDir;
    private string $tempBase;

    protected function setUp(): void
    {
        $this->tempBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upgrader-orch-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempBase, 0700, true);

        // Create a fake repo directory with at least one file so copy works.
        $this->repoDir = $this->tempBase . DIRECTORY_SEPARATOR . 'repo';
        mkdir($this->repoDir, 0700, true);
        file_put_contents($this->repoDir . DIRECTORY_SEPARATOR . 'composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempBase);
    }

    public function testSuccessfulSingleHopRunCompletes(): void
    {
        $processFactory = static fn(array $cmd): Process => new Process(
            ['php', '-r', 'echo json_encode(["event" => "pipeline_complete", "passed" => true]) . PHP_EOL;'],
        );

        $orchestrator = $this->buildOrchestrator($processFactory);
        $result = $orchestrator->run($this->repoDir, '8', '9');

        self::assertTrue($result->success);
        self::assertNotEmpty($result->runId);
        self::assertCount(1, $result->hops);
    }

    public function testVerificationFailureHalts(): void
    {
        $processFactory = static fn(array $cmd): Process => new Process(
            ['php', '-r', 'echo json_encode(["event" => "pipeline_complete", "passed" => false]) . PHP_EOL;'],
        );

        $orchestrator = $this->buildOrchestrator($processFactory);

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/Verification failed/');

        $orchestrator->run($this->repoDir, '8', '9');
    }

    public function testDockerFailureThrowsOrchestratorException(): void
    {
        $processFactory = static fn(array $cmd): Process => new Process(
            ['php', '-r', 'fwrite(STDERR, "fatal error\n"); exit(1);'],
        );

        $orchestrator = $this->buildOrchestrator($processFactory);

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/exit code 1/');

        $orchestrator->run($this->repoDir, '8', '9');
    }

    public function testInvalidHopPlanThrowsOrchestratorException(): void
    {
        $processFactory = static fn(array $cmd): Process => new Process(['php', '-r', 'exit(0);']);
        $orchestrator = $this->buildOrchestrator($processFactory);

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/Invalid hop plan/');

        $orchestrator->run($this->repoDir, '9', '10');
    }

    public function testCheckpointSkipsAlreadyCompletedHop(): void
    {
        $processRan = false;

        $processFactory = static function (array $cmd) use (&$processRan): Process {
            $processRan = true;
            return new Process(['php', '-r', 'exit(0);']);
        };

        $checkpoints = new class implements CheckpointManagerInterface {
            public function isCompleted(Hop $hop): bool
            {
                return true;
            }

            public function markCompleted(Hop $hop): void {}
        };

        $orchestrator = $this->buildOrchestrator($processFactory, $checkpoints);
        $result = $orchestrator->run($this->repoDir, '8', '9');

        self::assertTrue($result->success);
        self::assertFalse($processRan, 'Process must not run for already-completed hops');
    }

    public function testEventsAreCollectedInResult(): void
    {
        $processFactory = static fn(array $cmd): Process => new Process(
            ['php', '-r', 'echo json_encode(["event" => "stage_start", "stage" => "rector"]) . PHP_EOL . json_encode(["event" => "pipeline_complete", "passed" => true]) . PHP_EOL;'],
        );

        $orchestrator = $this->buildOrchestrator($processFactory);
        $result = $orchestrator->run($this->repoDir, '8', '9');

        $eventTypes = array_column($result->events, 'event');
        self::assertContains('stage_start', $eventTypes);
        self::assertContains('pipeline_complete', $eventTypes);
    }

    public function testNoVerificationEventMeansFailure(): void
    {
        $processFactory = static fn(array $cmd): Process => new Process(
            ['php', '-r', 'echo json_encode(["event" => "stage_start", "stage" => "rector"]) . PHP_EOL;'],
        );

        $orchestrator = $this->buildOrchestrator($processFactory);

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/Verification failed/');

        $orchestrator->run($this->repoDir, '8', '9');
    }

    private function buildOrchestrator(
        \Closure $processFactory,
        ?CheckpointManagerInterface $checkpoints = null,
    ): UpgradeOrchestrator {
        return new UpgradeOrchestrator(
            hopPlanner: new HopPlanner(['8:9' => 'upgrader/hop-8-to-9']),
            dockerRunner: new DockerRunner(processFactory: $processFactory),
            workspaceManager: new WorkspaceManager(),
            streamer: new EventStreamer(),
            checkpoints: $checkpoints,
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }
}

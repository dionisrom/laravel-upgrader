<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\ChainResumeHandler;
use App\Orchestrator\ChainRunner;
use App\Orchestrator\ChainRunResult;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\MultiHopPlanner;
use App\Orchestrator\OrchestratorException;
use App\State\ChainCheckpoint;
use App\State\HopResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ChainRunnerTest extends TestCase
{
    private string $tmpDir;
    private string $outputDir;
    private string $checkpointDir;

    protected function setUp(): void
    {
        $base                = sys_get_temp_dir() . '/chain-runner-test-' . uniqid('', true);
        $this->tmpDir        = $base . '/workspace';
        $this->outputDir     = $base . '/output';
        $this->checkpointDir = $base . '/checkpoints';

        mkdir($this->tmpDir,        0700, true);
        mkdir($this->outputDir,     0700, true);
        mkdir($this->checkpointDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir(sys_get_temp_dir() . '/chain-runner-test-');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a DockerRunner whose fake process emits $events as JSON-ND lines
     * on stdout and then exits 0.
     *
     * @param list<array<string, mixed>> $events
     */
    private function makeSuccessfulDockerRunner(array $events = []): DockerRunner
    {
        if (empty($events)) {
            $events = [['event' => 'pipeline_complete', 'passed' => true, 'ts' => time()]];
        }

        $lines = implode('', array_map(
            static fn (array $e): string => json_encode($e) . "\n",
            $events,
        ));

        $processFactory = static function (array $command) use ($lines): Process {
            $escaped = addslashes($lines);

            return new Process(['php', '-r', "echo \"{$escaped}\";"]);
        };

        return new DockerRunner(processFactory: $processFactory);
    }

    /**
     * Builds a DockerRunner whose fake process exits with $exitCode.
     */
    private function makeFailingDockerRunner(int $exitCode = 1): DockerRunner
    {
        $processFactory = static function (array $command) use ($exitCode): Process {
            return new Process(['php', '-r', "exit({$exitCode});"]);
        };

        return new DockerRunner(processFactory: $processFactory);
    }

    private function makeChainRunner(DockerRunner $dockerRunner, ?MultiHopPlanner $planner = null): ChainRunner
    {
        return new ChainRunner(
            planner:       $planner ?? new MultiHopPlanner(),
            dockerRunner:  $dockerRunner,
            streamer:      new EventStreamer(),
            resumeHandler: new ChainResumeHandler(),
            outputBaseDir: $this->outputDir,
            checkpointDir: $this->checkpointDir,
        );
    }

    private function removeDir(string $prefix): void
    {
        $dirs = glob(sys_get_temp_dir() . '/chain-runner-test-*') ?: [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($it as $file) {
                if ($file->isDir()) {
                    @rmdir((string) $file->getRealPath());
                } else {
                    @unlink((string) $file->getRealPath());
                }
            }

            @rmdir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // Return type
    // -------------------------------------------------------------------------

    public function testRunReturnsChainRunResult(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $result = $runner->run($this->tmpDir, '8', '9');

        self::assertInstanceOf(ChainRunResult::class, $result);
    }

    public function testRunResultContainsCorrectVersions(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $result = $runner->run($this->tmpDir, '8', '9');

        self::assertSame('8', $result->sourceVersion);
        self::assertSame('9', $result->targetVersion);
    }

    public function testRunResultContainsNonEmptyChainId(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $result = $runner->run($this->tmpDir, '8', '9');

        self::assertNotEmpty($result->chainId);
    }

    // -------------------------------------------------------------------------
    // Sequential execution
    // -------------------------------------------------------------------------

    public function testRunSingleHopProducesOneHopInResult(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $result = $runner->run($this->tmpDir, '8', '9');

        self::assertCount(1, $result->hops);
        self::assertSame('8', $result->hops[0]->fromVersion);
        self::assertSame('9', $result->hops[0]->toVersion);
    }

    public function testRunMultiHopProducesCorrectHopList(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $result = $runner->run($this->tmpDir, '8', '11');

        self::assertCount(3, $result->hops);
        self::assertSame('8',  $result->hops[0]->fromVersion);
        self::assertSame('9',  $result->hops[0]->toVersion);
        self::assertSame('9',  $result->hops[1]->fromVersion);
        self::assertSame('10', $result->hops[1]->toVersion);
        self::assertSame('10', $result->hops[2]->fromVersion);
        self::assertSame('11', $result->hops[2]->toVersion);
    }

    public function testRunPopulatesHopEventsForEachHop(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $result = $runner->run($this->tmpDir, '8', '9');

        self::assertArrayHasKey('8->9', $result->hopEvents);
    }

    public function testRunWritesUnifiedReportArtifacts(): void
    {
        file_put_contents($this->tmpDir . '/app.php', "<?php\nreturn 1;\n");

        $processFactory = static function (array $command): Process {
            // The output directory is pre-staged by ChainRunner and mounted as
            // /repo:rw. Extract it so the fake "container" can mutate a file to
            // produce a diff for the report writer.
            $repoDir = '';

            foreach ($command as $index => $part) {
                if ($part === '-v' && isset($command[$index + 1]) && str_contains((string) $command[$index + 1], ':/repo:rw')) {
                    $repoDir = substr((string) $command[$index + 1], 0, strpos((string) $command[$index + 1], ':/repo:rw'));
                }
            }

            if ($repoDir !== '') {
                // Simulate in-place transformation: overwrite the pre-staged file.
                file_put_contents($repoDir . '/app.php', "<?php\nreturn 2;\n");
            }

            return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>true,"ts"=>1]) . "\n";']);
        };

        $runner = $this->makeChainRunner(new DockerRunner(processFactory: $processFactory));
        $result = $runner->run($this->tmpDir, '8', '9');

        self::assertNotNull($result->reportHtmlPath);
        self::assertNotNull($result->reportJsonPath);
        self::assertFileExists($result->reportHtmlPath);
        self::assertFileExists($result->reportJsonPath);
    }

    public function testRunStoresHopInputPathInCheckpoint(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $runner->run($this->tmpDir, '8', '9');

        $handler    = new ChainResumeHandler();
        $checkpoint = $handler->readCheckpoint($this->checkpointDir);

        self::assertNotNull($checkpoint);
        self::assertSame($this->tmpDir, $checkpoint->completedHops[0]->inputPath);
    }

    public function testTypeEventsAreNormalizedIntoHostEventShape(): void
    {
        $processFactory = static function (array $command): Process {
            $lines = [
                json_encode([
                    'type' => 'manual_review_required',
                    'data' => [
                        'file' => 'app/Http/Kernel.php',
                        'detail' => 'Middleware order changed',
                        'pattern' => 'MIDDLEWARE-ORDER',
                    ],
                ]),
                json_encode(['event' => 'pipeline_complete', 'passed' => true, 'ts' => 1]),
            ];

            return new Process(['php', '-r', 'echo ' . var_export(implode("\n", $lines) . "\n", true) . ';']);
        };

        $runner = $this->makeChainRunner(new DockerRunner(processFactory: $processFactory));
        $result = $runner->run($this->tmpDir, '8', '9');

        self::assertSame('manual_review_required', $result->hopEvents['8->9'][0]['event']);
        self::assertSame(['app/Http/Kernel.php'], $result->hopEvents['8->9'][0]['files']);
        self::assertSame('MIDDLEWARE-ORDER', $result->hopEvents['8->9'][0]['id']);
    }

    // -------------------------------------------------------------------------
    // Verification gate
    // -------------------------------------------------------------------------

    public function testRunThrowsWhenContainerDoesNotPassVerification(): void
    {
        $processFactory = static function (array $command): Process {
            // Emits pipeline_complete with passed=false — verification gate must block.
            return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>false,"ts"=>1]) . "\n"; exit(0);']);
        };

        $dockerRunner = new DockerRunner(processFactory: $processFactory);
        $runner       = $this->makeChainRunner($dockerRunner);

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/verification/i');

        $runner->run($this->tmpDir, '8', '9');
    }

    public function testRunThrowsWhenContainerEmitsNoPipelineComplete(): void
    {
        $processFactory = static function (array $command): Process {
            // No pipeline_complete event — verification gate must block.
            return new Process(['php', '-r', 'echo json_encode(["event"=>"stage_start","ts"=>1]) . "\n"; exit(0);']);
        };

        $dockerRunner = new DockerRunner(processFactory: $processFactory);
        $runner       = $this->makeChainRunner($dockerRunner);

        $this->expectException(OrchestratorException::class);

        $runner->run($this->tmpDir, '8', '9');
    }

    // -------------------------------------------------------------------------
    // Abort on hop failure
    // -------------------------------------------------------------------------

    public function testRunThrowsOrchestratorExceptionOnHopFailure(): void
    {
        $runner = $this->makeChainRunner($this->makeFailingDockerRunner(1));

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/Chain aborted/');

        $runner->run($this->tmpDir, '8', '9');
    }

    public function testRunAbortsAtFirstFailingHop(): void
    {
        // First hop succeeds, second hop fails.
        $callCount      = 0;
        $processFactory = static function (array $command) use (&$callCount): Process {
            $callCount++;

            if ($callCount === 1) {
                return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>true,"ts"=>1]) . "\n"; exit(0);']);
            }

            return new Process(['php', '-r', 'exit(1);']);
        };

        $dockerRunner = new DockerRunner(processFactory: $processFactory);
        $runner       = $this->makeChainRunner($dockerRunner);

        try {
            $runner->run($this->tmpDir, '8', '10');
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException $e) {
            // Only 2 calls happened (run 1 succeeded, run 2 failed, run 3 never reached).
            self::assertSame(2, $callCount);
        }
    }

    // -------------------------------------------------------------------------
    // Checkpoint persistence
    // -------------------------------------------------------------------------

    public function testCheckpointIsWrittenAfterSuccessfulHop(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $runner->run($this->tmpDir, '8', '9');

        $checkpointFile = $this->checkpointDir . '/chain-checkpoint.json';
        self::assertFileExists($checkpointFile);
    }

    public function testCheckpointRecordsCompletedHop(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());
        $runner->run($this->tmpDir, '8', '9');

        $handler    = new ChainResumeHandler();
        $checkpoint = $handler->readCheckpoint($this->checkpointDir);

        self::assertNotNull($checkpoint);
        self::assertCount(1, $checkpoint->completedHops);
        self::assertSame('8', $checkpoint->completedHops[0]->fromVersion);
        self::assertSame('9', $checkpoint->completedHops[0]->toVersion);
    }

    // -------------------------------------------------------------------------
    // Resume: skip completed hops
    // -------------------------------------------------------------------------

    public function testResumeSkipsAlreadyCompletedHops(): void
    {
        // Pre-populate checkpoint with hops 8->9 and 9->10 already done.
        $completed = [
            new HopResult('8', '9',  'upgrader/hop-8-to-9',  '/fake/out1', new \DateTimeImmutable(), [], $this->tmpDir),
            new HopResult('9', '10', 'upgrader/hop-9-to-10', '/fake/out2', new \DateTimeImmutable(), [], '/fake/out1'),
        ];

        $checkpoint = new ChainCheckpoint(
            chainId:       'existing-chain',
            sourceVersion: '8',
            targetVersion: '11',
            completedHops: $completed,
            currentHop:    null,
            workspacePath: $this->tmpDir,
            startedAt:     new \DateTimeImmutable(),
            updatedAt:     null,
        );

        $handler = new ChainResumeHandler();
        $handler->writeCheckpoint($checkpoint, $this->checkpointDir);

        // Only hop 10->11 should be executed.
        $callCount      = 0;
        $processFactory = static function (array $command) use (&$callCount): Process {
            $callCount++;

            return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>true,"ts"=>1]) . "\n"; exit(0);']);
        };

        $dockerRunner = new DockerRunner(processFactory: $processFactory);
        $runner       = $this->makeChainRunner($dockerRunner);

        $result = $runner->run($this->tmpDir, '8', '11', resume: true);

        self::assertSame(1, $callCount, 'Only the remaining hop (10->11) should have been run.');
        self::assertSame('existing-chain', $result->chainId);
    }

    // -------------------------------------------------------------------------
    // Invalid plan throws OrchestratorException
    // -------------------------------------------------------------------------

    public function testRunThrowsOrchestratorExceptionForInvalidVersionRange(): void
    {
        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());

        $this->expectException(OrchestratorException::class);

        $runner->run($this->tmpDir, '9', '8'); // downgrade
    }

    // -------------------------------------------------------------------------
    // Workspace handoff contract (regression coverage for the pre-copy fix)
    // -------------------------------------------------------------------------

    /**
     * After a successful hop the output directory must contain the input workspace
     * contents (transformed in-place by the container).  Before the fix this
     * directory was empty because the container mutated /repo (the original
     * workspace) while /output (the output dir) was never written to.
     */
    public function testCompletedHopOutputDirectoryContainsInputWorkspaceContent(): void
    {
        // Put a sentinel file in the input workspace.
        file_put_contents($this->tmpDir . '/sentinel.txt', 'original');

        // The fake "container" receives the pre-staged output dir as /repo:rw
        // and modifies the sentinel file in-place — exactly what Rector does.
        $processFactory = static function (array $command): Process {
            $repoDir = '';

            foreach ($command as $index => $part) {
                if ($part === '-v' && isset($command[$index + 1]) && str_contains((string) $command[$index + 1], ':/repo:rw')) {
                    $repoDir = substr((string) $command[$index + 1], 0, strpos((string) $command[$index + 1], ':/repo:rw'));
                }
            }

            if ($repoDir !== '') {
                file_put_contents($repoDir . '/sentinel.txt', 'transformed');
            }

            return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>true,"ts"=>1]) . "\n";']);
        };

        $runner = $this->makeChainRunner(new DockerRunner(processFactory: $processFactory));
        $result = $runner->run($this->tmpDir, '8', '9');

        // ChainRunResult::workspacePath is the final output workspace (= single hop's outputDir).
        $hopOutputPath = $result->workspacePath;
        self::assertDirectoryExists($hopOutputPath);
        self::assertFileExists($hopOutputPath . '/sentinel.txt');
        self::assertStringEqualsFile($hopOutputPath . '/sentinel.txt', 'transformed');
    }

    /**
     * In a multi-hop chain, the second hop must run against the populated output
     * directory of the first hop — not an empty directory.  Before the fix the
     * second hop received an empty workspace because ChainRunner set
     * $currentWorkspace = $outputDir (which was never populated).
     */
    public function testNextHopReceivesPopulatedWorkspaceFromPreviousHopOutput(): void
    {
        file_put_contents($this->tmpDir . '/file.php', '<?php // L8');

        /** @var list<string> $repoDirsSeenByContainer */
        $repoDirsSeenByContainer = [];
        /** @var list<string> $fileContentsBeforeTransform */
        $fileContentsBeforeTransform = [];
        $callIndex                   = 0;

        $processFactory = static function (array $command) use (&$repoDirsSeenByContainer, &$fileContentsBeforeTransform, &$callIndex): Process {
            $repoDir = '';

            foreach ($command as $index => $part) {
                if ($part === '-v' && isset($command[$index + 1]) && str_contains((string) $command[$index + 1], ':/repo:rw')) {
                    $repoDir = substr((string) $command[$index + 1], 0, strpos((string) $command[$index + 1], ':/repo:rw'));
                }
            }

            $repoDirsSeenByContainer[] = $repoDir;

            // Capture what came in (pre-transform) so we can assert the handoff was correct.
            if ($repoDir !== '' && file_exists($repoDir . '/file.php')) {
                $fileContentsBeforeTransform[] = (string) file_get_contents($repoDir . '/file.php');
            } else {
                $fileContentsBeforeTransform[] = '';
            }

            // Each hop transforms the file in-place (simulating Rector).
            if ($repoDir !== '') {
                file_put_contents($repoDir . '/file.php', '<?php // L' . (9 + $callIndex));
            }

            $callIndex++;

            return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>true,"ts"=>1]) . "\n";']);
        };

        $runner = $this->makeChainRunner(new DockerRunner(processFactory: $processFactory));
        $runner->run($this->tmpDir, '8', '10');

        self::assertCount(2, $repoDirsSeenByContainer, 'Two hops should have been executed.');

        // The directories the two hops saw must be different (each hop gets its own output dir).
        self::assertNotSame($repoDirsSeenByContainer[0], $repoDirsSeenByContainer[1]);

        // Hop 1 received the original "L8" workspace.
        self::assertStringContainsString('L8', $fileContentsBeforeTransform[0]);

        // Hop 2 must receive the output of hop 1 ("L9"), not an empty workspace.
        self::assertNotSame('', $fileContentsBeforeTransform[1], 'Second hop workspace must not be empty.');
        self::assertStringContainsString('L9', $fileContentsBeforeTransform[1]);
    }

    // -------------------------------------------------------------------------
    // Resume: version mismatch guard
    // -------------------------------------------------------------------------

    public function testResumeThrowsWhenCheckpointVersionsMismatch(): void
    {
        // Pre-populate a checkpoint for 8→11.
        $checkpoint = new ChainCheckpoint(
            chainId:       'old-chain',
            sourceVersion: '8',
            targetVersion: '11',
            completedHops: [
                new HopResult('8', '9', 'upgrader/hop-8-to-9', '/fake/out1', new \DateTimeImmutable(), [], $this->tmpDir),
            ],
            currentHop:    null,
            workspacePath: $this->tmpDir,
            startedAt:     new \DateTimeImmutable(),
            updatedAt:     null,
        );

        $handler = new ChainResumeHandler();
        $handler->writeCheckpoint($checkpoint, $this->checkpointDir);

        $runner = $this->makeChainRunner($this->makeSuccessfulDockerRunner());

        $this->expectException(OrchestratorException::class);
        $this->expectExceptionMessageMatches('/mismatch.*8.*11.*8.*13/i');

        // Request 8→13 but checkpoint is for 8→11.
        $runner->run($this->tmpDir, '8', '13', resume: true);
    }

    // -------------------------------------------------------------------------
    // Partial report on chain abort
    // -------------------------------------------------------------------------

    public function testPartialReportWrittenWhenSecondHopFails(): void
    {
        file_put_contents($this->tmpDir . '/app.php', "<?php\nreturn 1;\n");

        $callCount      = 0;
        $processFactory = static function (array $command) use (&$callCount): Process {
            $callCount++;

            if ($callCount === 1) {
                // First hop succeeds: mutate the staged workspace and emit pipeline_complete.
                $repoDir = '';
                foreach ($command as $index => $part) {
                    if ($part === '-v' && isset($command[$index + 1]) && str_contains((string) $command[$index + 1], ':/repo:rw')) {
                        $repoDir = substr((string) $command[$index + 1], 0, strpos((string) $command[$index + 1], ':/repo:rw'));
                    }
                }
                if ($repoDir !== '') {
                    file_put_contents($repoDir . '/app.php', "<?php\nreturn 2;\n");
                }

                return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>true,"ts"=>1]) . "\n";']);
            }

            // Second hop fails.
            return new Process(['php', '-r', 'exit(1);']);
        };

        $dockerRunner = new DockerRunner(processFactory: $processFactory);
        $runner       = $this->makeChainRunner($dockerRunner);

        try {
            $runner->run($this->tmpDir, '8', '10');
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException) {
            // Partial report should exist for the completed first hop.
            $chainCheckpoint = (new ChainResumeHandler())->readCheckpoint($this->checkpointDir);
            self::assertNotNull($chainCheckpoint);

            $chainOutputDir = $this->outputDir . '/' . $chainCheckpoint->chainId;
            self::assertFileExists($chainOutputDir . '/chain-report.json', 'Partial JSON report must exist after abort.');
            self::assertFileExists($chainOutputDir . '/chain-report.html', 'Partial HTML report must exist after abort.');
        }
    }

    // -------------------------------------------------------------------------
    // Incremental report-context.json (TRD-P2MULTI-003)
    // -------------------------------------------------------------------------

    public function testReportContextJsonWrittenIncrementallyAfterEachHop(): void
    {
        file_put_contents($this->tmpDir . '/app.php', "<?php\nreturn 1;\n");

        $callCount      = 0;
        $processFactory = static function (array $command) use (&$callCount): Process {
            $callCount++;

            if ($callCount === 1) {
                $repoDir = '';
                foreach ($command as $index => $part) {
                    if ($part === '-v' && isset($command[$index + 1]) && str_contains((string) $command[$index + 1], ':/repo:rw')) {
                        $repoDir = substr((string) $command[$index + 1], 0, strpos((string) $command[$index + 1], ':/repo:rw'));
                    }
                }
                if ($repoDir !== '') {
                    file_put_contents($repoDir . '/app.php', "<?php\nreturn 2;\n");
                }

                return new Process(['php', '-r', 'echo json_encode(["event"=>"pipeline_complete","passed"=>true,"ts"=>1]) . "\n";']);
            }

            // Second hop fails — but report-context.json should already exist.
            return new Process(['php', '-r', 'exit(1);']);
        };

        $dockerRunner = new DockerRunner(processFactory: $processFactory);
        $runner       = $this->makeChainRunner($dockerRunner);

        try {
            $runner->run($this->tmpDir, '8', '10');
            self::fail('Expected OrchestratorException');
        } catch (OrchestratorException) {
            $chainCheckpoint = (new ChainResumeHandler())->readCheckpoint($this->checkpointDir);
            self::assertNotNull($chainCheckpoint);

            $reportContextPath = $this->outputDir . '/' . $chainCheckpoint->chainId . '/report-context.json';
            self::assertFileExists($reportContextPath, 'report-context.json must exist after first hop completes.');

            $reportContext = json_decode((string) file_get_contents($reportContextPath), true);
            self::assertIsArray($reportContext);
            self::assertCount(1, $reportContext['completedHops']);
            self::assertSame('8', $reportContext['completedHops'][0]['fromVersion']);
            self::assertSame('9', $reportContext['completedHops'][0]['toVersion']);
        }
    }
}
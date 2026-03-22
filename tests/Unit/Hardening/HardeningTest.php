<?php

declare(strict_types=1);

namespace Tests\Unit\Hardening;

use App\Orchestrator\DockerRunner;
use App\Orchestrator\Events\EventParser;
use App\Orchestrator\Hop;
use App\Orchestrator\HopPlanner;
use App\Orchestrator\HopSequence;
use App\Orchestrator\State\TransformCheckpoint;
use AppContainer\EventEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Hardening test: validates architectural constraints are encoded in source.
 * All tests are static — no Docker required.
 */
final class HardeningTest extends TestCase
{
    // -----------------------------------------------------------------------
    // DockerRunner: network isolation
    // -----------------------------------------------------------------------

    public function testDockerRunnerEnforcesNetworkNone(): void
    {
        $hop = $this->makeHop();
        $runner = new DockerRunner(dockerBin: 'docker');

        $command = $runner->buildCommand($hop, '/workspace', '/output');

        self::assertContains(
            '--network=none',
            $command,
            'DockerRunner must always inject --network=none into every docker run command.',
        );
    }

    public function testDockerRunnerContainerIsLastArgument(): void
    {
        $hop = $this->makeHop();
        $runner = new DockerRunner(dockerBin: 'docker');

        $command = $runner->buildCommand($hop, '/workspace', '/output');

        self::assertSame(
            $hop->dockerImage,
            $command[count($command) - 1],
            'Docker image must be the last argument so no flags can be injected after it.',
        );
    }

    public function testDockerRunnerInjectsEnvHopFrom(): void
    {
        $hop = $this->makeHop();
        $runner = new DockerRunner(dockerBin: 'docker');

        $command = $runner->buildCommand($hop, '/workspace', '/output');

        self::assertContains('UPGRADER_HOP_FROM=8', $command);
        self::assertContains('UPGRADER_HOP_TO=9', $command);
        self::assertContains('UPGRADER_WORKSPACE=/repo', $command);
    }

    public function testDockerRunnerDoesNotInheritHostEnvironment(): void
    {
        // DockerRunner::run() calls $process->setEnv([]) before starting.
        // We verify this by inspecting the source; a simpler proxy: ensure
        // the buildCommand contains no --env with host-side values.
        $hop = $this->makeHop();
        $runner = new DockerRunner(dockerBin: 'docker');

        $command = $runner->buildCommand($hop, '/workspace', '/output');

        // --env flags present must only be the three we control
        $envValues = [];
        for ($i = 0; $i < count($command) - 1; $i++) {
            if ($command[$i] === '--env') {
                $envValues[] = $command[$i + 1];
            }
        }

        self::assertCount(3, $envValues, 'Expected exactly 3 --env pairs in docker run command.');

        $knownKeys = ['UPGRADER_HOP_FROM', 'UPGRADER_HOP_TO', 'UPGRADER_WORKSPACE'];
        foreach ($envValues as $pair) {
            [$key] = explode('=', $pair, 2);
            self::assertContains($key, $knownKeys, "Unexpected --env key injected: {$key}");
        }
    }

    // -----------------------------------------------------------------------
    // AuditLogWriter: sensitive key sanitisation
    // -----------------------------------------------------------------------

    public function testAuditLogWriterSanitizesSensitiveKeys(): void
    {
        $logFile = sys_get_temp_dir() . '/hardening-audit-' . uniqid() . '.json';

        $writer = new \App\Orchestrator\AuditLogWriter(
            logPath: $logFile,
            runId: 'test-run-001',
            repoSha: 'abc123',
        );

        $writer->consume([
            'event'        => 'test_event',
            'token'        => 'ghp_supersecret',
            'password'     => 'hunter2',
            'secret'       => 'my-signing-secret',
            'key'          => 'encryption-key-value',
            'source_code'  => '<?php echo "private";',
            'file_contents'=> '<?php $x = 1;',
            'content'      => 'raw file content',
            'safe_field'   => 'this should survive',
        ]);

        self::assertFileExists($logFile);

        $line = trim((string) file_get_contents($logFile));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('token', $decoded, 'token must be stripped from audit log');
        self::assertArrayNotHasKey('password', $decoded, 'password must be stripped from audit log');
        self::assertArrayNotHasKey('secret', $decoded, 'secret must be stripped from audit log');
        self::assertArrayNotHasKey('key', $decoded, 'key must be stripped from audit log');
        self::assertArrayNotHasKey('source_code', $decoded);
        self::assertArrayNotHasKey('file_contents', $decoded);
        self::assertArrayNotHasKey('content', $decoded);

        // Non-sensitive field must be preserved
        self::assertArrayHasKey('safe_field', $decoded);
        self::assertSame('this should survive', $decoded['safe_field']);

        // Metadata must be injected
        self::assertSame('test-run-001', $decoded['run_id']);
        self::assertSame('abc123', $decoded['repo_sha']);

        @unlink($logFile);
    }

    // -----------------------------------------------------------------------
    // EventParser: never throws
    // -----------------------------------------------------------------------

    public function testEventParserNeverThrowsOnMalformedInput(): void
    {
        $parser = new EventParser();

        $malformedInputs = [
            '',
            '   ',
            'not json at all',
            '{broken json',
            '{"missing_event_key": true}',
            '[]',
            'null',
            str_repeat('x', 10000),
        ];

        foreach ($malformedInputs as $input) {
            try {
                $event = $parser->parseLine($input);
                // Should return a WarningEvent, not throw
                self::assertInstanceOf(
                    \App\Orchestrator\Events\BaseEvent::class,
                    $event,
                    "EventParser must return a BaseEvent for input: " . substr($input, 0, 50)
                );
            } catch (\Throwable $e) {
                self::fail(sprintf(
                    'EventParser::parseLine() threw %s for input "%s": %s',
                    $e::class,
                    substr($input, 0, 50),
                    $e->getMessage(),
                ));
            }
        }
    }

    // -----------------------------------------------------------------------
    // TransformCheckpoint: atomic write pattern
    // -----------------------------------------------------------------------

    public function testCheckpointWriteIsAtomic(): void
    {
        $workspacePath = sys_get_temp_dir() . '/hardening-ws-' . uniqid();
        mkdir($workspacePath, 0700, true);

        $checkpoint = new TransformCheckpoint(
            workspacePath: $workspacePath,
            hostVersion: '1.0.0',
        );

        $checkpoint->write(
            hop: '8:9',
            completedRules: ['RuleA', 'RuleB'],
            pendingRules: ['RuleC'],
            filesHashed: ['app/Models/User.php' => 'sha256:abc123def456'],
        );

        $finalPath = $workspacePath . '/.upgrader-state/checkpoint.json';
        $tmpPath   = $finalPath . '.tmp';

        // Final checkpoint must exist
        self::assertFileExists($finalPath, 'checkpoint.json must exist after write()');

        // Temp file must NOT remain (rename clears it)
        self::assertFileDoesNotExist($tmpPath, 'checkpoint.json.tmp must not exist after successful write (atomic rename)');

        // Content must be valid JSON
        $content = file_get_contents($finalPath);
        self::assertNotFalse($content);

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('8:9', $data['hop']);
        self::assertSame(['RuleA', 'RuleB'], $data['completed_rules']);
        self::assertTrue((bool) $data['can_resume']);

        // Cleanup
        array_map('unlink', glob($workspacePath . '/.upgrader-state/*') ?: []);
        @rmdir($workspacePath . '/.upgrader-state');
        @rmdir($workspacePath);
    }

    public function testCheckpointRejectsAbsolutePaths(): void
    {
        $workspacePath = sys_get_temp_dir() . '/hardening-ws-abs-' . uniqid();
        mkdir($workspacePath, 0700, true);

        $checkpoint = new TransformCheckpoint(workspacePath: $workspacePath);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute path/i');

        $checkpoint->write(
            hop: '8:9',
            completedRules: [],
            pendingRules: [],
            filesHashed: ['/etc/passwd' => 'sha256:abc'],
        );

        @rmdir($workspacePath);
    }

    // -----------------------------------------------------------------------
    // HopSequence: not empty
    // -----------------------------------------------------------------------

    public function testHopSequenceIsNotEmpty(): void
    {
        $planner = new HopPlanner();
        $sequence = $planner->plan('8', '9');

        self::assertInstanceOf(HopSequence::class, $sequence);
        self::assertNotEmpty($sequence->hops, 'HopSequence for 8→9 must contain at least one Hop.');
        self::assertCount(1, $sequence->hops);
    }

    public function testHopSequenceHopHasCorrectVersions(): void
    {
        $planner = new HopPlanner();
        $sequence = $planner->plan('8', '9');

        $hop = $sequence->hops[0];
        self::assertSame('8', $hop->fromVersion);
        self::assertSame('9', $hop->toVersion);
        self::assertSame('upgrader/hop-8-to-9', $hop->dockerImage);
    }

    // -----------------------------------------------------------------------
    // VersionCommand: output format
    // -----------------------------------------------------------------------

    public function testVersionCommandOutputContainsVersion(): void
    {
        // Invoke VersionCommand directly (not via the CLI binary, which requires all
        // commands to be registered — DashboardCommand uses #[AsCommand] in Symfony 7).
        $command = new \App\Commands\VersionCommand();

        $input  = new \Symfony\Component\Console\Input\StringInput('');
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $command->run($input, $output);

        $text = $output->fetch();

        self::assertStringContainsString('Laravel Enterprise Upgrader', $text);
        self::assertMatchesRegularExpression('/v\d+\.\d+\.\d+/', $text, 'Version output must contain a semver version.');
        self::assertStringContainsString('PHP:', $text);
    }

    // -----------------------------------------------------------------------
    // EventEmitter: writes to stdout (captured via resource injection)
    // -----------------------------------------------------------------------

    public function testContainerEventEmitterWritesToProvidedResource(): void
    {
        $tmpFile = sys_get_temp_dir() . '/emitter-test-' . uniqid() . '.json';
        $fh = fopen($tmpFile, 'w');
        self::assertNotFalse($fh);

        $emitter = new EventEmitter(hop: '8:9', stdout: $fh);
        $emitter->emit('pipeline_start', ['files_total' => 42]);
        fclose($fh);

        $content = file_get_contents($tmpFile);
        self::assertNotFalse($content);

        /** @var array<string, mixed> $data */
        $data = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('pipeline_start', $data['event']);
        self::assertSame('8:9', $data['hop']);
        self::assertSame(42, $data['files_total']);
        self::assertSame(1, $data['seq'], 'seq must start at 1');
        self::assertArrayHasKey('ts', $data);

        @unlink($tmpFile);
    }

    public function testContainerEventEmitterIncrementsSeq(): void
    {
        $tmpFile = sys_get_temp_dir() . '/emitter-seq-' . uniqid() . '.json';
        $fh = fopen($tmpFile, 'w');
        self::assertNotFalse($fh);

        $emitter = new EventEmitter(hop: '8:9', stdout: $fh);
        $emitter->emit('stage_start');
        $emitter->emit('stage_complete');
        $emitter->emit('hop_complete');
        fclose($fh);

        $lines = array_filter(explode("\n", trim((string) file_get_contents($tmpFile))));
        self::assertCount(3, $lines);

        $seqs = [];
        foreach ($lines as $line) {
            /** @var array<string, mixed> $d */
            $d = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $seqs[] = $d['seq'];
        }

        self::assertSame([1, 2, 3], $seqs, 'seq must increment by 1 for each emit.');

        @unlink($tmpFile);
    }

    // -----------------------------------------------------------------------
    // WorkspaceManager: lock released on cleanup
    // -----------------------------------------------------------------------

    public function testWorkspaceManagerReleasesLockOnCleanup(): void
    {
        $fakeRepo = sys_get_temp_dir() . '/hardening-repo-' . uniqid();
        mkdir($fakeRepo, 0700, true);
        // Place a minimal file so it's a valid dir
        file_put_contents($fakeRepo . '/composer.json', '{}');

        $manager = new \App\Workspace\WorkspaceManager();

        $workspacePath = $manager->createWorkspace($fakeRepo, '9');
        self::assertDirectoryExists($workspacePath);

        // cleanup() must release the lock AND remove the workspace directory
        $manager->cleanup($workspacePath);

        self::assertDirectoryDoesNotExist($workspacePath, 'Workspace directory must be removed after cleanup().');

        // After lock is released, acquire again must succeed (not throw ConcurrentUpgradeException)
        $workspacePath2 = $manager->createWorkspace($fakeRepo, '9');
        $manager->cleanup($workspacePath2);

        // Cleanup
        @unlink($fakeRepo . '/composer.json');
        @rmdir($fakeRepo);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeHop(): Hop
    {
        return new Hop(
            dockerImage: 'upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );
    }
}

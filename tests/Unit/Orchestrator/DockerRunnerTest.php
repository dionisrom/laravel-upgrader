<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\Hop;
use App\Orchestrator\HopFailureException;
use App\Orchestrator\UpgradeOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DockerRunnerTest extends TestCase
{
    private Hop $hop;

    protected function setUp(): void
    {
        $this->hop = new Hop(
            dockerImage: 'upgrader/hop-8-to-9',
            fromVersion: '8',
            toVersion: '9',
            type: 'laravel',
            phpBase: null,
        );
    }

    public function testRunBuildsCorrectDockerCommand(): void
    {
        $runner = new DockerRunner(dockerBin: 'docker');
        $command = $runner->buildCommand($this->hop, '/workspace/path', '/output/path');

        self::assertSame('docker', $command[0]);
        self::assertContains('run', $command);
        self::assertContains('--rm', $command);
        self::assertContains('--network=none', $command);
        self::assertContains('-v', $command);
        // The pre-staged output directory is mounted as /repo so the container
        // can transform it in-place. The original workspace is NOT mounted.
        self::assertContains('/output/path:/repo:rw', $command);
        self::assertNotContains('/workspace/path:/repo:rw', $command);
        self::assertNotContains('/output/path:/output:rw', $command);
        self::assertContains('--env', $command);
        self::assertContains('UPGRADER_HOP_FROM=8', $command);
        self::assertContains('UPGRADER_HOP_TO=9', $command);
        self::assertContains('UPGRADER_WORKSPACE=/repo', $command);
        self::assertSame('upgrader/hop-8-to-9', $command[count($command) - 1]);
    }

    public function testBuildCommandUsesConfiguredDockerBin(): void
    {
        $runner = new DockerRunner(dockerBin: '/usr/local/bin/docker');
        $command = $runner->buildCommand($this->hop, '/ws', '/out');

        self::assertSame('/usr/local/bin/docker', $command[0]);
    }

    public function testNetworkNoneIsAlwaysPresent(): void
    {
        $runner = new DockerRunner();
        $command = $runner->buildCommand($this->hop, '/ws', '/out');

        self::assertContains('--network=none', $command, '--network=none MUST always be present');
    }

    public function testBuildCommandMountsExtraComposerCacheWhenConfigured(): void
    {
        $runner = new DockerRunner();
        $options = new UpgradeOptions(extraComposerCacheDir: '/tmp/cache', skipDependencyUpgrader: true);

        $command = $runner->buildCommand($this->hop, '/ws', '/out', $options);

        self::assertContains('/tmp/cache:/composer-cache:rw', $command);
        self::assertContains('UPGRADER_EXTRA_COMPOSER_CACHE_DIR=/composer-cache', $command);
        self::assertContains('UPGRADER_SKIP_DEPENDENCY_UPGRADER=1', $command);
        self::assertContains('--network=none', $command);
    }

    public function testBuildPrimerCommandAllowsSeparateNetworkedPrefetch(): void
    {
        $runner = new DockerRunner();

        $command = $runner->buildPrimerCommand($this->hop, '/ws', '/cache');

        self::assertSame('docker', $command[0]);
        self::assertContains('/ws:/repo:rw', $command);
        self::assertContains('/cache:/composer-cache:rw', $command);
        self::assertContains('COMPOSER_CACHE_DIR=/composer-cache', $command);
        self::assertContains('/upgrader/src/Composer/RepositoryCachePrimer.php', $command);
        self::assertNotContains('--network=none', $command);
    }

    public function testBuildDependencyPreStageCommandUsesSeparateNetworkedContainer(): void
    {
        $runner = new DockerRunner();
        $options = new UpgradeOptions(extraComposerCacheDir: '/cache');

        $command = $runner->buildDependencyPreStageCommand($this->hop, '/ws', $options);

        self::assertContains('/ws:/repo:rw', $command);
        self::assertContains('/cache:/composer-cache:rw', $command);
        self::assertContains('UPGRADER_EXTRA_COMPOSER_CACHE_DIR=/composer-cache', $command);
        self::assertContains('/upgrader/src/Composer/DependencyUpgrader.php', $command);
        self::assertContains('--framework-target=^9.0', $command);
        self::assertNotContains('--network=none', $command);
    }

    public function testNonZeroExitThrowsHopFailureException(): void
    {
        $processFactory = static function (array $command): Process {
            return new Process(['php', '-r', 'fwrite(STDERR, "err\n"); exit(2);']);
        };

        $runner = new DockerRunner(processFactory: $processFactory);
        $streamer = new EventStreamer();

        $this->expectException(HopFailureException::class);

        $runner->run($this->hop, '/workspace', '/output', $streamer);
    }

    public function testHopFailureExceptionContainsExitCode(): void
    {
        $processFactory = static function (array $command): Process {
            return new Process(['php', '-r', 'exit(42);']);
        };

        $runner = new DockerRunner(processFactory: $processFactory);
        $streamer = new EventStreamer();

        try {
            $runner->run($this->hop, '/workspace', '/output', $streamer);
            self::fail('Expected HopFailureException to be thrown.');
        } catch (HopFailureException $e) {
            self::assertSame(42, $e->getExitCode());
        }
    }

    public function testValidJsonLinesAreDispatchedAsEvents(): void
    {
        /** @var list<array<string, mixed>> $dispatched */
        $dispatched = [];

        $processFactory = static function (array $command): Process {
            $json = addslashes('{"event":"stage_complete","stage":"rector"}');
            return new Process(['php', '-r', "echo \"{$json}\\n\"; exit(0);"]);
        };

        $runner = new DockerRunner(processFactory: $processFactory);

        $streamer = new EventStreamer();
        $streamer->addConsumer(new class ($dispatched) implements \App\Orchestrator\EventConsumerInterface {
            /** @param list<array<string, mixed>> $dispatched */
            public function __construct(private array &$dispatched) {}

            /** @param array<string, mixed> $event */
            public function consume(array $event): void
            {
                $this->dispatched[] = $event;
            }
        });

        $runner->run($this->hop, '/workspace', '/output', $streamer);

        $nonWarnings = array_values(array_filter(
            $dispatched,
            static fn (array $e): bool => ($e['event'] ?? '') !== 'stderr',
        ));

        self::assertNotEmpty($nonWarnings);
        self::assertSame('stage_complete', $nonWarnings[0]['event'] ?? null);
    }

    public function testNonJsonStdoutLinesDispatchWarning(): void
    {
        /** @var list<array<string, mixed>> $dispatched */
        $dispatched = [];

        $processFactory = static function (array $command): Process {
            return new Process(['php', '-r', "echo \"not json at all\\n\"; exit(0);"]);
        };

        $runner = new DockerRunner(processFactory: $processFactory);

        $streamer = new EventStreamer();
        $streamer->addConsumer(new class ($dispatched) implements \App\Orchestrator\EventConsumerInterface {
            /** @param list<array<string, mixed>> $dispatched */
            public function __construct(private array &$dispatched) {}

            /** @param array<string, mixed> $event */
            public function consume(array $event): void
            {
                $this->dispatched[] = $event;
            }
        });

        $runner->run($this->hop, '/workspace', '/output', $streamer);

        $warnings = array_values(array_filter(
            $dispatched,
            static fn (array $e): bool => ($e['event'] ?? '') === 'warning',
        ));

        self::assertNotEmpty($warnings, 'Non-JSON stdout lines must produce warning events.');
        self::assertStringContainsString('Non-JSON stdout line', (string) ($warnings[0]['message'] ?? ''));
    }
}

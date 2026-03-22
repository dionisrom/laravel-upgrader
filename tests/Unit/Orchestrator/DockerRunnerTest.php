<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\Hop;
use App\Orchestrator\HopFailureException;
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
        self::assertContains('/workspace/path:/repo:rw', $command);
        self::assertContains('/output/path:/output:rw', $command);
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

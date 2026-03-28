<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\ChainResumeHandler;
use App\Orchestrator\Hop;
use App\Orchestrator\HopSequence;
use App\State\ChainCheckpoint;
use App\State\HopResult;
use PHPUnit\Framework\TestCase;

final class ChainResumeTest extends TestCase
{
    private string $tmpDir;
    private ChainResumeHandler $handler;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/chain-resume-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
        $this->handler = new ChainResumeHandler();
    }

    protected function tearDown(): void
    {
        // Clean up temp files created during tests.
        $files = glob($this->tmpDir . '/*') ?: [];

        foreach ($files as $file) {
            @unlink($file);
        }

        @rmdir($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // readCheckpoint
    // -------------------------------------------------------------------------

    public function testReadCheckpointReturnsNullWhenFileAbsent(): void
    {
        $result = $this->handler->readCheckpoint($this->tmpDir);

        self::assertNull($result);
    }

    public function testReadCheckpointReturnsHydratedCheckpoint(): void
    {
        $checkpoint = $this->makeCheckpoint([], '8', '13');
        $this->handler->writeCheckpoint($checkpoint, $this->tmpDir);

        $read = $this->handler->readCheckpoint($this->tmpDir);

        self::assertNotNull($read);
        self::assertSame($checkpoint->chainId, $read->chainId);
        self::assertSame('8', $read->sourceVersion);
        self::assertSame('13', $read->targetVersion);
    }

    public function testReadCheckpointThrowsForInvalidJson(): void
    {
        file_put_contents($this->tmpDir . '/chain-checkpoint.json', 'not-json');

        $this->expectException(\JsonException::class);

        $this->handler->readCheckpoint($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // writeCheckpoint (atomic rename)
    // -------------------------------------------------------------------------

    public function testWriteCheckpointCreatesFile(): void
    {
        $checkpoint = $this->makeCheckpoint([], '8', '9');
        $this->handler->writeCheckpoint($checkpoint, $this->tmpDir);

        self::assertFileExists($this->tmpDir . '/chain-checkpoint.json');
    }

    public function testWriteCheckpointLeavesNoTmpFile(): void
    {
        $checkpoint = $this->makeCheckpoint([], '8', '9');
        $this->handler->writeCheckpoint($checkpoint, $this->tmpDir);

        self::assertFileDoesNotExist($this->tmpDir . '/chain-checkpoint.json.tmp');
    }

    public function testWriteCheckpointProducesValidJson(): void
    {
        $checkpoint = $this->makeCheckpoint([], '8', '13');
        $this->handler->writeCheckpoint($checkpoint, $this->tmpDir);

        $raw  = file_get_contents($this->tmpDir . '/chain-checkpoint.json');
        $data = json_decode((string) $raw, true);

        self::assertIsArray($data);
        self::assertSame($checkpoint->chainId, $data['chainId'] ?? null);
    }

    // -------------------------------------------------------------------------
    // findResumeIndex — core resume logic
    // -------------------------------------------------------------------------

    public function testFindResumeIndexReturnsZeroForNoCompletedHops(): void
    {
        $checkpoint = $this->makeCheckpoint([], '8', '13');
        $sequence   = $this->makeSequence([['8', '9'], ['9', '10'], ['10', '11'], ['11', '12'], ['12', '13']]);

        $index = $this->handler->findResumeIndex($checkpoint, $sequence);

        self::assertSame(0, $index);
    }

    public function testFindResumeIndexSkipsTwoCompletedHops(): void
    {
        $completed = [
            $this->makeHopResult('8', '9'),
            $this->makeHopResult('9', '10'),
        ];

        $checkpoint = $this->makeCheckpoint($completed, '8', '13');
        $sequence   = $this->makeSequence([['8', '9'], ['9', '10'], ['10', '11'], ['11', '12'], ['12', '13']]);

        $index = $this->handler->findResumeIndex($checkpoint, $sequence);

        self::assertSame(2, $index);
    }

    public function testFindResumeIndexSkipsThreeCompletedHops(): void
    {
        $completed = [
            $this->makeHopResult('8', '9'),
            $this->makeHopResult('9', '10'),
            $this->makeHopResult('10', '11'),
        ];

        $checkpoint = $this->makeCheckpoint($completed, '8', '13');
        $sequence   = $this->makeSequence([['8', '9'], ['9', '10'], ['10', '11'], ['11', '12'], ['12', '13']]);

        $index = $this->handler->findResumeIndex($checkpoint, $sequence);

        self::assertSame(3, $index);
    }

    public function testFindResumeIndexReturnsSequenceLengthWhenAllDone(): void
    {
        $completed = [
            $this->makeHopResult('8', '9'),
            $this->makeHopResult('9', '10'),
            $this->makeHopResult('10', '11'),
            $this->makeHopResult('11', '12'),
            $this->makeHopResult('12', '13'),
        ];

        $checkpoint = $this->makeCheckpoint($completed, '8', '13');
        $sequence   = $this->makeSequence([['8', '9'], ['9', '10'], ['10', '11'], ['11', '12'], ['12', '13']]);

        $index = $this->handler->findResumeIndex($checkpoint, $sequence);

        self::assertSame(5, $index);
    }

    public function testFindResumeIndexWorksForSingleHopChain(): void
    {
        $checkpoint = $this->makeCheckpoint([], '8', '9');
        $sequence   = $this->makeSequence([['8', '9']]);

        $index = $this->handler->findResumeIndex($checkpoint, $sequence);

        self::assertSame(0, $index);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<HopResult> $completedHops
     */
    private function makeCheckpoint(array $completedHops, string $from, string $to): ChainCheckpoint
    {
        return new ChainCheckpoint(
            chainId:       'test-chain-id-' . uniqid('', true),
            sourceVersion: $from,
            targetVersion: $to,
            completedHops: $completedHops,
            currentHop:    null,
            workspacePath: $this->tmpDir,
            startedAt:     new \DateTimeImmutable(),
            updatedAt:     null,
        );
    }

    private function makeHopResult(string $from, string $to): HopResult
    {
        return new HopResult(
            fromVersion: $from,
            toVersion:   $to,
            dockerImage: "upgrader/hop-{$from}-to-{$to}",
            outputPath:  '/tmp/fake-output',
            completedAt: new \DateTimeImmutable(),
            events:      [],
        );
    }

    /**
     * @param list<array{string, string}> $pairs
     */
    private function makeSequence(array $pairs): HopSequence
    {
        $hops = [];

        foreach ($pairs as [$from, $to]) {
            $hops[] = new Hop(
                dockerImage: "upgrader/hop-{$from}-to-{$to}",
                fromVersion: $from,
                toVersion:   $to,
                type:        'laravel',
                phpBase:     null,
            );
        }

        return new HopSequence($hops);
    }
}

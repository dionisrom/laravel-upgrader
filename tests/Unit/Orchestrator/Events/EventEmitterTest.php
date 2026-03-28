<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator\Events;

use App\Orchestrator\Events\EventCatalogue;
use AppContainer\EventEmitter;
use PHPUnit\Framework\TestCase;

final class EventEmitterTest extends TestCase
{
    /**
     * @return resource
     */
    private function openMemoryStream(): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function readStream(mixed $stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }

    public function testEmitWritesJsonNdToStdout(): void
    {
        $stream  = $this->openMemoryStream();
        $emitter = new EventEmitter('8_to_9', $stream);

        $emitter->emit(EventCatalogue::PIPELINE_START, [
            'total_files'  => 10,
            'php_files'    => 8,
            'config_files' => 2,
        ]);

        $output = $this->readStream($stream);

        self::assertStringEndsWith("\n", $output);
        $lines = array_filter(explode("\n", trim($output)));
        self::assertCount(1, $lines);

        $decoded = json_decode((string) array_values($lines)[0], true);
        self::assertIsArray($decoded);
        self::assertSame(EventCatalogue::PIPELINE_START, $decoded['event']);
    }

    public function testSeqIncrementsMonotonically(): void
    {
        $stream  = $this->openMemoryStream();
        $emitter = new EventEmitter('8_to_9', $stream);

        $emitter->emit(EventCatalogue::STAGE_START, ['stage' => 'rector']);
        $emitter->emit(EventCatalogue::STAGE_START, ['stage' => 'composer']);
        $emitter->emit(EventCatalogue::STAGE_COMPLETE, ['stage' => 'rector', 'duration_seconds' => 1.0, 'issues_found' => 0]);

        $output = $this->readStream($stream);
        $lines  = array_values(array_filter(explode("\n", trim($output))));
        self::assertCount(3, $lines);

        $seqs = array_map(static function (string $line): int {
            $data = json_decode($line, true);
            self::assertIsArray($data);
            return (int) $data['seq'];
        }, $lines);

        self::assertSame([1, 2, 3], $seqs);
    }

    public function testEmitIncludesAllBaseFields(): void
    {
        $stream  = $this->openMemoryStream();
        $emitter = new EventEmitter('9_to_10', $stream);

        $emitter->emit(EventCatalogue::HOP_COMPLETE, [
            'confidence'          => 0.9,
            'manual_review_count' => 0,
            'files_changed'       => 5,
        ]);

        $output  = $this->readStream($stream);
        $decoded = json_decode(trim($output), true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('event', $decoded);
        self::assertArrayHasKey('hop', $decoded);
        self::assertArrayHasKey('ts', $decoded);
        self::assertArrayHasKey('seq', $decoded);
        self::assertSame(EventCatalogue::HOP_COMPLETE, $decoded['event']);
        self::assertSame('9_to_10', $decoded['hop']);
        self::assertSame(1, $decoded['seq']);

        // TRD §13.1: ts must be Unix timestamp in seconds (float), not milliseconds
        self::assertIsFloat($decoded['ts']);
        self::assertGreaterThan(1_000_000_000, $decoded['ts']);
        self::assertLessThan(10_000_000_000, $decoded['ts']);
    }
}

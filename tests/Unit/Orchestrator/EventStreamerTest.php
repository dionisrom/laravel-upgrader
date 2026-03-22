<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Orchestrator\EventConsumerInterface;
use App\Orchestrator\EventStreamer;
use PHPUnit\Framework\TestCase;

final class EventStreamerTest extends TestCase
{
    public function testDispatchCallsAllConsumers(): void
    {
        /** @var list<array<string, mixed>> $received1 */
        $received1 = [];
        /** @var list<array<string, mixed>> $received2 */
        $received2 = [];

        $consumer1 = $this->makeConsumer(static function (array $event) use (&$received1): void {
            $received1[] = $event;
        });

        $consumer2 = $this->makeConsumer(static function (array $event) use (&$received2): void {
            $received2[] = $event;
        });

        $streamer = new EventStreamer([$consumer1, $consumer2]);
        $event = ['event' => 'test', 'value' => 42];
        $streamer->dispatch($event);

        self::assertCount(1, $received1);
        self::assertCount(1, $received2);
        self::assertSame($event, $received1[0]);
        self::assertSame($event, $received2[0]);
    }

    public function testFailingConsumerDoesNotBlockOtherConsumers(): void
    {
        /** @var list<array<string, mixed>> $received */
        $received = [];

        $failingConsumer = $this->makeConsumer(static function (array $event): void {
            throw new \RuntimeException('Consumer deliberately failed');
        });

        $goodConsumer = $this->makeConsumer(static function (array $event) use (&$received): void {
            $received[] = $event;
        });

        $streamer = new EventStreamer([$failingConsumer, $goodConsumer]);
        $streamer->dispatch(['event' => 'test']);

        self::assertCount(1, $received, 'Good consumer must still receive events when a prior consumer throws.');
    }

    public function testDispatchWarningCreatesCorrectEvent(): void
    {
        /** @var list<array<string, mixed>> $received */
        $received = [];

        $consumer = $this->makeConsumer(static function (array $event) use (&$received): void {
            $received[] = $event;
        });

        $streamer = new EventStreamer([$consumer]);
        $streamer->dispatchWarning('Something went wrong');

        self::assertCount(1, $received);
        self::assertSame('warning', $received[0]['event'] ?? null);
        self::assertSame('Something went wrong', $received[0]['message'] ?? null);
        self::assertArrayHasKey('ts', $received[0]);
    }

    public function testDispatchStderrLinesCreatesCorrectEvent(): void
    {
        /** @var list<array<string, mixed>> $received */
        $received = [];

        $consumer = $this->makeConsumer(static function (array $event) use (&$received): void {
            $received[] = $event;
        });

        $streamer = new EventStreamer([$consumer]);
        $streamer->dispatchStderrLines(['line 1', 'line 2']);

        self::assertCount(1, $received);
        self::assertSame('stderr', $received[0]['event'] ?? null);
        self::assertSame(['line 1', 'line 2'], $received[0]['lines'] ?? null);
    }

    public function testAddConsumerAppendsToExistingConsumers(): void
    {
        $callCount = 0;

        $consumer = $this->makeConsumer(static function (array $event) use (&$callCount): void {
            ++$callCount;
        });

        $streamer = new EventStreamer();
        $streamer->addConsumer($consumer);
        $streamer->addConsumer($consumer);

        $streamer->dispatch(['event' => 'ping']);

        self::assertSame(2, $callCount);
    }

    public function testDispatchWithNoConsumersDoesNotThrow(): void
    {
        $streamer = new EventStreamer();

        // Must not throw.
        $streamer->dispatch(['event' => 'noop']);

        $this->addToAssertionCount(1);
    }

    /**
     * @param callable(array<string, mixed>): void $fn
     */
    private function makeConsumer(callable $fn): EventConsumerInterface
    {
        return new class ($fn) implements EventConsumerInterface {
            /** @var callable(array<string, mixed>): void */
            private $fn;

            /** @param callable(array<string, mixed>): void $fn */
            public function __construct(callable $fn)
            {
                $this->fn = $fn;
            }

            /** @param array<string, mixed> $event */
            public function consume(array $event): void
            {
                ($this->fn)($event);
            }
        };
    }
}

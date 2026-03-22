<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Dashboard\EventBus;
use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;

final class EventBusTest extends TestCase
{
    public function testAddAndCountClients(): void
    {
        $bus = new EventBus();
        $bus->addClient('a', new ThroughStream());
        $bus->addClient('b', new ThroughStream());
        $bus->addClient('c', new ThroughStream());

        self::assertSame(3, $bus->clientCount());
    }

    public function testRemoveClient(): void
    {
        $bus = new EventBus();
        $bus->addClient('x', new ThroughStream());
        $bus->addClient('y', new ThroughStream());
        $bus->removeClient('x');

        self::assertSame(1, $bus->clientCount());
    }

    public function testBroadcastWritesToStreams(): void
    {
        $bus = new EventBus();

        $written = [];
        $stream = new ThroughStream();
        $stream->on('data', function (string $chunk) use (&$written): void {
            $written[] = $chunk;
        });

        $bus->addClient('s1', $stream);
        $bus->broadcast(['event' => 'test', 'value' => 42]);

        self::assertCount(1, $written);
        self::assertStringContainsString('"event":"test"', $written[0]);
        self::assertStringStartsWith('data: ', $written[0]);
        self::assertStringEndsWith("\n\n", $written[0]);
    }

    public function testBroadcastFailureInOneClientDoesNotBlockOthers(): void
    {
        $bus = new EventBus();

        // Create a stream and close it so writes will fail
        $brokenStream = new ThroughStream();
        $brokenStream->close();

        $written = [];
        $goodStream = new ThroughStream();
        $goodStream->on('data', function (string $chunk) use (&$written): void {
            $written[] = $chunk;
        });

        $bus->addClient('broken', $brokenStream);
        $bus->addClient('good', $goodStream);

        // Should not throw
        $bus->broadcast(['event' => 'ping']);

        self::assertCount(1, $written);
        self::assertStringContainsString('"event":"ping"', $written[0]);
    }

    public function testConsumeCallsBroadcast(): void
    {
        $bus = new EventBus();

        $written = [];
        $stream = new ThroughStream();
        $stream->on('data', function (string $chunk) use (&$written): void {
            $written[] = $chunk;
        });

        $bus->addClient('c1', $stream);
        $bus->consume(['event' => 'stage_start', 'stage' => 'rector']);

        self::assertCount(1, $written);
        self::assertStringContainsString('"event":"stage_start"', $written[0]);
    }
}

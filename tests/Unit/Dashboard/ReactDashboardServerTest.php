<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Dashboard\EventBus;
use App\Dashboard\ReactDashboardServer;
use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;

final class ReactDashboardServerTest extends TestCase
{
    public function testBroadcastDelegatesToEventBus(): void
    {
        $bus = new EventBus();

        $received = [];
        $stream = new ThroughStream();
        $stream->on('data', function (string $chunk) use (&$received): void {
            $received[] = $chunk;
        });
        $bus->addClient('test', $stream);

        $event = ['event' => 'file_changed', 'file' => 'app/Http/Kernel.php'];
        $server = new ReactDashboardServer($bus);
        $server->broadcast($event);

        self::assertCount(1, $received);
        self::assertStringContainsString('"event":"file_changed"', $received[0]);
    }

    public function testOpenBrowserDoesNotThrow(): void
    {
        $bus = new EventBus();
        $server = new ReactDashboardServer($bus);

        $this->expectNotToPerformAssertions();

        // Should not throw on any platform
        $server->openBrowser();
    }

    public function testStopOnUninitializedServerDoesNotThrow(): void
    {
        $bus = new EventBus();
        $server = new ReactDashboardServer($bus, 8765, '127.0.0.1');

        // stop() before start() should be a no-op (nullable socket/loop)
        $server->stop();

        self::assertTrue(true);
    }
}

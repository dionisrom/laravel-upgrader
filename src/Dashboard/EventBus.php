<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Orchestrator\EventConsumerInterface;
use React\Stream\ThroughStream;

final class EventBus implements EventConsumerInterface
{
    /** @var array<string, ThroughStream> */
    private array $clients = [];

    public function addClient(string $id, ThroughStream $stream): void
    {
        $this->clients[$id] = $stream;
    }

    public function removeClient(string $id): void
    {
        unset($this->clients[$id]);
    }

    public function clientCount(): int
    {
        return count($this->clients);
    }

    /**
     * Broadcast event to all connected SSE clients.
     * Failure in one client MUST NOT affect others.
     *
     * @param array<string, mixed> $event
     */
    public function broadcast(array $event): void
    {
        $payload = 'data: ' . json_encode($event) . "\n\n";

        foreach ($this->clients as $id => $stream) {
            try {
                if ($stream->write($payload) === false) {
                    $stream->close();
                    unset($this->clients[$id]);
                }
            } catch (\Throwable) {
                $stream->close();
                unset($this->clients[$id]);
            }
        }
    }

    /**
     * Implements EventConsumerInterface — delegates to broadcast().
     *
     * @param array<string, mixed> $event
     */
    public function consume(array $event): void
    {
        $this->broadcast($event);
    }
}

<?php

declare(strict_types=1);

namespace App\Orchestrator;

use App\Orchestrator\Events\EventParser;

final class EventStreamer
{
    private EventParser $parser;

    /**
     * @param list<EventConsumerInterface> $consumers
     */
    public function __construct(
        private array $consumers = [],
        ?EventParser $parser = null,
    ) {
        $this->parser = $parser ?? new EventParser();
    }

    public function addConsumer(EventConsumerInterface $consumer): void
    {
        $this->consumers[] = $consumer;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function dispatch(array $event): void
    {
        foreach ($this->consumers as $consumer) {
            try {
                $consumer->consume($event);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'EventStreamer: consumer %s threw %s: %s',
                    $consumer::class,
                    $e::class,
                    $e->getMessage(),
                ));
            }
        }
    }

    public function dispatchWarning(string $message): void
    {
        $this->dispatch([
            'event'   => 'warning',
            'message' => $message,
            'ts'      => time(),
        ]);
    }

    /**
     * @param list<string> $lines
     */
    public function dispatchStderrLines(array $lines): void
    {
        $this->dispatch([
            'event' => 'stderr',
            'lines' => $lines,
            'ts'    => time(),
        ]);
    }

    /**
     * Parse a JSON-ND line from container stdout and dispatch the resulting event.
     * If parsing fails, a WarningEvent is dispatched instead.
     */
    public function parseLine(string $line): void
    {
        $event = $this->parser->parseLine($line);
        $this->dispatch($event->toArray());
    }
}

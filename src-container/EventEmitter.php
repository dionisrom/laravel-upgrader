<?php

declare(strict_types=1);

namespace AppContainer;

final class EventEmitter
{
    private int $seq = 0;

    /**
     * @param resource $stdout
     */
    public function __construct(
        private readonly string $hop,
        private $stdout = STDOUT,
    ) {}

    /**
     * Emit a JSON-ND event to stdout.
     * Increments seq before each emit (starts at 1).
     *
     * @param array<string, mixed> $data Extra fields for this event type.
     */
    public function emit(string $eventType, array $data = []): void
    {
        ++$this->seq;

        $payload = array_merge($data, [
            'event' => $eventType,
            'hop'   => $this->hop,
            'ts'    => (int) (microtime(true) * 1000),
            'seq'   => $this->seq,
        ]);

        fwrite($this->stdout, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}

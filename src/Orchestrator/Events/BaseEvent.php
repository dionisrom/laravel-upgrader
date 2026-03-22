<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

abstract readonly class BaseEvent
{
    public function __construct(
        public string $event,
        public string $hop,
        public float $ts,
        public int $seq,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'hop'   => $this->hop,
            'ts'    => $this->ts,
            'seq'   => $this->seq,
        ];
    }
}

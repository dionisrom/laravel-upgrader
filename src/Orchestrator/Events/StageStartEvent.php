<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class StageStartEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $stage,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event: (string) ($data['event'] ?? EventCatalogue::STAGE_START),
            hop:   (string) ($data['hop'] ?? ''),
            ts:    (float)  ($data['ts'] ?? 0.0),
            seq:   (int)    ($data['seq'] ?? 0),
            stage: (string) ($data['stage'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'stage' => $this->stage,
        ]);
    }
}

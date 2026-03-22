<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class StageCompleteEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $stage,
        public float $durationSeconds,
        public int $issuesFound,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:           (string) ($data['event'] ?? EventCatalogue::STAGE_COMPLETE),
            hop:             (string) ($data['hop'] ?? ''),
            ts:              (float)  ($data['ts'] ?? 0.0),
            seq:             (int)    ($data['seq'] ?? 0),
            stage:           (string) ($data['stage'] ?? ''),
            durationSeconds: (float)  ($data['duration_seconds'] ?? 0.0),
            issuesFound:     (int)    ($data['issues_found'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'stage'            => $this->stage,
            'duration_seconds' => $this->durationSeconds,
            'issues_found'     => $this->issuesFound,
        ]);
    }
}

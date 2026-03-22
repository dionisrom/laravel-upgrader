<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class CheckpointWrittenEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public int $completedRulesCount,
        public int $pendingRulesCount,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:               (string) ($data['event'] ?? EventCatalogue::CHECKPOINT_WRITTEN),
            hop:                 (string) ($data['hop'] ?? ''),
            ts:                  (float)  ($data['ts'] ?? 0.0),
            seq:                 (int)    ($data['seq'] ?? 0),
            completedRulesCount: (int)    ($data['completed_rules_count'] ?? 0),
            pendingRulesCount:   (int)    ($data['pending_rules_count'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'completed_rules_count' => $this->completedRulesCount,
            'pending_rules_count'   => $this->pendingRulesCount,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class BreakingChangeAppliedEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $id,
        public bool $automated,
        public int $fileCount,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:     (string) ($data['event'] ?? EventCatalogue::BREAKING_CHANGE_APPLIED),
            hop:       (string) ($data['hop'] ?? ''),
            ts:        (float)  ($data['ts'] ?? 0.0),
            seq:       (int)    ($data['seq'] ?? 0),
            id:        (string) ($data['id'] ?? ''),
            automated: (bool)   ($data['automated'] ?? true),
            fileCount: (int)    ($data['file_count'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'id'        => $this->id,
            'automated' => $this->automated,
            'file_count' => $this->fileCount,
        ]);
    }
}

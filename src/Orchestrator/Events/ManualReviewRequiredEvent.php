<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class ManualReviewRequiredEvent extends BaseEvent
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $id,
        public bool $automated,
        public string $reason,
        public array $files,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @var list<string> $files */
        $files = array_values(array_filter(
            (array) ($data['files'] ?? []),
            static fn (mixed $v): bool => is_string($v),
        ));

        return new self(
            event:     (string) ($data['event'] ?? EventCatalogue::MANUAL_REVIEW_REQUIRED),
            hop:       (string) ($data['hop'] ?? ''),
            ts:        (float)  ($data['ts'] ?? 0.0),
            seq:       (int)    ($data['seq'] ?? 0),
            id:        (string) ($data['id'] ?? ''),
            automated: (bool)   ($data['automated'] ?? false),
            reason:    (string) ($data['reason'] ?? ''),
            files:     $files,
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
            'reason'    => $this->reason,
            'files'     => $this->files,
        ]);
    }
}

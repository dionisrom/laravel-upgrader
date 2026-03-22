<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class HopCompleteEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public float $confidence,
        public int $manualReviewCount,
        public int $filesChanged,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:             (string) ($data['event'] ?? EventCatalogue::HOP_COMPLETE),
            hop:               (string) ($data['hop'] ?? ''),
            ts:                (float)  ($data['ts'] ?? 0.0),
            seq:               (int)    ($data['seq'] ?? 0),
            confidence:        (float)  ($data['confidence'] ?? 0.0),
            manualReviewCount: (int)    ($data['manual_review_count'] ?? 0),
            filesChanged:      (int)    ($data['files_changed'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'confidence'          => $this->confidence,
            'manual_review_count' => $this->manualReviewCount,
            'files_changed'       => $this->filesChanged,
        ]);
    }
}

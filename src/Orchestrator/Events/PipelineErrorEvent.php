<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class PipelineErrorEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $message,
        public string $stage,
        public bool $recoverable,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:       (string) ($data['event'] ?? EventCatalogue::PIPELINE_ERROR),
            hop:         (string) ($data['hop'] ?? ''),
            ts:          (float)  ($data['ts'] ?? 0.0),
            seq:         (int)    ($data['seq'] ?? 0),
            message:     (string) ($data['message'] ?? ''),
            stage:       (string) ($data['stage'] ?? ''),
            recoverable: (bool)   ($data['recoverable'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'message'     => $this->message,
            'stage'       => $this->stage,
            'recoverable' => $this->recoverable,
        ]);
    }
}

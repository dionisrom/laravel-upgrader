<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class WarningEvent extends BaseEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $message,
        public array $context,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @var array<string, mixed> $context */
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];

        return new self(
            event:   (string) ($data['event'] ?? EventCatalogue::WARNING),
            hop:     (string) ($data['hop'] ?? ''),
            ts:      (float)  ($data['ts'] ?? 0.0),
            seq:     (int)    ($data['seq'] ?? 0),
            message: (string) ($data['message'] ?? ''),
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromMessage(string $message, array $context = []): self
    {
        return new self(
            event:   EventCatalogue::WARNING,
            hop:     '',
            ts:      microtime(true),
            seq:     0,
            message: $message,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'message' => $this->message,
            'context' => $this->context,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class PhpstanRegressionEvent extends BaseEvent
{
    /**
     * @param list<string> $newErrors
     */
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public int $beforeCount,
        public int $afterCount,
        public array $newErrors,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @var list<string> $newErrors */
        $newErrors = array_values(array_filter(
            (array) ($data['new_errors'] ?? []),
            static fn (mixed $v): bool => is_string($v),
        ));

        return new self(
            event:       (string) ($data['event'] ?? EventCatalogue::PHPSTAN_REGRESSION),
            hop:         (string) ($data['hop'] ?? ''),
            ts:          (float)  ($data['ts'] ?? 0.0),
            seq:         (int)    ($data['seq'] ?? 0),
            beforeCount: (int)    ($data['before_count'] ?? 0),
            afterCount:  (int)    ($data['after_count'] ?? 0),
            newErrors:   $newErrors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'before_count' => $this->beforeCount,
            'after_count'  => $this->afterCount,
            'new_errors'   => $this->newErrors,
        ]);
    }
}

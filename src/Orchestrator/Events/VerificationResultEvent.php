<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class VerificationResultEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $step,
        public bool $passed,
        public int $issueCount,
        public float $durationSeconds,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:           (string) ($data['event'] ?? EventCatalogue::VERIFICATION_RESULT),
            hop:             (string) ($data['hop'] ?? ''),
            ts:              (float)  ($data['ts'] ?? 0.0),
            seq:             (int)    ($data['seq'] ?? 0),
            step:            (string) ($data['step'] ?? ''),
            passed:          (bool)   ($data['passed'] ?? false),
            issueCount:      (int)    ($data['issue_count'] ?? 0),
            durationSeconds: (float)  ($data['duration_seconds'] ?? 0.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'step'             => $this->step,
            'passed'           => $this->passed,
            'issue_count'      => $this->issueCount,
            'duration_seconds' => $this->durationSeconds,
        ]);
    }
}

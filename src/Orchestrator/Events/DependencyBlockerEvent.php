<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class DependencyBlockerEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $package,
        public string $currentVersion,
        public string $severity,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:          (string) ($data['event'] ?? EventCatalogue::DEPENDENCY_BLOCKER),
            hop:            (string) ($data['hop'] ?? ''),
            ts:             (float)  ($data['ts'] ?? 0.0),
            seq:            (int)    ($data['seq'] ?? 0),
            package:        (string) ($data['package'] ?? ''),
            currentVersion: (string) ($data['current_version'] ?? ''),
            severity:       (string) ($data['severity'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'package'         => $this->package,
            'current_version' => $this->currentVersion,
            'severity'        => $this->severity,
        ]);
    }
}

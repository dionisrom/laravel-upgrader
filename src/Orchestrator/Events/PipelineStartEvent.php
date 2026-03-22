<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class PipelineStartEvent extends BaseEvent
{
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public int $totalFiles,
        public int $phpFiles,
        public int $configFiles,
    ) {
        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            event:       (string) ($data['event'] ?? EventCatalogue::PIPELINE_START),
            hop:         (string) ($data['hop'] ?? ''),
            ts:          (float)  ($data['ts'] ?? 0.0),
            seq:         (int)    ($data['seq'] ?? 0),
            totalFiles:  (int)    ($data['total_files'] ?? 0),
            phpFiles:    (int)    ($data['php_files'] ?? 0),
            configFiles: (int)    ($data['config_files'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'total_files'  => $this->totalFiles,
            'php_files'    => $this->phpFiles,
            'config_files' => $this->configFiles,
        ]);
    }
}

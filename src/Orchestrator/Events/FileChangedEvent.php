<?php

declare(strict_types=1);

namespace App\Orchestrator\Events;

final readonly class FileChangedEvent extends BaseEvent
{
    /**
     * @param list<string> $rules
     */
    public function __construct(
        string $event,
        string $hop,
        float $ts,
        int $seq,
        public string $file,
        public array $rules,
        public int $linesAdded,
        public int $linesRemoved,
    ) {
        if (str_starts_with($file, '/')) {
            throw new \InvalidArgumentException(
                'FileChangedEvent: file path must be relative, got absolute path: ' . $file,
            );
        }

        parent::__construct($event, $hop, $ts, $seq);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @var list<string> $rules */
        $rules = array_values(array_filter(
            (array) ($data['rules'] ?? []),
            static fn (mixed $v): bool => is_string($v),
        ));

        return new self(
            event:        (string) ($data['event'] ?? EventCatalogue::FILE_CHANGED),
            hop:          (string) ($data['hop'] ?? ''),
            ts:           (float)  ($data['ts'] ?? 0.0),
            seq:          (int)    ($data['seq'] ?? 0),
            file:         (string) ($data['file'] ?? ''),
            rules:        $rules,
            linesAdded:   (int)    ($data['lines_added'] ?? 0),
            linesRemoved: (int)    ($data['lines_removed'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'file'          => $this->file,
            'rules'         => $this->rules,
            'lines_added'   => $this->linesAdded,
            'lines_removed' => $this->linesRemoved,
        ]);
    }
}

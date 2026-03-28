<?php

declare(strict_types=1);

namespace App\State;

final readonly class HopResult implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(
        public string $fromVersion,
        public string $toVersion,
        public string $dockerImage,
        public string $outputPath,
        public \DateTimeImmutable $completedAt,
        public array $events = [],
        public ?string $inputPath = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'fromVersion' => $this->fromVersion,
            'toVersion'   => $this->toVersion,
            'dockerImage' => $this->dockerImage,
            'outputPath'  => $this->outputPath,
            'completedAt' => $this->completedAt->format(\DateTimeInterface::ATOM),
            'events'      => $this->events,
            'inputPath'   => $this->inputPath,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $events */
        $events = is_array($data['events'] ?? null) ? array_values((array) $data['events']) : [];

        return new self(
            fromVersion: (string) ($data['fromVersion'] ?? ''),
            toVersion:   (string) ($data['toVersion'] ?? ''),
            dockerImage: (string) ($data['dockerImage'] ?? ''),
            outputPath:  (string) ($data['outputPath'] ?? ''),
            completedAt: new \DateTimeImmutable((string) ($data['completedAt'] ?? 'now')),
            events:      $events,
            inputPath:   isset($data['inputPath']) ? (string) $data['inputPath'] : null,
        );
    }
}

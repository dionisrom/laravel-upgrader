<?php

declare(strict_types=1);

namespace App\Orchestrator\State;

final readonly class Checkpoint
{
    /**
     * @param list<string> $completedRules
     * @param list<string> $pendingRules
     * @param array<string, string> $filesHashed  relative path => "sha256:{hex}"
     */
    public function __construct(
        public string $hop,
        public string $schemaVersion,
        public array $completedRules,
        public array $pendingRules,
        public array $filesHashed,
        public string $timestamp,
        public bool $canResume,
        public string $hostVersion,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hop: (string) ($data['hop'] ?? ''),
            schemaVersion: (string) ($data['schema_version'] ?? '1'),
            completedRules: array_values(array_map('strval', (array) ($data['completed_rules'] ?? []))),
            pendingRules: array_values(array_map('strval', (array) ($data['pending_rules'] ?? []))),
            filesHashed: array_map('strval', (array) ($data['files_hashed'] ?? [])),
            timestamp: (string) ($data['timestamp'] ?? ''),
            canResume: (bool) ($data['can_resume'] ?? false),
            hostVersion: (string) ($data['host_version'] ?? '1.0.0'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hop' => $this->hop,
            'schema_version' => $this->schemaVersion,
            'completed_rules' => $this->completedRules,
            'pending_rules' => $this->pendingRules,
            'files_hashed' => $this->filesHashed,
            'timestamp' => $this->timestamp,
            'can_resume' => $this->canResume,
            'host_version' => $this->hostVersion,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\State;

/**
 * Chain-level checkpoint value object.
 *
 * Persisted as {@code chain-checkpoint.json} in the chain's checkpoint
 * directory after every hop completion. Enables {@see \App\Orchestrator\ChainResumeHandler}
 * to resume a chain from the first non-completed hop.
 *
 * All mutations produce a new immutable instance (copy-on-write helpers).
 */
final class ChainCheckpoint implements \JsonSerializable
{
    /**
     * @param list<HopResult> $completedHops Hops that have successfully completed.
     */
    public function __construct(
        public readonly string $chainId,
        public readonly string $sourceVersion,
        public readonly string $targetVersion,
        public readonly array $completedHops,
        public readonly ?string $currentHop,
        public readonly string $workspacePath,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // Copy-on-write mutation helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a new checkpoint that records $hop as in-progress.
     */
    public function withCurrentHop(string $hopKey): self
    {
        return new self(
            chainId:       $this->chainId,
            sourceVersion: $this->sourceVersion,
            targetVersion: $this->targetVersion,
            completedHops: $this->completedHops,
            currentHop:    $hopKey,
            workspacePath: $this->workspacePath,
            startedAt:     $this->startedAt,
            updatedAt:     new \DateTimeImmutable(),
        );
    }

    /**
     * Returns a new checkpoint that appends $hopResult to the completed list
     * and sets $newWorkspacePath as the current workspace.
     */
    public function withCompletedHop(HopResult $hopResult, string $newWorkspacePath): self
    {
        return new self(
            chainId:       $this->chainId,
            sourceVersion: $this->sourceVersion,
            targetVersion: $this->targetVersion,
            completedHops: [...$this->completedHops, $hopResult],
            currentHop:    null,
            workspacePath: $newWorkspacePath,
            startedAt:     $this->startedAt,
            updatedAt:     new \DateTimeImmutable(),
        );
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    public function isHopCompleted(string $fromVersion, string $toVersion): bool
    {
        foreach ($this->completedHops as $hopResult) {
            if ($hopResult->fromVersion === $fromVersion && $hopResult->toVersion === $toVersion) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'chainId'       => $this->chainId,
            'sourceVersion' => $this->sourceVersion,
            'targetVersion' => $this->targetVersion,
            'completedHops' => $this->completedHops,
            'currentHop'    => $this->currentHop,
            'workspacePath' => $this->workspacePath,
            'startedAt'     => $this->startedAt->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Deserialises a checkpoint from its JSON-decoded array representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $completedHops = [];

        foreach ((array) ($data['completedHops'] ?? []) as $hopData) {
            $completedHops[] = HopResult::fromArray((array) $hopData);
        }

        return new self(
            chainId:       (string) ($data['chainId'] ?? ''),
            sourceVersion: (string) ($data['sourceVersion'] ?? ''),
            targetVersion: (string) ($data['targetVersion'] ?? ''),
            completedHops: $completedHops,
            currentHop:    isset($data['currentHop']) ? (string) $data['currentHop'] : null,
            workspacePath: (string) ($data['workspacePath'] ?? ''),
            startedAt:     new \DateTimeImmutable((string) ($data['startedAt'] ?? 'now')),
            updatedAt:     isset($data['updatedAt']) && $data['updatedAt'] !== null
                ? new \DateTimeImmutable((string) $data['updatedAt'])
                : null,
        );
    }
}

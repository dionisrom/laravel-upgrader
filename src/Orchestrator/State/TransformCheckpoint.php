<?php

declare(strict_types=1);

namespace App\Orchestrator\State;

use App\Orchestrator\CheckpointManagerInterface;
use App\Orchestrator\Hop;

/**
 * Manages upgrade checkpoints stored in {workspacePath}/.upgrader-state/checkpoint.json
 *
 * NOTE: The .upgrader-state/ directory MUST be excluded from Rector processing.
 * Add this path to the Rector config's `skip` array:
 *   $rectorConfig->skip([__DIR__ . '/.upgrader-state']);
 * or pass it via --skip in the CLI invocation.
 *
 * Implements CheckpointManagerInterface so it can be injected into UpgradeOrchestrator.
 */
final class TransformCheckpoint implements CheckpointManagerInterface
{
    private const STATE_DIR = '.upgrader-state';
    private const CHECKPOINT_FILE = 'checkpoint.json';

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $hostVersion = '1.0.0',
    ) {}

    /**
     * Write/update the checkpoint after completing a batch of rules.
     * Uses atomic write: write to .tmp file → rename to final path.
     *
     * @param list<string> $completedRules
     * @param list<string> $pendingRules
     * @param array<string, string> $filesHashed  relative path => "sha256:{hex}"
     * @throws \InvalidArgumentException if any key in $filesHashed is an absolute path
     * @throws \RuntimeException if the checkpoint cannot be written
     */
    public function write(
        string $hop,
        array $completedRules,
        array $pendingRules,
        array $filesHashed,
    ): void {
        // Validate that file paths in filesHashed are relative
        foreach (array_keys($filesHashed) as $relativePath) {
            if (str_starts_with($relativePath, '/') || (strlen($relativePath) >= 2 && $relativePath[1] === ':')) {
                throw new \InvalidArgumentException(
                    "File paths in filesHashed must be relative, got absolute path: {$relativePath}"
                );
            }
        }

        $stateDir = $this->getStateDir();
        $this->ensureStateDirExists($stateDir);

        $checkpoint = new Checkpoint(
            hop: $hop,
            schemaVersion: '1',
            completedRules: array_values($completedRules),
            pendingRules: array_values($pendingRules),
            filesHashed: $filesHashed,
            timestamp: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            canResume: true,
            hostVersion: $this->hostVersion,
        );

        $json = json_encode($checkpoint->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $finalPath = $this->getCheckpointPath();
        $tmpPath = $finalPath . '.tmp';

        $bytesWritten = file_put_contents($tmpPath, $json);
        if ($bytesWritten === false) {
            throw new \RuntimeException("Failed to write checkpoint to temp file: {$tmpPath}");
        }

        if (!rename($tmpPath, $finalPath)) {
            // Clean up tmp file on failure
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to atomically rename checkpoint from {$tmpPath} to {$finalPath}");
        }
    }

    /**
     * Read the checkpoint file. Returns null if no checkpoint exists.
     *
     * @throws \RuntimeException if the checkpoint file exists but cannot be parsed
     */
    public function read(): ?Checkpoint
    {
        $path = $this->getCheckpointPath();

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read checkpoint file: {$path}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return Checkpoint::fromArray($data);
    }

    /**
     * Delete the checkpoint file (call after successful hop completion).
     */
    public function clear(): void
    {
        $path = $this->getCheckpointPath();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Returns true if the hop's "fromVersion_to_toVersion" matches the stored checkpoint
     * and the checkpoint has can_resume = true.
     */
    public function isCompleted(Hop $hop): bool
    {
        try {
            $checkpoint = $this->read();
        } catch (\RuntimeException) {
            return false;
        }

        if ($checkpoint === null) {
            return false;
        }

        $hopKey = "{$hop->fromVersion}_to_{$hop->toVersion}";

        return $checkpoint->hop === $hopKey && $checkpoint->canResume;
    }

    /**
     * Mark the hop as completed by clearing its checkpoint.
     */
    public function markCompleted(Hop $hop): void
    {
        $this->clear();
    }

    private function getStateDir(): string
    {
        return rtrim($this->workspacePath, '/\\') . DIRECTORY_SEPARATOR . self::STATE_DIR;
    }

    private function getCheckpointPath(): string
    {
        return $this->getStateDir() . DIRECTORY_SEPARATOR . self::CHECKPOINT_FILE;
    }

    private function ensureStateDirExists(string $stateDir): void
    {
        if (!is_dir($stateDir) && !mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
            throw new \RuntimeException("Failed to create state directory: {$stateDir}");
        }
    }
}

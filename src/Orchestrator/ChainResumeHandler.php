<?php

declare(strict_types=1);

namespace App\Orchestrator;

use App\State\ChainCheckpoint;

/**
 * Reads and writes chain-level checkpoints and calculates the resume index
 * into a {@see HopSequence}.
 *
 * The checkpoint file is always named {@code chain-checkpoint.json} and lives
 * in a caller-supplied directory that remains stable across hop executions.
 */
final class ChainResumeHandler
{
    private const CHECKPOINT_FILENAME = 'chain-checkpoint.json';

    // -------------------------------------------------------------------------
    // Checkpoint I/O
    // -------------------------------------------------------------------------

    /**
     * Reads the chain checkpoint from $dir. Returns null when none exists yet.
     *
     * @throws \RuntimeException if the file exists but cannot be read or parsed
     */
    public function readCheckpoint(string $dir): ?ChainCheckpoint
    {
        $path = $this->checkpointPath($dir);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Cannot read chain checkpoint: {$path}");
        }

        /** @var mixed $data */
        $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException("Chain checkpoint at {$path} is not a JSON object.");
        }

        return ChainCheckpoint::fromArray($data);
    }

    /**
     * Atomically writes the chain checkpoint into $dir.
     *
     * Uses a write-to-temp-then-rename strategy to avoid partial writes.
     *
     * @throws \RuntimeException if the file cannot be written or renamed
     */
    public function writeCheckpoint(ChainCheckpoint $checkpoint, string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException("Cannot create checkpoint directory: {$dir}");
        }

        $finalPath = $this->checkpointPath($dir);
        $tmpPath   = $finalPath . '.tmp';

        $json = json_encode(
            $checkpoint,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        if (file_put_contents($tmpPath, $json) === false) {
            throw new \RuntimeException("Cannot write chain checkpoint to: {$tmpPath}");
        }

        if (!rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException(
                "Cannot atomically rename chain checkpoint: {$tmpPath} → {$finalPath}",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Resume logic
    // -------------------------------------------------------------------------

    /**
     * Returns the 0-based index of the first hop in $sequence that has NOT yet
     * been recorded as completed in $checkpoint.
     *
     * Returns {@code count($sequence->hops)} when all hops are complete.
     */
    public function findResumeIndex(ChainCheckpoint $checkpoint, HopSequence $sequence): int
    {
        foreach ($sequence->hops as $index => $hop) {
            if (!$checkpoint->isHopCompleted($hop->fromVersion, $hop->toVersion)) {
                return $index;
            }
        }

        return count($sequence->hops);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function checkpointPath(string $dir): string
    {
        return rtrim($dir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . self::CHECKPOINT_FILENAME;
    }
}

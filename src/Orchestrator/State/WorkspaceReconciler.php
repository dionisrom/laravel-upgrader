<?php

declare(strict_types=1);

namespace App\Orchestrator\State;

final class WorkspaceReconciler
{
    public function __construct(
        private readonly FileHasher $hasher = new FileHasher(),
    ) {}

    /**
     * Reconcile the checkpoint against current file state.
     *
     * For each file tracked in the checkpoint:
     * - If the file no longer exists → listed in $modifiedFiles
     * - If the file hash matches → file is unchanged, rules are skippable
     * - If the file hash differs → listed in $modifiedFiles (caller emits WARNING)
     *
     * @throws NoCheckpointException if $checkpoint is null
     */
    public function reconcile(?Checkpoint $checkpoint, string $workspacePath): ReconcileResult
    {
        if ($checkpoint === null) {
            $checkpointPath = rtrim($workspacePath, '/\\') . DIRECTORY_SEPARATOR . '.upgrader-state' . DIRECTORY_SEPARATOR . 'checkpoint.json';
            throw new NoCheckpointException(
                "No checkpoint found at {$checkpointPath}. Run without --resume to start a fresh upgrade."
            );
        }

        if (!$checkpoint->canResume) {
            throw new CheckpointNotResumableException(
                "Checkpoint for hop '{$checkpoint->hop}' is marked as not resumable due to an inconsistent state. Run without --resume to start a fresh upgrade."
            );
        }

        $modifiedFiles = [];
        $workspacePath = rtrim($workspacePath, '/\\');

        foreach ($checkpoint->filesHashed as $relativePath => $storedHash) {
            $absolutePath = $workspacePath . DIRECTORY_SEPARATOR . $relativePath;

            if (!is_file($absolutePath)) {
                $modifiedFiles[] = $relativePath;
                continue;
            }

            try {
                $currentHash = $this->hasher->hash($absolutePath);
            } catch (\RuntimeException) {
                $modifiedFiles[] = $relativePath;
                continue;
            }

            if ($currentHash !== $storedHash) {
                $modifiedFiles[] = $relativePath;
            }
        }

        return new ReconcileResult(
            pendingRules: $checkpoint->pendingRules,
            skippedRules: $checkpoint->completedRules,
            modifiedFiles: $modifiedFiles,
            hasModifiedFiles: count($modifiedFiles) > 0,
        );
    }
}

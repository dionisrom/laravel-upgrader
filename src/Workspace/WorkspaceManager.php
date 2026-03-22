<?php

declare(strict_types=1);

namespace App\Workspace;

use App\Workspace\Exception\ConcurrentUpgradeException;
use App\Workspace\Exception\WorkspaceException;
use Symfony\Component\Process\Process;

final class WorkspaceManager
{
    private const DIR_MODE_WORKSPACE = 0700;

    /** @var array<string, resource> */
    private array $lockHandles = [];

    /** @var array<string, string> workspace path => original repo path */
    private array $workspaceToRepoPath = [];

    /**
     * Creates a content-addressed isolated workspace copy of $repoPath.
     * Directory permissions are 0700.
     * Returns the absolute path to the workspace.
     *
     * @throws WorkspaceException
     */
    public function createWorkspace(string $repoPath, string $targetVersion): string
    {
        $repoPath = $this->normalizePath($repoPath);

        if (!is_dir($repoPath)) {
            throw new WorkspaceException(sprintf('Source repository path does not exist: %s', $repoPath));
        }

        $this->acquireLock($repoPath);

        $workspaceId = hash('sha256', $repoPath . $targetVersion . (string) microtime(true));
        $workspacePath = $this->normalizePath(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upgrader' . DIRECTORY_SEPARATOR . $workspaceId
        );

        if (!mkdir($workspacePath, self::DIR_MODE_WORKSPACE, true) && !is_dir($workspacePath)) {
            throw new WorkspaceException(sprintf('Failed to create workspace directory: %s', $workspacePath));
        }

        $this->copyDirectory($repoPath, $workspacePath);

        $this->workspaceToRepoPath[$workspacePath] = $repoPath;

        return $workspacePath;
    }

    /**
     * Applies file diffs from a RectorResult to the workspace.
     * Each file is validated with `php -l` before writing.
     * Halts on first write failure and emits pipeline_error.
     * Returns ApplyResult with counts of applied/skipped/failed files.
     *
     * @param array<int, array{file: string, diff: string, appliedRectors: array<int, string>}> $fileDiffs
     */
    public function applyDiffs(string $workspacePath, array $fileDiffs): ApplyResult
    {
        $workspacePath = $this->normalizePath($workspacePath);
        $applied = 0;
        $skipped = 0;
        $failed = 0;
        $failedFile = null;
        $seq = 1;

        foreach ($fileDiffs as $fileDiff) {
            $relativeFile = $fileDiff['file'];
            $diff = $fileDiff['diff'];
            $appliedRectors = $fileDiff['appliedRectors'];

            $absolutePath = $this->normalizePath($workspacePath . DIRECTORY_SEPARATOR . $relativeFile);

            $originalContent = '';
            if (is_file($absolutePath)) {
                $content = file_get_contents($absolutePath);
                if ($content === false) {
                    $failed++;
                    $failedFile = $relativeFile;
                    $this->emitEvent([
                        'event' => 'pipeline_error',
                        'file' => $relativeFile,
                        'error' => 'Failed to read source file',
                        'ts' => time(),
                        'seq' => $seq++,
                    ]);
                    break;
                }
                $originalContent = $content;
            }

            $newContent = $this->applyUnifiedDiff($originalContent, $diff);

            // Write to a temp file first, validate, then move atomically
            $tmpFile = $absolutePath . '.upgrader.tmp';

            if (file_put_contents($tmpFile, $newContent) === false) {
                $failed++;
                $failedFile = $relativeFile;
                $this->emitEvent([
                    'event' => 'pipeline_error',
                    'file' => $relativeFile,
                    'error' => 'Failed to write temporary file (disk full or permissions)',
                    'ts' => time(),
                    'seq' => $seq++,
                ]);
                break;
            }

            if (!$this->validatePhpSyntax($tmpFile)) {
                @unlink($tmpFile);
                $failed++;
                $failedFile = $relativeFile;
                $this->emitEvent([
                    'event' => 'pipeline_error',
                    'file' => $relativeFile,
                    'error' => 'PHP syntax validation failed (php -l)',
                    'ts' => time(),
                    'seq' => $seq++,
                ]);
                break;
            }

            // Ensure parent directory exists in workspace
            $parentDir = dirname($absolutePath);
            if (!is_dir($parentDir)) {
                if (!mkdir($parentDir, self::DIR_MODE_WORKSPACE, true) && !is_dir($parentDir)) {
                    @unlink($tmpFile);
                    $failed++;
                    $failedFile = $relativeFile;
                    $this->emitEvent([
                        'event' => 'pipeline_error',
                        'file' => $relativeFile,
                        'error' => 'Failed to create parent directory in workspace',
                        'ts' => time(),
                        'seq' => $seq++,
                    ]);
                    break;
                }
            }

            if (!rename($tmpFile, $absolutePath)) {
                @unlink($tmpFile);
                $failed++;
                $failedFile = $relativeFile;
                $this->emitEvent([
                    'event' => 'pipeline_error',
                    'file' => $relativeFile,
                    'error' => 'Failed to atomically rename temp file to target',
                    'ts' => time(),
                    'seq' => $seq++,
                ]);
                break;
            }

            $applied++;
            $this->emitEvent([
                'event' => 'file_changed',
                'file' => $relativeFile,
                'rules' => $appliedRectors,
                'ts' => time(),
                'seq' => $seq++,
            ]);
        }

        return new ApplyResult(
            appliedCount: $applied,
            skippedCount: $skipped,
            failedCount: $failed,
            failedFile: $failedFile,
        );
    }

    /**
     * Copies the verified workspace back to the original repo path.
     * ONLY called after ALL hops complete successfully.
     * Original repo is NEVER modified during execution.
     *
     * @throws WorkspaceException
     */
    public function writeBack(string $workspacePath, string $originalRepoPath): void
    {
        $workspacePath = $this->normalizePath($workspacePath);
        $originalRepoPath = $this->normalizePath($originalRepoPath);

        if (!is_dir($workspacePath)) {
            throw new WorkspaceException(sprintf('Workspace path does not exist: %s', $workspacePath));
        }

        if (!is_dir($originalRepoPath)) {
            throw new WorkspaceException(sprintf('Original repo path does not exist: %s', $originalRepoPath));
        }

        $this->copyDirectory($workspacePath, $originalRepoPath);
    }

    /**
     * Removes the temporary workspace directory.
     */
    public function cleanup(string $workspacePath): void
    {
        $workspacePath = $this->normalizePath($workspacePath);

        if (isset($this->workspaceToRepoPath[$workspacePath])) {
            $this->releaseLock($this->workspaceToRepoPath[$workspacePath]);
            unset($this->workspaceToRepoPath[$workspacePath]);
        }

        if (!is_dir($workspacePath)) {
            return;
        }

        $this->removeDirectory($workspacePath);
    }

    /**
     * Releases the advisory lock held for the given repo path.
     */
    public function releaseLock(string $repoPath): void
    {
        $repoPath = $this->normalizePath($repoPath);
        $key = hash('sha256', $repoPath);

        if (!isset($this->lockHandles[$key])) {
            return;
        }

        flock($this->lockHandles[$key], LOCK_UN);
        fclose($this->lockHandles[$key]);
        unset($this->lockHandles[$key]);
    }

    /**
     * Acquires an exclusive advisory lock for the given repo path.
     * Throws ConcurrentUpgradeException if already locked by another process.
     *
     * @throws ConcurrentUpgradeException
     */
    private function acquireLock(string $repoPath): void
    {
        $lockDir = sys_get_temp_dir() . '/upgrader/locks/';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }

        $key = hash('sha256', $repoPath);
        $lockFile = $lockDir . $key . '.lock';
        $fh = fopen($lockFile, 'c');

        if ($fh === false) {
            throw new ConcurrentUpgradeException('Unable to open lock file for repository.');
        }

        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new ConcurrentUpgradeException(
                'Another upgrade is already running for this repository.'
            );
        }

        $this->lockHandles[$key] = $fh;
    }

    /**
     * Translates OS-specific path separators and drive letters for
     * Windows/WSL2 compatibility (F-09).
     */
    private function normalizePath(string $path): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return str_replace('\\', '/', $path);
        }

        return $path;
    }

    private function validatePhpSyntax(string $filePath): bool
    {
        $process = new Process([PHP_BINARY, '-l', $filePath]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Emits a JSON-ND event to STDOUT (one JSON object per line).
     *
     * @param array<string, mixed> $event
     */
    private function emitEvent(array $event): void
    {
        echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Applies a unified diff string to source content and returns the result.
     *
     * Parses the standard unified diff format (@@-hunks) and applies each
     * hunk sequentially, respecting context lines as anchors.
     */
    private function applyUnifiedDiff(string $originalContent, string $diff): string
    {
        if (trim($diff) === '') {
            return $originalContent;
        }

        $originalLines = explode("\n", $originalContent);
        $diffLines = explode("\n", $diff);

        $hunks = $this->parseDiffHunks($diffLines);

        if ($hunks === []) {
            return $originalContent;
        }

        $result = [];
        $origPos = 0; // 0-indexed cursor in $originalLines

        foreach ($hunks as $hunk) {
            $hunkStart = $hunk['original_start'] - 1; // 0-indexed

            // Copy original lines before this hunk
            while ($origPos < $hunkStart && $origPos < count($originalLines)) {
                $result[] = $originalLines[$origPos];
                $origPos++;
            }

            // Apply hunk lines: context copies, remove skips, add inserts
            foreach ($hunk['lines'] as $pLine) {
                if (str_starts_with($pLine, ' ')) {
                    // Context line: take from original (authoritative)
                    $result[] = $originalLines[$origPos] ?? substr($pLine, 1);
                    $origPos++;
                } elseif (str_starts_with($pLine, '-')) {
                    // Removed line: skip original
                    $origPos++;
                } elseif (str_starts_with($pLine, '+')) {
                    // Added line: insert new content
                    $result[] = substr($pLine, 1);
                }
            }
        }

        // Copy remaining original lines after the last hunk
        while ($origPos < count($originalLines)) {
            $result[] = $originalLines[$origPos];
            $origPos++;
        }

        return implode("\n", $result);
    }

    /**
     * Parses unified diff hunk headers and their content lines.
     *
     * @param array<int, string> $diffLines
     * @return array<int, array{original_start: int, original_count: int, new_start: int, new_count: int, lines: array<int, string>}>
     */
    private function parseDiffHunks(array $diffLines): array
    {
        $hunks = [];
        $currentHunk = null;

        foreach ($diffLines as $line) {
            if (str_starts_with($line, '@@')) {
                if ($currentHunk !== null) {
                    $hunks[] = $currentHunk;
                }

                // Parse: @@ -originalStart,originalCount +newStart,newCount @@
                if (preg_match('/@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                    $currentHunk = [
                        'original_start' => (int) $matches[1],
                        'original_count' => $matches[2] !== '' ? (int) $matches[2] : 1,
                        'new_start' => (int) $matches[3],
                        'new_count' => ($matches[4] ?? '') !== '' ? (int) $matches[4] : 1,
                        'lines' => [],
                    ];
                }
            } elseif ($currentHunk !== null && (
                str_starts_with($line, '+') ||
                str_starts_with($line, '-') ||
                str_starts_with($line, ' ')
            )) {
                $currentHunk['lines'][] = $line;
            }
        }

        if ($currentHunk !== null) {
            $hunks[] = $currentHunk;
        }

        return $hunks;
    }

    /**
     * Recursively copies a directory tree from $source to $destination.
     *
     * @throws WorkspaceException
     */
    private function copyDirectory(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $subPath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $this->normalizePath($destination . DIRECTORY_SEPARATOR . $subPath);

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (!mkdir($targetPath, self::DIR_MODE_WORKSPACE, true) && !is_dir($targetPath)) {
                        throw new WorkspaceException(sprintf('Failed to create directory: %s', $targetPath));
                    }
                }
            } else {
                if (!copy($item->getPathname(), $targetPath)) {
                    throw new WorkspaceException(sprintf('Failed to copy file: %s', $item->getPathname()));
                }
            }
        }
    }

    /**
     * Recursively removes a directory and all its contents.
     */
    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

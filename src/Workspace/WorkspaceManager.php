<?php

declare(strict_types=1);

namespace App\Workspace;

use App\Orchestrator\State\TransformCheckpoint;
use App\Workspace\Exception\ConcurrentUpgradeException;
use App\Workspace\Exception\WorkspaceException;
use Symfony\Component\Process\Process;

final class WorkspaceManager
{
    private const DIR_MODE_WORKSPACE = 0700;
    /** @var list<string> */
    private const WRITEBACK_PRESERVED_PATHS = [
        '.git',
        '.svn',
        '.hg',
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        '.upgrader-state',
    ];
    private const DEFAULT_CHECKPOINT_HOP = 'workspace_apply';

    /** @var \Closure(string): string */
    private \Closure $pathNormalizer;

    /** @var \Closure(string, string, array<int, string>, string): void */
    private \Closure $checkpointWriter;

    /** @var \Closure(array<string, mixed>): void */
    private \Closure $eventEmitter;

    /** @var array<string, resource> */
    private array $lockHandles = [];

    /** @var array<string, string> workspace path => original repo path */
    private array $workspaceToRepoPath = [];

    public function __construct(
        ?callable $pathNormalizer = null,
        ?callable $checkpointWriter = null,
        ?callable $eventEmitter = null,
    ) {
        $this->pathNormalizer = $pathNormalizer !== null
            ? \Closure::fromCallable($pathNormalizer)
            : \Closure::fromCallable([$this, 'defaultNormalizePath']);

        $this->checkpointWriter = $checkpointWriter !== null
            ? \Closure::fromCallable($checkpointWriter)
            : \Closure::fromCallable([$this, 'writeTransformCheckpoint']);

        $this->eventEmitter = $eventEmitter !== null
            ? \Closure::fromCallable($eventEmitter)
            : \Closure::fromCallable([$this, 'defaultEmitEvent']);
    }

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
            $relativeFile = $this->normalizeRelativePath($fileDiff['file']);
            $diff = $fileDiff['diff'];
            $appliedRectors = $fileDiff['appliedRectors'];

            $absolutePath = $this->normalizePath(
                $workspacePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile)
            );

            $parentDir = dirname($absolutePath);
            if (!is_dir($parentDir)) {
                if (!mkdir($parentDir, self::DIR_MODE_WORKSPACE, true) && !is_dir($parentDir)) {
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

            try {
                ($this->checkpointWriter)($workspacePath, $relativeFile, $appliedRectors, $absolutePath);
            } catch (\Throwable $e) {
                $failed++;
                $failedFile = $relativeFile;
                $this->emitEvent([
                    'event' => 'pipeline_error',
                    'file' => $relativeFile,
                    'error' => sprintf('Failed to update transform checkpoint: %s', $e->getMessage()),
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

        $this->removeStaleFiles($originalRepoPath, $workspacePath);
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
        return ($this->pathNormalizer)($path);
    }

    private function defaultNormalizePath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);

        if ($this->looksLikeWindowsDrivePath($normalizedPath) && $this->shouldTranslateToWsl()) {
            return $this->translateWindowsPathToWsl($normalizedPath);
        }

        return $normalizedPath;
    }

    private function shouldTranslateToWsl(): bool
    {
        if (getenv('UPGRADER_FORCE_WSL_PATHS') === '1') {
            return true;
        }

        $wslDistro = getenv('WSL_DISTRO_NAME');
        if (is_string($wslDistro) && $wslDistro !== '') {
            return true;
        }

        $osRelease = @file_get_contents('/proc/sys/kernel/osrelease');
        if ($osRelease === false) {
            return false;
        }

        return str_contains(strtolower($osRelease), 'microsoft');
    }

    private function looksLikeWindowsDrivePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    private function translateWindowsPathToWsl(string $path): string
    {
        $driveLetter = strtolower($path[0]);
        $suffix = ltrim(substr($path, 2), '/');

        return $suffix === ''
            ? sprintf('/mnt/%s', $driveLetter)
            : sprintf('/mnt/%s/%s', $driveLetter, $suffix);
    }

    private function normalizeRelativePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param array<int, string> $appliedRectors
     */
    private function writeTransformCheckpoint(
        string $workspacePath,
        string $relativeFile,
        array $appliedRectors,
        string $absolutePath,
    ): void {
        $checkpoint = new TransformCheckpoint($workspacePath);
        $existing = $checkpoint->read();

        $completedRules = $existing?->completedRules ?? [];
        foreach ($appliedRectors as $appliedRector) {
            if (!in_array($appliedRector, $completedRules, true)) {
                $completedRules[] = $appliedRector;
            }
        }

        $fileHash = hash_file('sha256', $absolutePath);
        if ($fileHash === false) {
            throw new WorkspaceException(sprintf('Failed to hash updated file: %s', $relativeFile));
        }

        $filesHashed = $existing?->filesHashed ?? [];
        $filesHashed[$relativeFile] = 'sha256:' . $fileHash;

        $checkpoint->write(
            $existing?->hop ?? self::DEFAULT_CHECKPOINT_HOP,
            $completedRules,
            $existing?->pendingRules ?? [],
            $filesHashed,
        );
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
        ($this->eventEmitter)($event);
    }

    /**
     * Default event emitter — writes JSON-ND to STDOUT.
     *
     * @param array<string, mixed> $event
     */
    private function defaultEmitEvent(array $event): void
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
     * Removes files from $target that do not exist in $source, preserving VCS directories.
     */
    private function removeStaleFiles(string $target, string $source): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $subPath = substr($item->getPathname(), strlen($target) + 1);
            $normalizedSubPath = str_replace('\\', '/', $subPath);

            if ($this->shouldPreserveOnWriteBack($normalizedSubPath)) {
                continue;
            }

            $correspondingSource = $source . DIRECTORY_SEPARATOR . $subPath;

            if ($item->isDir()) {
                if (!is_dir($correspondingSource)) {
                    @rmdir($item->getPathname());
                }
            } else {
                if (!is_file($correspondingSource)) {
                    @unlink($item->getPathname());
                }
            }
        }
    }

    /**
     * Recursively copies a directory tree from $source to $destination.
     *
     * @throws WorkspaceException
     */
    private function copyDirectory(string $source, string $destination): void
    {
        $realSource = realpath($source);
        if ($realSource === false || !is_dir($realSource)) {
            throw new WorkspaceException(sprintf('Source directory does not exist: %s', $source));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realSource, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $itemPath = $item->getPathname();
            $subPath = substr($itemPath, strlen($realSource) + 1);
            $normalizedSubPath = str_replace('\\', '/', $subPath);

            if ($this->shouldPreserveOnWriteBack($normalizedSubPath)) {
                continue;
            }

            $targetPath = $this->normalizePath($destination . DIRECTORY_SEPARATOR . $subPath);

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (!mkdir($targetPath, self::DIR_MODE_WORKSPACE, true) && !is_dir($targetPath)) {
                        throw new WorkspaceException(sprintf('Failed to create directory: %s', $targetPath));
                    }
                }
            } else {
                $targetDirectory = dirname($targetPath);
                if (!is_dir($targetDirectory) && !mkdir($targetDirectory, self::DIR_MODE_WORKSPACE, true) && !is_dir($targetDirectory)) {
                    throw new WorkspaceException(sprintf('Failed to create parent directory: %s', $targetDirectory));
                }

                if (!copy($itemPath, $targetPath)) {
                    throw new WorkspaceException(sprintf('Failed to copy file: %s', $itemPath));
                }
            }
        }
    }

    private function shouldPreserveOnWriteBack(string $normalizedSubPath): bool
    {
        foreach (self::WRITEBACK_PRESERVED_PATHS as $preservedPath) {
            if ($normalizedSubPath === $preservedPath || str_starts_with($normalizedSubPath, $preservedPath . '/')) {
                return true;
            }
        }

        return false;
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

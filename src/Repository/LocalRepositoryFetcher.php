<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Exception\ConcurrentUpgradeException;
use App\Repository\Exception\RepositoryNotFoundException;
use Symfony\Component\Process\Process;

final class LocalRepositoryFetcher implements RepositoryFetcherInterface
{
    public function fetch(string $source, string $targetPath, ?string $token = null): FetchResult
    {
        if (!is_dir($source)) {
            throw new RepositoryNotFoundException("Repository not found at path: {$source}");
        }

        [$lockFile, $lockHandle] = $this->acquireLock($source);

        $this->copyDirectory($source, $targetPath);

        $defaultBranch = $this->resolveDefaultBranch($targetPath);
        $commitSha = $this->resolveCommitSha($targetPath);

        return new FetchResult(
            workspacePath: $targetPath,
            lockFilePath: $lockFile,
            defaultBranch: $defaultBranch,
            resolvedCommitSha: $commitSha,
        );
    }

    /**
     * @return array{string, resource}
     */
    private function acquireLock(string $repoPath): array
    {
        $lockDir = sys_get_temp_dir() . '/upgrader/locks/';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }

        $lockFile = $lockDir . hash('sha256', $repoPath) . '.lock';
        $fh = fopen($lockFile, 'c');

        if ($fh === false) {
            throw new ConcurrentUpgradeException("Unable to open lock file for repository.");
        }

        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new ConcurrentUpgradeException(
                "Another upgrade is already running for this repository."
            );
        }

        return [$lockFile, $fh];
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $process = new Process(['git', 'clone', '--local', '--no-hardlinks', $source, $target]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            // Fall back to rsync/xcopy-style recursive copy if not a git repo
            $this->recursiveCopy($source, $target);
        }
    }

    private function recursiveCopy(string $source, string $target): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $dest = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
            } else {
                copy($item->getPathname(), $dest);
            }
        }
    }

    private function resolveDefaultBranch(string $repoPath): string
    {
        $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $repoPath);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput()) ?: 'main';
        }

        return 'main';
    }

    private function resolveCommitSha(string $repoPath): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $repoPath);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput()) ?: 'unknown';
        }

        return 'unknown';
    }
}

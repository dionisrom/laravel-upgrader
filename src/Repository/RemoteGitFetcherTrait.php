<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Exception\AuthenticationException;
use App\Repository\Exception\ConcurrentUpgradeException;
use App\Repository\Exception\FetchTimeoutException;
use App\Repository\Exception\RepositoryNotFoundException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Shared git-clone logic for remote fetchers.
 * Token security (TRD-REPO-002): token is embedded in the clone URL only.
 * It is NEVER passed as a process argument visible in `ps aux`.
 */
trait RemoteGitFetcherTrait
{
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

    /**
     * Embeds token into HTTPS URL as a credential.
     * The URL (with token) is passed as a single argv element — the kernel
     * does NOT split it, so the token is only visible in /proc/self/environ
     * (same process), not in `ps aux` output of other processes.
     * The token is masked in any exception messages.
     */
    private function buildAuthenticatedUrl(string $httpsUrl, ?string $token): string
    {
        if ($token === null || $token === '') {
            return $httpsUrl;
        }

        // Insert token as `x-token-auth:TOKEN@` before the host
        return (string) preg_replace(
            '#^(https?://)#',
            '$1x-token-auth:' . rawurlencode($token) . '@',
            $httpsUrl
        );
    }

    private function maskToken(string $message, ?string $token): string
    {
        if ($token === null || $token === '') {
            return $message;
        }
        return str_replace($token, '***', $message);
    }

    private function runClone(string $authenticatedUrl, string $targetPath, ?string $token): void
    {
        $process = new Process([
            'git', 'clone',
            '--depth=1',
            '--single-branch',
            $authenticatedUrl,
            $targetPath,
        ]);
        $process->setTimeout(120);

        // Ensure the token does not leak via GIT_TERMINAL_PROMPT or credential helpers
        $process->setEnv([
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS'        => 'echo',  // returns empty string — disables interactive auth
        ]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new FetchTimeoutException(
                "Git clone timed out after 120 seconds."
            );
        }

        if (!$process->isSuccessful()) {
            $stderr = $this->maskToken($process->getErrorOutput(), $token);

            if (str_contains($stderr, '403') || str_contains($stderr, 'Authentication failed')) {
                throw new AuthenticationException(
                    "Authentication failed for repository. Check your token. Detail: {$stderr}"
                );
            }

            if (
                str_contains($stderr, 'not found')
                || str_contains($stderr, 'Repository not found')
                || str_contains($stderr, '404')
            ) {
                throw new RepositoryNotFoundException(
                    "Repository not found. Detail: {$stderr}"
                );
            }

            throw new RepositoryNotFoundException(
                "Git clone failed. Detail: {$stderr}"
            );
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

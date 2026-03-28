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
    private const CONCURRENT_UPGRADE_MESSAGE = 'An upgrade is already running for this repository. Use --resume to continue it, or wait for it to complete.';

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
            throw new ConcurrentUpgradeException(self::CONCURRENT_UPGRADE_MESSAGE);
        }

        return [$lockFile, $fh];
    }

    /**
     * Builds an HTTPS URL that carries only the provider username while the
     * PAT itself is supplied via GIT_ASKPASS.
     */
    private function buildAuthenticatedUrl(string $httpsUrl, ?string $token, string $username): string
    {
        if ($token === null || $token === '') {
            return $httpsUrl;
        }

        return (string) preg_replace(
            '#^(https?://)#',
            '$1' . rawurlencode($username) . '@',
            $httpsUrl
        );
    }

    private function createAskPassHelper(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $helperDir = sys_get_temp_dir() . '/upgrader/askpass/';
        if (!is_dir($helperDir)) {
            mkdir($helperDir, 0700, true);
        }

        $suffix = PHP_OS_FAMILY === 'Windows' ? '.bat' : '.sh';
        $helperPath = $helperDir . 'askpass-' . bin2hex(random_bytes(8)) . $suffix;

        $script = PHP_OS_FAMILY === 'Windows'
            ? "@echo off\r\necho %UPGRADER_GIT_PASSWORD%\r\n"
            : '#!/bin/sh' . "\n" . 'printf ' . "'%s\\n'" . ' "$UPGRADER_GIT_PASSWORD"' . "\n";

        file_put_contents($helperPath, $script);

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($helperPath, 0700);
        }

        return $helperPath;
    }

    /**
     * @return array<int, string>
     */
    private function buildCloneCommand(string $cloneUrl, string $targetPath): array
    {
        return [
            'git',
            'clone',
            '--depth=1',
            '--single-branch',
            $cloneUrl,
            $targetPath,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildGitEnvironment(?string $askPassHelper, ?string $token): array
    {
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        if ($askPassHelper !== null && $token !== null && $token !== '') {
            $env['GIT_ASKPASS'] = $askPassHelper;
            $env['UPGRADER_GIT_PASSWORD'] = $token;
        }

        return $env;
    }

    private function maskToken(string $message, ?string $token): string
    {
        if ($token === null || $token === '') {
            return $message;
        }
        return str_replace($token, '***', $message);
    }

    private function runClone(string $cloneUrl, string $targetPath, ?string $token): void
    {
        $askPassHelper = $this->createAskPassHelper($token);
        $process = $this->createProcess($this->buildCloneCommand($cloneUrl, $targetPath));
        $process->setTimeout(120);
        $process->setEnv($this->buildGitEnvironment($askPassHelper, $token));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new FetchTimeoutException(
                "Git clone timed out after 120 seconds."
            );
        } finally {
            if ($askPassHelper !== null && is_file($askPassHelper)) {
                @unlink($askPassHelper);
            }
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
        $process = $this->createProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $repoPath);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput()) ?: 'main';
        }

        return 'main';
    }

    private function resolveCommitSha(string $repoPath): string
    {
        $process = $this->createProcess(['git', 'rev-parse', 'HEAD'], $repoPath);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput()) ?: 'unknown';
        }

        return 'unknown';
    }
}

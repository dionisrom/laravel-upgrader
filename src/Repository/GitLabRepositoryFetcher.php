<?php

declare(strict_types=1);

namespace App\Repository;

use Symfony\Component\Process\Process;

final class GitLabRepositoryFetcher implements RepositoryFetcherInterface
{
    use RemoteGitFetcherTrait;

    public function __construct(private readonly ?\Closure $processFactory = null)
    {
    }

    public function fetch(string $source, string $targetPath, ?string $token = null): FetchResult
    {
        $httpsUrl = $this->normalizeToHttpsUrl($source);
        $authenticatedUrl = $this->buildAuthenticatedUrl($httpsUrl, $token, 'oauth2');

        [$lockFile, $lockHandle] = $this->acquireLock($httpsUrl);

        try {
            $this->runClone($authenticatedUrl, $targetPath, $token);

            return new FetchResult(
                workspacePath: $targetPath,
                lockFilePath: $lockFile,
                defaultBranch: $this->resolveDefaultBranch($targetPath),
                resolvedCommitSha: $this->resolveCommitSha($targetPath),
            );
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function createProcess(array $command, ?string $cwd = null): Process
    {
        if ($this->processFactory !== null) {
            return ($this->processFactory)($command, $cwd);
        }

        return new Process($command, $cwd);
    }

    private function normalizeToHttpsUrl(string $source): string
    {
        if (str_starts_with($source, 'gitlab:')) {
            $slug = substr($source, strlen('gitlab:'));
            return "https://gitlab.com/{$slug}.git";
        }

        // Already an HTTPS URL
        return $source;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

final class GitHubRepositoryFetcher implements RepositoryFetcherInterface
{
    use RemoteGitFetcherTrait;

    public function fetch(string $source, string $targetPath, ?string $token = null): FetchResult
    {
        $httpsUrl = $this->normalizeToHttpsUrl($source);
        $authenticatedUrl = $this->buildAuthenticatedUrl($httpsUrl, $token);

        [$lockFile] = $this->acquireLock($httpsUrl);

        $this->runClone($authenticatedUrl, $targetPath, $token);

        return new FetchResult(
            workspacePath: $targetPath,
            lockFilePath: $lockFile,
            defaultBranch: $this->resolveDefaultBranch($targetPath),
            resolvedCommitSha: $this->resolveCommitSha($targetPath),
        );
    }

    private function normalizeToHttpsUrl(string $source): string
    {
        if (str_starts_with($source, 'github:')) {
            $slug = substr($source, strlen('github:'));
            return "https://github.com/{$slug}.git";
        }

        // Already an HTTPS URL
        return $source;
    }
}

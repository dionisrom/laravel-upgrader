<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Exception\RepositoryNotFoundException;

final class RepositoryFetcherFactory
{
    public function make(string $source): RepositoryFetcherInterface
    {
        return match (true) {
            $this->isLocal($source)  => new LocalRepositoryFetcher(),
            $this->isGitHub($source) => new GitHubRepositoryFetcher(),
            $this->isGitLab($source) => new GitLabRepositoryFetcher(),
            default => throw new RepositoryNotFoundException(
                "Cannot determine fetcher for source: {$source}"
            ),
        };
    }

    private function isLocal(string $source): bool
    {
        // Absolute Unix path
        if (str_starts_with($source, '/')) {
            return true;
        }

        // Absolute Windows path (e.g. C:\... or D:/)
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $source) === 1) {
            return true;
        }

        // Existing directory (relative path)
        if (is_dir($source)) {
            return true;
        }

        return false;
    }

    private function isGitHub(string $source): bool
    {
        return str_starts_with($source, 'github:')
            || str_contains($source, 'github.com');
    }

    private function isGitLab(string $source): bool
    {
        return str_starts_with($source, 'gitlab:')
            || str_contains($source, 'gitlab.com');
    }
}

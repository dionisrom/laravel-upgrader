<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\Exception\RepositoryNotFoundException;
use App\Repository\GitHubRepositoryFetcher;
use App\Repository\GitLabRepositoryFetcher;
use App\Repository\LocalRepositoryFetcher;
use App\Repository\RepositoryFetcherFactory;
use PHPUnit\Framework\TestCase;

final class RepositoryFetcherFactoryTest extends TestCase
{
    private RepositoryFetcherFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RepositoryFetcherFactory();
    }

    // -------------------------------------------------------------------------
    // Local paths
    // -------------------------------------------------------------------------

    public function testMakeReturnsLocalFetcherForUnixAbsolutePath(): void
    {
        $fetcher = $this->factory->make('/var/www/myapp');
        $this->assertInstanceOf(LocalRepositoryFetcher::class, $fetcher);
    }

    public function testMakeReturnsLocalFetcherForWindowsAbsolutePath(): void
    {
        $fetcher = $this->factory->make('C:\\Projects\\myapp');
        $this->assertInstanceOf(LocalRepositoryFetcher::class, $fetcher);
    }

    public function testMakeReturnsLocalFetcherForWindowsPathWithForwardSlash(): void
    {
        $fetcher = $this->factory->make('D:/projects/myapp');
        $this->assertInstanceOf(LocalRepositoryFetcher::class, $fetcher);
    }

    public function testMakeReturnsLocalFetcherForExistingDirectory(): void
    {
        $fetcher = $this->factory->make(sys_get_temp_dir());
        $this->assertInstanceOf(LocalRepositoryFetcher::class, $fetcher);
    }

    // -------------------------------------------------------------------------
    // GitHub sources
    // -------------------------------------------------------------------------

    public function testMakeReturnsGitHubFetcherForGithubPrefix(): void
    {
        $fetcher = $this->factory->make('github:acme/myapp');
        $this->assertInstanceOf(GitHubRepositoryFetcher::class, $fetcher);
    }

    public function testMakeReturnsGitHubFetcherForGithubHttpsUrl(): void
    {
        $fetcher = $this->factory->make('https://github.com/acme/myapp.git');
        $this->assertInstanceOf(GitHubRepositoryFetcher::class, $fetcher);
    }

    // -------------------------------------------------------------------------
    // GitLab sources
    // -------------------------------------------------------------------------

    public function testMakeReturnsGitLabFetcherForGitlabPrefix(): void
    {
        $fetcher = $this->factory->make('gitlab:acme/myapp');
        $this->assertInstanceOf(GitLabRepositoryFetcher::class, $fetcher);
    }

    public function testMakeReturnsGitLabFetcherForGitlabHttpsUrl(): void
    {
        $fetcher = $this->factory->make('https://gitlab.com/acme/myapp.git');
        $this->assertInstanceOf(GitLabRepositoryFetcher::class, $fetcher);
    }

    // -------------------------------------------------------------------------
    // Unknown source
    // -------------------------------------------------------------------------

    public function testMakeThrowsRepositoryNotFoundForUnknownSource(): void
    {
        $this->expectException(RepositoryNotFoundException::class);
        $this->factory->make('bitbucket:acme/myapp');
    }
}

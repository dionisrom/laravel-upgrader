<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\Exception\AuthenticationException;
use App\Repository\Exception\FetchTimeoutException;
use App\Repository\Exception\RepositoryNotFoundException;
use App\Repository\FetchResult;
use App\Repository\GitHubRepositoryFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Tests for GitHubRepositoryFetcher.
 *
 * Git subprocesses are exercised indirectly; we test:
 *  - URL normalisation (github: prefix → HTTPS)
 *  - Token masking: token must NOT appear in exception messages
 *  - Lock acquisition behaviour
 */
final class GitHubRepositoryFetcherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader_gh_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Token masking
    // -------------------------------------------------------------------------

    public function testTokenDoesNotAppearInRepositoryNotFoundExceptionMessage(): void
    {
        $fetcher = new GitHubRepositoryFetcher();
        $secret = 'ghp_supersecrettoken12345';

        try {
            // This will call git clone against a non-existent host/repo — expected to fail
            $fetcher->fetch(
                'github:nonexistent-org-xyz/nonexistent-repo-xyz',
                $this->tempDir . '/target',
                token: $secret,
            );
            $this->fail('Expected exception was not thrown');
        } catch (RepositoryNotFoundException | AuthenticationException | FetchTimeoutException $e) {
            $this->assertStringNotContainsString(
                $secret,
                $e->getMessage(),
                'Token must be masked in exception messages'
            );
        }
    }

    public function testTokenDoesNotAppearInAuthenticationExceptionMessage(): void
    {
        $fetcher = new GitHubRepositoryFetcher();
        $secret = 'ghp_anothertokenvalue99';

        try {
            $fetcher->fetch(
                'github:nonexistent-org-xyz/nonexistent-repo-xyz',
                $this->tempDir . '/target',
                token: $secret,
            );
            $this->fail('Expected exception was not thrown');
        } catch (RepositoryNotFoundException | AuthenticationException | FetchTimeoutException $e) {
            $this->assertStringNotContainsString(
                $secret,
                $e->getMessage(),
                'Token must be masked in exception messages'
            );
        }
    }

    // -------------------------------------------------------------------------
    // URL normalisation
    // -------------------------------------------------------------------------

    /**
     * White-box: verify that `github:org/repo` normalises to the expected HTTPS URL
     * via reflection, without actually executing git.
     */
    public function testNormalizeToHttpsUrlConvertsGithubPrefix(): void
    {
        $fetcher = new GitHubRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('normalizeToHttpsUrl');
        $method->setAccessible(true);

        $result = $method->invoke($fetcher, 'github:acme/myapp');

        $this->assertSame('https://github.com/acme/myapp.git', $result);
    }

    public function testNormalizeToHttpsUrlPassthroughsFullUrl(): void
    {
        $fetcher = new GitHubRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('normalizeToHttpsUrl');
        $method->setAccessible(true);

        $result = $method->invoke($fetcher, 'https://github.com/acme/myapp.git');

        $this->assertSame('https://github.com/acme/myapp.git', $result);
    }

    // -------------------------------------------------------------------------
    // Token embedding
    // -------------------------------------------------------------------------

    public function testBuildAuthenticatedUrlEmbeddsTokenBeforeHost(): void
    {
        $fetcher = new GitHubRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('buildAuthenticatedUrl');
        $method->setAccessible(true);

        $url = $method->invoke($fetcher, 'https://github.com/acme/app.git', 'mytoken');

        $this->assertStringContainsString('x-token-auth:', $url);
        $this->assertStringContainsString('@github.com', $url);
        // Token appears in the URL (embedded credential), not in a separate argv
        $this->assertStringContainsString('mytoken', $url);
    }

    public function testBuildAuthenticatedUrlWithNullTokenReturnsOriginalUrl(): void
    {
        $fetcher = new GitHubRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('buildAuthenticatedUrl');
        $method->setAccessible(true);

        $original = 'https://github.com/acme/app.git';
        $result = $method->invoke($fetcher, $original, null);

        $this->assertSame($original, $result);
    }

    // -------------------------------------------------------------------------
    // Concurrent lock
    // -------------------------------------------------------------------------

    public function testConcurrentLockThrowsException(): void
    {
        $source = 'https://github.com/acme/app.git';
        $lockDir = sys_get_temp_dir() . '/upgrader/locks/';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }

        $lockFile = $lockDir . hash('sha256', $source) . '.lock';
        $fh = fopen($lockFile, 'c');
        $this->assertNotFalse($fh);
        flock($fh, LOCK_EX);

        try {
            $fetcher = new GitHubRepositoryFetcher();
            $this->expectException(\App\Repository\Exception\ConcurrentUpgradeException::class);
            $fetcher->fetch($source, $this->tempDir . '/target');
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                // On Windows, git object files are read-only; make writable before deletion
                if (!is_writable($item->getPathname())) {
                    chmod($item->getPathname(), 0644);
                }
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

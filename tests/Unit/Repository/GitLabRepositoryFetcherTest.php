<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\Exception\AuthenticationException;
use App\Repository\Exception\FetchTimeoutException;
use App\Repository\Exception\RepositoryNotFoundException;
use App\Repository\GitLabRepositoryFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GitLabRepositoryFetcher.
 *
 * Git subprocesses are exercised indirectly; we test:
 *  - URL normalisation (gitlab: prefix → HTTPS)
 *  - Token masking: token must NOT appear in exception messages
 *  - Lock acquisition behaviour
 */
final class GitLabRepositoryFetcherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader_gl_test_' . uniqid('', true);
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
        $fetcher = new GitLabRepositoryFetcher();
        $secret = 'glpat-supersecrettoken12345';

        try {
            $fetcher->fetch(
                'gitlab:nonexistent-org-xyz/nonexistent-repo-xyz',
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
        $fetcher = new GitLabRepositoryFetcher();
        $secret = 'glpat-anothertokenvalue99';

        try {
            $fetcher->fetch(
                'gitlab:nonexistent-org-xyz/nonexistent-repo-xyz',
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

    public function testNormalizeToHttpsUrlConvertsGitlabPrefix(): void
    {
        $fetcher = new GitLabRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('normalizeToHttpsUrl');
        $method->setAccessible(true);

        $result = $method->invoke($fetcher, 'gitlab:acme/myapp');

        $this->assertSame('https://gitlab.com/acme/myapp.git', $result);
    }

    public function testNormalizeToHttpsUrlPassthroughsFullUrl(): void
    {
        $fetcher = new GitLabRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('normalizeToHttpsUrl');
        $method->setAccessible(true);

        $result = $method->invoke($fetcher, 'https://gitlab.com/acme/myapp.git');

        $this->assertSame('https://gitlab.com/acme/myapp.git', $result);
    }

    // -------------------------------------------------------------------------
    // Token embedding
    // -------------------------------------------------------------------------

    public function testBuildAuthenticatedUrlEmbeddsTokenBeforeHost(): void
    {
        $fetcher = new GitLabRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('buildAuthenticatedUrl');
        $method->setAccessible(true);

        $url = $method->invoke($fetcher, 'https://gitlab.com/acme/app.git', 'mytoken');

        $this->assertStringContainsString('x-token-auth:', $url);
        $this->assertStringContainsString('@gitlab.com', $url);
        $this->assertStringContainsString('mytoken', $url);
    }

    public function testBuildAuthenticatedUrlWithNullTokenReturnsOriginalUrl(): void
    {
        $fetcher = new GitLabRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('buildAuthenticatedUrl');
        $method->setAccessible(true);

        $original = 'https://gitlab.com/acme/app.git';
        $result = $method->invoke($fetcher, $original, null);

        $this->assertSame($original, $result);
    }

    // -------------------------------------------------------------------------
    // Concurrent lock
    // -------------------------------------------------------------------------

    public function testConcurrentLockThrowsException(): void
    {
        $source = 'https://gitlab.com/acme/app.git';
        $lockDir = sys_get_temp_dir() . '/upgrader/locks/';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }

        $lockFile = $lockDir . hash('sha256', $source) . '.lock';
        $fh = fopen($lockFile, 'c');
        $this->assertNotFalse($fh);
        flock($fh, LOCK_EX);

        try {
            $fetcher = new GitLabRepositoryFetcher();
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

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

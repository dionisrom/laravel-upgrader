<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\Exception\ConcurrentUpgradeException;
use App\Repository\Exception\RepositoryNotFoundException;
use App\Repository\FetchResult;
use App\Repository\LocalRepositoryFetcher;
use PHPUnit\Framework\TestCase;

final class LocalRepositoryFetcherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testThrowsRepositoryNotFoundForNonExistentPath(): void
    {
        $fetcher = new LocalRepositoryFetcher();

        $this->expectException(RepositoryNotFoundException::class);
        $fetcher->fetch('/this/path/does/not/exist', $this->tempDir . '/target');
    }

    public function testFetchReturnsResultWithCorrectPaths(): void
    {
        $source = $this->createLocalGitRepo();
        $target = $this->tempDir . '/target';

        $fetcher = new LocalRepositoryFetcher();
        $result = $fetcher->fetch($source, $target);

        $this->assertInstanceOf(FetchResult::class, $result);
        $this->assertDirectoryExists($result->workspacePath);
        $this->assertNotEmpty($result->lockFilePath);
        $this->assertNotEmpty($result->defaultBranch);
        $this->assertNotEmpty($result->resolvedCommitSha);
    }

    public function testLockFilePathMatchesHashOfSource(): void
    {
        $source = $this->createLocalGitRepo();
        $target = $this->tempDir . '/target';

        $fetcher = new LocalRepositoryFetcher();
        $result = $fetcher->fetch($source, $target);

        $expectedLockFile = sys_get_temp_dir() . '/upgrader/locks/' . hash('sha256', $source) . '.lock';
        $this->assertSame($expectedLockFile, $result->lockFilePath);
    }

    public function testThrowsConcurrentUpgradeExceptionWhenLockHeld(): void
    {
        $source = $this->createLocalGitRepo();
        $lockDir = sys_get_temp_dir() . '/upgrader/locks/';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }

        $lockFile = $lockDir . hash('sha256', $source) . '.lock';
        $fh = fopen($lockFile, 'c');
        $this->assertNotFalse($fh);
        flock($fh, LOCK_EX);

        try {
            $fetcher = new LocalRepositoryFetcher();
            $this->expectException(ConcurrentUpgradeException::class);
            $this->expectExceptionMessage('An upgrade is already running for this repository. Use --resume to continue it, or wait for it to complete.');
            $fetcher->fetch($source, $this->tempDir . '/target2');
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function testTokenIsNotUsed(): void
    {
        // LocalRepositoryFetcher accepts token param but ignores it — no leakage possible
        $source = $this->createLocalGitRepo();
        $target = $this->tempDir . '/target';

        $fetcher = new LocalRepositoryFetcher();
        $result = $fetcher->fetch($source, $target, token: 'secret-token-value');

        $this->assertInstanceOf(FetchResult::class, $result);
        // Ensure token does not appear in any returned field
        $this->assertStringNotContainsString('secret-token-value', $result->workspacePath);
        $this->assertStringNotContainsString('secret-token-value', $result->lockFilePath);
        $this->assertStringNotContainsString('secret-token-value', $result->defaultBranch);
        $this->assertStringNotContainsString('secret-token-value', $result->resolvedCommitSha);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createLocalGitRepo(): string
    {
        $repoDir = $this->tempDir . '/source_repo';
        mkdir($repoDir, 0755, true);

        exec("git init {$repoDir} 2>&1");
        exec("git -C {$repoDir} config user.email test@test.com 2>&1");
        exec("git -C {$repoDir} config user.name Test 2>&1");
        file_put_contents($repoDir . '/README.md', '# Test');
        exec("git -C {$repoDir} add . 2>&1");
        exec("git -C {$repoDir} commit -m init 2>&1");

        return $repoDir;
    }

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

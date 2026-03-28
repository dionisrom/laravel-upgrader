<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Repository\Exception\AuthenticationException;
use App\Repository\Exception\ConcurrentUpgradeException;
use App\Repository\Exception\FetchTimeoutException;
use App\Repository\FetchResult;
use App\Repository\GitLabRepositoryFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class GitLabRepositoryFetcherTest extends TestCase
{
    private const CONCURRENT_UPGRADE_MESSAGE = 'An upgrade is already running for this repository. Use --resume to continue it, or wait for it to complete.';

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

    public function testNormalizeToHttpsUrlConvertsGitlabPrefix(): void
    {
        $fetcher = new GitLabRepositoryFetcher();

        $ref = new \ReflectionClass($fetcher);
        $method = $ref->getMethod('normalizeToHttpsUrl');
        $method->setAccessible(true);

        $result = $method->invoke($fetcher, 'gitlab:acme/myapp');

        self::assertSame('https://gitlab.com/acme/myapp.git', $result);
    }

    public function testFetchUsesAskPassWithoutLeakingTokenInCloneCommand(): void
    {
        $secret = 'glpat_supersecrettoken12345';
        $target = $this->tempDir . '/target';
        $commands = [];

        $clone = $this->createMock(Process::class);
        $clone->expects(self::once())->method('setTimeout')->with(120.0);
        $clone->expects(self::once())->method('setEnv')->with(self::callback(function (array $env) use ($secret): bool {
            self::assertSame('0', $env['GIT_TERMINAL_PROMPT']);
            self::assertSame($secret, $env['UPGRADER_GIT_PASSWORD']);
            self::assertArrayHasKey('GIT_ASKPASS', $env);
            self::assertStringNotContainsString($secret, $env['GIT_ASKPASS']);

            return true;
        }));
        $clone->expects(self::once())->method('run');
        $clone->method('isSuccessful')->willReturn(true);

        $branch = $this->successfulProcess('main', 30.0);
        $sha = $this->successfulProcess('abc123def', 30.0);

        $fetcher = new GitLabRepositoryFetcher($this->queueFactory($commands, $clone, $branch, $sha));
        $result = $fetcher->fetch('gitlab:acme/myapp', $target, $secret);

        self::assertInstanceOf(FetchResult::class, $result);
        self::assertSame($target, $result->workspacePath);
        self::assertSame('main', $result->defaultBranch);
        self::assertSame('abc123def', $result->resolvedCommitSha);
        self::assertCount(3, $commands);
        self::assertSame(
            ['git', 'clone', '--depth=1', '--single-branch', 'https://oauth2@gitlab.com/acme/myapp.git', $target],
            $commands[0]['command']
        );
        self::assertStringNotContainsString($secret, implode(' ', $commands[0]['command']));
    }

    public function testAuthenticationExceptionMasksTokenFromErrorOutput(): void
    {
        $secret = 'glpat_secret_value_999';
        $clone = $this->createMock(Process::class);
        $clone->expects(self::once())->method('setTimeout')->with(120.0);
        $clone->expects(self::once())->method('setEnv');
        $clone->expects(self::once())->method('run');
        $clone->method('isSuccessful')->willReturn(false);
        $clone->method('getErrorOutput')->willReturn("Authentication failed for {$secret}");

        $commands = [];
        $fetcher = new GitLabRepositoryFetcher($this->queueFactory($commands, $clone));

        try {
            $fetcher->fetch('gitlab:acme/myapp', $this->tempDir . '/target', $secret);
            self::fail('Expected AuthenticationException was not thrown.');
        } catch (AuthenticationException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringContainsString('***', $e->getMessage());
        }
    }

    public function testCloneTimeoutThrowsFetchTimeoutException(): void
    {
        $clone = $this->createMock(Process::class);
        $clone->expects(self::once())->method('setTimeout')->with(120.0);
        $clone->expects(self::once())->method('setEnv');
        $clone->expects(self::once())->method('run')->willThrowException(
            new ProcessTimedOutException(new Process([PHP_BINARY, '-r', 'exit(0);']), ProcessTimedOutException::TYPE_GENERAL)
        );

        $commands = [];
        $fetcher = new GitLabRepositoryFetcher($this->queueFactory($commands, $clone));

        $this->expectException(FetchTimeoutException::class);
        $this->expectExceptionMessage('Git clone timed out after 120 seconds.');
        $fetcher->fetch('gitlab:acme/myapp', $this->tempDir . '/target', 'glpat_timeout_token');
    }

    public function testConcurrentLockThrowsExactTrdMessage(): void
    {
        $source = 'https://gitlab.com/acme/app.git';
        $lockDir = sys_get_temp_dir() . '/upgrader/locks/';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }

        $lockFile = $lockDir . hash('sha256', $source) . '.lock';
        $fh = fopen($lockFile, 'c');
        self::assertNotFalse($fh);
        flock($fh, LOCK_EX);

        try {
            $fetcher = new GitLabRepositoryFetcher();
            $this->expectException(ConcurrentUpgradeException::class);
            $this->expectExceptionMessage(self::CONCURRENT_UPGRADE_MESSAGE);
            $fetcher->fetch($source, $this->tempDir . '/target');
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param array<int, array{command: array<int, string>, cwd: ?string}> $commands
     */
    private function queueFactory(array &$commands, Process ...$processes): \Closure
    {
        $index = 0;

        return static function (array $command, ?string $cwd = null) use (&$commands, &$index, $processes): Process {
            $commands[] = [
                'command' => $command,
                'cwd' => $cwd,
            ];

            if (!isset($processes[$index])) {
                throw new \RuntimeException('Unexpected process creation.');
            }

            return $processes[$index++];
        };
    }

    private function successfulProcess(string $output, float $timeout): Process
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('setTimeout')->with($timeout);
        $process->expects(self::once())->method('run');
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($output);
        $process->method('getErrorOutput')->willReturn('');

        return $process;
    }

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
                if (!is_writable($item->getPathname())) {
                    chmod($item->getPathname(), 0644);
                }
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

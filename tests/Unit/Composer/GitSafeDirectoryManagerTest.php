<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use AppContainer\Composer\GitSafeDirectoryManager;
use PHPUnit\Framework\TestCase;

final class GitSafeDirectoryManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/git-safe-dir-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function testDiscoverGitRepositoryDirectoriesFindsBareAndWorktreeRepos(): void
    {
        $cacheDir = $this->tmpDir . '/cache';
        $bareRepo = $cacheDir . '/vcs/private-package.git';
        $worktreeRepo = $cacheDir . '/clones/local-package';

        mkdir($bareRepo . '/objects', 0777, true);
        file_put_contents($bareRepo . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($bareRepo . '/config', "[core]\n\trepositoryformatversion = 0\n");

        mkdir($worktreeRepo . '/.git', 0777, true);

        $manager = new GitSafeDirectoryManager();

        $discovered = array_map([$this, 'normalizePath'], $manager->discoverGitRepositoryDirectories($cacheDir));

        self::assertSame(
            [$this->normalizePath($worktreeRepo), $this->normalizePath($bareRepo)],
            $discovered,
        );
    }

    public function testMarkComposerCacheDirectoriesMarksRootAndNestedRepositories(): void
    {
        $cacheDir = $this->tmpDir . '/cache';
        $bareRepo = $cacheDir . '/vcs/private-package.git';

        mkdir($bareRepo . '/objects', 0777, true);
        file_put_contents($bareRepo . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($bareRepo . '/config', "[core]\n\trepositoryformatversion = 0\n");

        $commands = [];
        $manager = new GitSafeDirectoryManager(static function (array $command) use (&$commands): void {
            $commands[] = $command;
        });

        $manager->markComposerCacheDirectories($cacheDir);

        $normalizedCommands = array_map(function (array $command): array {
            $command[5] = $this->normalizePath($command[5]);
            return $command;
        }, $commands);

        self::assertSame([
            ['git', 'config', '--global', '--add', 'safe.directory', $this->normalizePath($cacheDir)],
            ['git', 'config', '--global', '--add', 'safe.directory', $this->normalizePath($bareRepo)],
        ], $normalizedCommands);
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
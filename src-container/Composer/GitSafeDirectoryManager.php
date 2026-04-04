<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use Symfony\Component\Process\Process;

final class GitSafeDirectoryManager
{
    /** @var \Closure(list<string>): void */
    private readonly \Closure $commandRunner;

    public function __construct(?callable $commandRunner = null)
    {
        $this->commandRunner = $commandRunner !== null
            ? $commandRunner(...)
            : function (array $command): void {
                $process = new Process($command, timeout: 30);
                $process->run();
            };
    }

    public function markDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        ($this->commandRunner)(['git', 'config', '--global', '--add', 'safe.directory', $path]);
    }

    public function markComposerCacheDirectories(string $cacheDir): void
    {
        if (!is_dir($cacheDir)) {
            return;
        }

        $this->markDirectory($cacheDir);

        foreach ($this->discoverGitRepositoryDirectories($cacheDir) as $repositoryDir) {
            $this->markDirectory($repositoryDir);
        }
    }

    /**
     * @return list<string>
     */
    public function discoverGitRepositoryDirectories(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $repositories = [];

        if ($this->isGitRepositoryDirectory($root)) {
            $repositories[$root] = true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                continue;
            }

            $path = $item->getPathname();

            if ($this->isGitRepositoryDirectory($path)) {
                $repositories[$path] = true;
            }
        }

        ksort($repositories);

        return array_keys($repositories);
    }

    private function isGitRepositoryDirectory(string $path): bool
    {
        if (is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
            return true;
        }

        return is_file($path . DIRECTORY_SEPARATOR . 'HEAD')
            && is_file($path . DIRECTORY_SEPARATOR . 'config')
            && is_dir($path . DIRECTORY_SEPARATOR . 'objects');
    }
}
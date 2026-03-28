<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use Symfony\Component\Process\Process;

final class RepositoryCachePrimer
{
    public function __construct(
        private readonly GitSafeDirectoryManager $safeDirectoryManager = new GitSafeDirectoryManager(),
    ) {}

    /**
     * Warm Composer's cache for private VCS repositories in a separate,
     * network-enabled container invocation before the isolated hop runs.
     */
    public function prime(string $workspacePath): void
    {
        if (!is_dir($workspacePath)) {
            throw new \RuntimeException("Workspace not found: {$workspacePath}");
        }

        $cacheDir = getenv('COMPOSER_CACHE_DIR');
        if (!is_string($cacheDir) || trim($cacheDir) === '') {
            throw new \RuntimeException('COMPOSER_CACHE_DIR is required for repository cache priming.');
        }

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true)) {
            throw new \RuntimeException("Failed to create Composer cache directory: {$cacheDir}");
        }

        $this->safeDirectoryManager->markDirectory($workspacePath);
        $this->safeDirectoryManager->markComposerCacheDirectories($cacheDir);

        $process = new Process(
            command: [
                'composer',
                'install',
                '--no-interaction',
                '--prefer-dist',
                '--no-scripts',
                '--ignore-platform-reqs',
            ],
            cwd: $workspacePath,
            timeout: 600,
        );

        $process->setEnv(['COMPOSER_CACHE_DIR' => $cacheDir]);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        if ($errorOutput === '') {
            $errorOutput = trim($process->getOutput());
        }

        throw new \RuntimeException($errorOutput !== '' ? $errorOutput : 'Composer cache priming failed.');
    }
}

if (isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';

    if (!file_exists($autoloader)) {
        fwrite(STDERR, "Autoloader not found: {$autoloader}\n");
        exit(2);
    }

    require_once $autoloader;

    if (!isset($argv[1])) {
        fwrite(STDERR, "Usage: php RepositoryCachePrimer.php <workspace_path>\n");
        exit(2);
    }

    try {
        (new RepositoryCachePrimer())->prime(rtrim($argv[1], '/'));
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
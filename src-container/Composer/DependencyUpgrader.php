<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use AppContainer\Composer\Exception\DependencyBlockerException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class DependencyUpgrader
{
    private const FRAMEWORK_PACKAGE = 'laravel/framework';
    private const FRAMEWORK_TARGET  = '^9.0';

    public function __construct(
        private readonly CompatibilityChecker $compatibilityChecker,
        private readonly ConflictResolver $conflictResolver,
        private readonly string $frameworkPackage = self::FRAMEWORK_PACKAGE,
        private readonly string $frameworkTarget = self::FRAMEWORK_TARGET,
        private readonly GitSafeDirectoryManager $safeDirectoryManager = new GitSafeDirectoryManager(),
    ) {}

    /**
     * @throws DependencyBlockerException when critical blockers exist and $ignoreBlockers is false
     * @throws \RuntimeException on I/O or composer failures
     */
    public function upgrade(string $workspacePath, bool $ignoreBlockers = false): UpgradeResult
    {
        $composerJsonPath = rtrim($workspacePath, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';

        $this->emit('composer.started', ['workspace' => $workspacePath]);

        // 1. Read composer.json
        $composerData = $this->readComposerJson($composerJsonPath);

        // 2. Collect all packages from require + require-dev
        /** @var array<string, string> $require */
        $require = $composerData['require'] ?? [];
        /** @var array<string, string> $requireDev */
        $requireDev = $composerData['require-dev'] ?? [];

        $allPackages = array_merge($require, $requireDev);

        // 3. Check compatibility for each package; collect blockers and cache results
        /** @var DependencyBlocker[] $blockers */
        $blockers = [];
        /** @var array<string, PackageCompatibility> $compatCache */
        $compatCache = [];

        foreach ($allPackages as $package => $constraint) {
            // Skip platform requirements and laravel/framework itself (handled separately)
            if ($this->isPlatformRequirement($package) || $package === $this->frameworkPackage) {
                continue;
            }

            $compatibility = $this->compatibilityChecker->check($package, $constraint);
            $compatCache[$package] = $compatibility;

            if ($compatibility->isBlocker()) {
                $blocker = new DependencyBlocker(
                    package: $package,
                    severity: 'critical',
                    reason: $compatibility->notes !== ''
                        ? $compatibility->notes
                        : "Package {$package} does not support Laravel 9.",
                    recommendedVersion: $compatibility->recommendedVersion,
                );
                $blockers[] = $blocker;
                $this->emit('dependency_blocker', [
                    'package'  => $blocker->package,
                    'severity' => $blocker->severity,
                    'reason'   => $blocker->reason,
                ]);
            } elseif ($compatibility->isUnknown()) {
                $blocker = new DependencyBlocker(
                    package: $package,
                    severity: 'warning',
                    reason: $compatibility->notes !== ''
                        ? $compatibility->notes
                        : "Package {$package} has unknown Laravel 9 compatibility.",
                    recommendedVersion: $compatibility->recommendedVersion,
                );
                $blockers[] = $blocker;
                $this->emit('dependency_blocker', [
                    'package'  => $blocker->package,
                    'severity' => $blocker->severity,
                    'reason'   => $blocker->reason,
                ]);
            }
        }

        // 4. Resolve blockers (throws DependencyBlockerException on critical + not ignoring)
        $this->conflictResolver->resolve($blockers, $ignoreBlockers);

        // 5. Apply version bumps
        $packagesUpdated = 0;

        // Always bump laravel/framework
        if (isset($composerData['require'][$this->frameworkPackage])) {
            $composerData['require'][$this->frameworkPackage] = $this->frameworkTarget;
            $packagesUpdated++;
        }

        // Apply recommended versions for known-compatible packages (using cached results)
        foreach (['require', 'require-dev'] as $section) {
            if (!isset($composerData[$section]) || !is_array($composerData[$section])) {
                continue;
            }

            foreach ($composerData[$section] as $package => $constraint) {
                if ($this->isPlatformRequirement($package) || $package === $this->frameworkPackage) {
                    continue;
                }

                $compatibility = $compatCache[$package] ?? $this->compatibilityChecker->check($package, $constraint);

                if ($compatibility->support === true && $compatibility->recommendedVersion !== null) {
                    $composerData[$section][$package] = $compatibility->recommendedVersion;
                    $packagesUpdated++;
                }
            }
        }

        // 6. Disable classmap optimization to avoid touch() permission errors
        //    on Windows-mounted Docker volumes (e.g. NTFS via Docker Desktop).
        //    The config key is stripped rather than set to false so the output
        //    composer.json stays clean.
        if (isset($composerData['config']['optimize-autoloader'])) {
            unset($composerData['config']['optimize-autoloader']);
        }

        // 7. Write composer.json atomically
        $this->writeComposerJsonAtomic($composerJsonPath, $composerData);

        // 7. Run composer install
        $this->emit('composer.install_started', []);

        try {
            $this->runComposerInstall($workspacePath);
        } catch (\RuntimeException $e) {
            $this->emit('composer_install_failed', ['message' => $e->getMessage()]);
            return UpgradeResult::failure($e->getMessage(), $blockers);
        }

        $this->emit('composer.completed', ['packages_updated' => $packagesUpdated]);

        return UpgradeResult::success($packagesUpdated, $blockers);
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("composer.json not found at: {$path}");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Could not read composer.json at: {$path}");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Invalid JSON in composer.json: ' . json_last_error_msg()
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('composer.json root must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJsonAtomic(string $path, array $data): void
    {
        // Composer requires certain keys to be JSON objects, not arrays.
        // PHP's json_encode turns empty associative arrays into [] instead of {}.
        $objectKeys = ['require', 'require-dev', 'autoload', 'autoload-dev', 'config', 'extra', 'scripts'];
        foreach ($objectKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && empty($data[$key])) {
                $data[$key] = new \stdClass();
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('Could not encode composer.json to JSON: ' . json_last_error_msg());
        }

        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmpPath, $json) === false) {
            throw new \RuntimeException("Could not write temporary composer.json to: {$tmpPath}");
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Could not atomically rename {$tmpPath} to {$path}");
        }
    }

    private function runComposerInstall(string $workspacePath): void
    {
        $this->markWorkspaceAsSafeDirectory($workspacePath);

        // Remove stale lock file — the constraints were just modified, so the
        // old lock is incompatible. Removing it lets `composer install` perform
        // a full resolution as required by TRD-COMP-003.
        $lockFile = rtrim($workspacePath, '/\\') . DIRECTORY_SEPARATOR . 'composer.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        [$composerEnv, $cleanupCacheDir] = $this->prepareComposerEnvironment();

        $process = new Process(
            command: ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-scripts'],
            cwd: $workspacePath,
            timeout: 300,
        );

        if ($composerEnv !== []) {
            $process->setEnv($composerEnv);
        }

        $process->run();

        if ($cleanupCacheDir !== null) {
            $this->removeDirectory($cleanupCacheDir);
        }

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'composer install failed: ' . $process->getErrorOutput()
            );
        }
    }

    /**
     * @return array{array<string, string>, string|null}
     */
    private function prepareComposerEnvironment(): array
    {
        $extraCacheDir = getenv('UPGRADER_EXTRA_COMPOSER_CACHE_DIR');
        if (!is_string($extraCacheDir) || trim($extraCacheDir) === '' || !is_dir($extraCacheDir)) {
            return [[], null];
        }

        $mergedCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upgrader-composer-cache-' . bin2hex(random_bytes(4));
        if (!mkdir($mergedCacheDir, 0700, true) && !is_dir($mergedCacheDir)) {
            throw new \RuntimeException("Failed to create merged Composer cache directory: {$mergedCacheDir}");
        }

        $defaultCacheDir = $this->defaultComposerCacheDir();
        $this->mergeDirectoryInto($defaultCacheDir, $mergedCacheDir);
        $this->mergeDirectoryInto($extraCacheDir, $mergedCacheDir);
        $this->markComposerCacheAsSafeDirectory($mergedCacheDir);

        return [['COMPOSER_CACHE_DIR' => $mergedCacheDir], $mergedCacheDir];
    }

    private function defaultComposerCacheDir(): string
    {
        $composerHome = getenv('COMPOSER_HOME');
        if (!is_string($composerHome) || trim($composerHome) === '') {
            $home = getenv('HOME');
            $composerHome = is_string($home) && trim($home) !== ''
                ? rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.composer'
                : '/home/upgrader/.composer';
        }

        return rtrim($composerHome, '/\\') . DIRECTORY_SEPARATOR . 'cache';
    }

    private function markWorkspaceAsSafeDirectory(string $workspacePath): void
    {
        $this->safeDirectoryManager->markDirectory($workspacePath);
    }

    private function markComposerCacheAsSafeDirectory(string $cacheDir): void
    {
        $this->safeDirectoryManager->markComposerCacheDirectories($cacheDir);
    }

    private function mergeDirectoryInto(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($sourceDir)) {
            return;
        }

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0700, true)) {
                    throw new \RuntimeException("Failed to create Composer cache directory: {$targetPath}");
                }

                continue;
            }

            $parentDir = dirname($targetPath);
            if (!is_dir($parentDir) && !mkdir($parentDir, 0700, true)) {
                throw new \RuntimeException("Failed to create Composer cache parent directory: {$parentDir}");
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new \RuntimeException("Failed to copy Composer cache file: {$item->getPathname()}");
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function isPlatformRequirement(string $package): bool
    {
        // Platform requirements: php, ext-*, lib-*, php-64bit, etc.
        return str_starts_with($package, 'ext-')
            || str_starts_with($package, 'lib-')
            || $package === 'php'
            || $package === 'php-64bit'
            || $package === 'hhvm';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $type, array $data): void
    {
        echo json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_SLASHES) . "\n";
    }
}

// ─── CLI entry point ──────────────────────────────────────────────────────────
// Invoked by hop entrypoints via: php DependencyUpgrader.php <workspace> [--framework-target=^X.0] [--compatibility=/path/to/file.json]
if (isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    // Bootstrap the Composer autoloader.
    // Container layout: /upgrader/src/Composer/DependencyUpgrader.php → autoloader at /upgrader/vendor/autoload.php
    $autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';

    if (!file_exists($autoloader)) {
        $payload = ['event' => 'config_error', 'error' => "Autoloader not found: {$autoloader}"];
        echo json_encode($payload) . "\n";
        exit(2);
    }

    require_once $autoloader;

    if (!isset($argv[1])) {
        fwrite(STDERR, "Usage: php DependencyUpgrader.php <workspace_path> [--framework-target=^9.0] [--compatibility=/path/to/file.json]\n");
        exit(2);
    }

    $workspacePath     = rtrim($argv[1], '/');
    $frameworkTarget   = null;
    $compatibilityFile = null;

    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, '--framework-target=')) {
            $frameworkTarget = substr($arg, strlen('--framework-target='));
        } elseif (str_starts_with($arg, '--compatibility=')) {
            $compatibilityFile = substr($arg, strlen('--compatibility='));
        }
    }

    if (!is_dir($workspacePath)) {
        $payload = ['event' => 'config_error', 'error' => "Workspace not found: {$workspacePath}"];
        echo json_encode($payload) . "\n";
        exit(2);
    }

    $checker  = new CompatibilityChecker($compatibilityFile);
    $resolver = new ConflictResolver();

    $upgrader = $frameworkTarget !== null
        ? new DependencyUpgrader($checker, $resolver, frameworkTarget: $frameworkTarget)
        : new DependencyUpgrader($checker, $resolver);

    try {
        $result = $upgrader->upgrade($workspacePath);
    } catch (Exception\DependencyBlockerException $e) {
        $payload = [
            'event'    => 'dependency_error',
            'error'    => $e->getMessage(),
            'blockers' => array_map(
                static fn(DependencyBlocker $b) => ['package' => $b->package, 'reason' => $b->reason],
                $e->getBlockers(),
            ),
        ];
        echo json_encode($payload) . "\n";
        exit(1);
    }

    if (!$result->success) {
        $payload = ['event' => 'dependency_error', 'error' => $result->errorMessage ?? 'Unknown dependency upgrade failure'];
        echo json_encode($payload) . "\n";
        exit(1);
    }

    exit(0);
}

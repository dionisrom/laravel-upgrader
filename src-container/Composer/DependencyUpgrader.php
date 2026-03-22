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

        // 3. Check compatibility for each package; collect blockers
        /** @var DependencyBlocker[] $blockers */
        $blockers = [];

        foreach ($allPackages as $package => $constraint) {
            // Skip platform requirements and laravel/framework itself (handled separately)
            if ($this->isPlatformRequirement($package) || $package === self::FRAMEWORK_PACKAGE) {
                continue;
            }

            $compatibility = $this->compatibilityChecker->check($package, $constraint);

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
        if (isset($composerData['require'][self::FRAMEWORK_PACKAGE])) {
            $composerData['require'][self::FRAMEWORK_PACKAGE] = self::FRAMEWORK_TARGET;
            $packagesUpdated++;
        }

        // Apply recommended versions for known-compatible packages
        foreach (['require', 'require-dev'] as $section) {
            if (!isset($composerData[$section]) || !is_array($composerData[$section])) {
                continue;
            }

            foreach ($composerData[$section] as $package => $constraint) {
                if ($this->isPlatformRequirement($package) || $package === self::FRAMEWORK_PACKAGE) {
                    continue;
                }

                $compatibility = $this->compatibilityChecker->check($package, $constraint);

                if ($compatibility->l9Support === true && $compatibility->recommendedVersion !== null) {
                    $composerData[$section][$package] = $compatibility->recommendedVersion;
                    $packagesUpdated++;
                }
            }
        }

        // 6. Write composer.json atomically
        $this->writeComposerJsonAtomic($composerJsonPath, $composerData);

        // 7. Run composer install
        $this->emit('composer.install_started', []);

        try {
            $this->runComposerInstall($workspacePath);
        } catch (\RuntimeException $e) {
            $this->emit('composer.failed', ['message' => $e->getMessage()]);
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
        $process = new Process(
            command: ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-scripts'],
            cwd: $workspacePath,
            timeout: 300,
        );

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'composer install failed: ' . $process->getErrorOutput()
            );
        }
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

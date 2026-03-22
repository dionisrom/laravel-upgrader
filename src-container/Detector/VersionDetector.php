<?php

declare(strict_types=1);

namespace AppContainer\Detector;

use AppContainer\Detector\Exception\DetectionException;
use AppContainer\Detector\Exception\InvalidHopException;

final class VersionDetector
{
    private const SUPPORTED_MAJORS = [8, 9];

    private const LARAVEL_PACKAGES = [
        'laravel/framework',
        'laravel/lumen-framework',
    ];

    /**
     * Reads composer.lock to find the installed Laravel or Lumen version.
     * Returns e.g. "8.83.27" for Laravel 8, "9.52.15" for Laravel 9.
     * Throws InvalidHopException if version is outside supported range.
     */
    public function detectLaravelVersion(string $workspacePath): string
    {
        $lockFile = $workspacePath . '/composer.lock';

        if (!file_exists($lockFile)) {
            throw new DetectionException(
                "composer.lock not found at: {$lockFile}"
            );
        }

        $contents = file_get_contents($lockFile);

        if ($contents === false) {
            throw new DetectionException(
                "Failed to read composer.lock at: {$lockFile}"
            );
        }

        $lock = json_decode($contents, true);

        if (!is_array($lock) || !isset($lock['packages']) || !is_array($lock['packages'])) {
            throw new DetectionException(
                'composer.lock is malformed or missing "packages" key'
            );
        }

        foreach (self::LARAVEL_PACKAGES as $packageName) {
            /** @var array{name?: string, version?: string} $package */
            foreach ($lock['packages'] as $package) {
                if (!isset($package['name'], $package['version'])) {
                    continue;
                }

                if ($package['name'] === $packageName) {
                    $version = ltrim($package['version'], 'v');
                    $this->assertVersionSupported($version, $packageName);

                    return $version;
                }
            }
        }

        throw new DetectionException(
            'Neither laravel/framework nor laravel/lumen-framework found in composer.lock'
        );
    }

    /**
     * Reads composer.json "require.php" constraint.
     * Returns e.g. "^8.0" or ">=8.1".
     */
    public function detectPhpConstraint(string $workspacePath): string
    {
        $composerFile = $workspacePath . '/composer.json';

        if (!file_exists($composerFile)) {
            throw new DetectionException(
                "composer.json not found at: {$composerFile}"
            );
        }

        $contents = file_get_contents($composerFile);

        if ($contents === false) {
            throw new DetectionException(
                "Failed to read composer.json at: {$composerFile}"
            );
        }

        /** @var array{require?: array<string, string>} $composer */
        $composer = json_decode($contents, true);

        if (!is_array($composer)) {
            throw new DetectionException('composer.json is malformed');
        }

        if (!isset($composer['require']['php'])) {
            throw new DetectionException(
                'No PHP version constraint found in composer.json require section'
            );
        }

        return (string) $composer['require']['php'];
    }

    private function assertVersionSupported(string $version, string $packageName): void
    {
        $parts = explode('.', $version);

        if (!isset($parts[0]) || !is_numeric($parts[0])) {
            throw new DetectionException(
                "Could not parse major version from: {$version}"
            );
        }

        $major = (int) $parts[0];

        if (!in_array($major, self::SUPPORTED_MAJORS, true)) {
            throw new InvalidHopException(
                "Detected Laravel {$major}.x (via {$packageName}). "
                . 'This tool supports L8→L9 in Phase 1 only.'
            );
        }
    }
}

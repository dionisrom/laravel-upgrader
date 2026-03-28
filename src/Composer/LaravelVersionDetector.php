<?php

declare(strict_types=1);

namespace App\Composer;

/**
 * Detects the installed Laravel major version from composer.lock on the host side.
 * This is a lightweight duplicated reader (the container has its own VersionDetector).
 */
final class LaravelVersionDetector
{
    private const LARAVEL_PACKAGES = [
        'laravel/framework',
        'laravel/lumen-framework',
    ];

    /**
     * Reads the workspace composer.lock and returns the major version as a string (e.g. "8", "9").
     * Returns null if detection fails (no lock file, no Laravel package found).
     */
    public function detect(string $workspacePath): ?string
    {
        $lockFile = $workspacePath . '/composer.lock';

        if (!file_exists($lockFile)) {
            return null;
        }

        $contents = file_get_contents($lockFile);
        if ($contents === false) {
            return null;
        }

        $lock = json_decode($contents, true);
        if (!is_array($lock) || !isset($lock['packages']) || !is_array($lock['packages'])) {
            return null;
        }

        foreach ($lock['packages'] as $package) {
            if (!is_array($package) || !isset($package['name'], $package['version'])) {
                continue;
            }

            if (in_array($package['name'], self::LARAVEL_PACKAGES, true)) {
                $version = ltrim((string) $package['version'], 'v');
                $major = explode('.', $version)[0] ?? null;

                if ($major !== null && ctype_digit($major)) {
                    return $major;
                }
            }
        }

        return null;
    }
}

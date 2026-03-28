<?php

declare(strict_types=1);

namespace App\Composer;

/**
 * Value object produced by the host-side detection scanner.
 *
 * Holds the map of all installed Composer packages and their versions,
 * parsed from the target project's composer.lock file.
 * Used by PackageRuleActivator to determine which Rector rule sets to activate.
 */
final readonly class ComposerLockAnalysis
{
    /**
     * @param array<string, string> $installedPackages Map of package-name → version string
     *                                                  e.g. ['livewire/livewire' => 'v2.12.0']
     */
    public function __construct(
        public array $installedPackages,
    ) {
    }

    /**
     * Parse a composer.lock file and return a ComposerLockAnalysis instance.
     *
     * @throws \RuntimeException if the file cannot be read or contains invalid JSON
     */
    public static function fromLockFile(string $lockFilePath): self
    {
        if (! is_file($lockFilePath)) {
            return new self([]);
        }

        $contents = file_get_contents($lockFilePath);
        if ($contents === false) {
            return new self([]);
        }

        /** @var array{
         *   packages?: list<array{name?: string, version?: string}>,
         *   packages-dev?: list<array{name?: string, version?: string}>
         * }|null $lock
         */
        $lock = json_decode($contents, true);
        if (! is_array($lock)) {
            return new self([]);
        }

        $packages = [];

        foreach ($lock['packages'] ?? [] as $package) {
            if (isset($package['name'], $package['version'])) {
                $packages[$package['name']] = $package['version'];
            }
        }

        foreach ($lock['packages-dev'] ?? [] as $package) {
            if (isset($package['name'], $package['version'])) {
                $packages[$package['name']] = $package['version'];
            }
        }

        return new self($packages);
    }

    /**
     * Whether a given package is present in this lock file.
     */
    public function hasPackage(string $packageName): bool
    {
        return isset($this->installedPackages[$packageName]);
    }

    /**
     * Return the installed version of a package, or null if not present.
     */
    public function getVersion(string $packageName): ?string
    {
        return $this->installedPackages[$packageName] ?? null;
    }
}

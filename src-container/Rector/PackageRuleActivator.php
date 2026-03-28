<?php

declare(strict_types=1);

namespace AppContainer\Rector;

/**
 * PackageRuleActivator — Phase 2 full implementation (P2-06).
 *
 * Reads the target project's composer.lock, detects which known packages
 * are installed, resolves the applicable Rector rule classes for the current
 * hop via {@see PackageVersionMatrix}, and returns them for the runner to apply.
 *
 * Rules are only activated when the package is present in composer.lock.
 * Unknown/unsupported package versions emit stderr warnings (not errors).
 */
final class PackageRuleActivator
{
    /**
     * Known packages that may have dedicated Rector rule sets.
     *
     * @var list<string>
     */
    private const KNOWN_PACKAGES = [
        'spatie/laravel-permission',
        'spatie/laravel-medialibrary',
        'spatie/laravel-activitylog',
        'livewire/livewire',
        'laravel/sanctum',
        'laravel/passport',
        'filament/filament',
        'laravel/nova',
        'laravel/horizon',
    ];

    public function __construct(private readonly PackageVersionMatrix $versionMatrix)
    {
    }

    /**
     * Detect installed packages and return a list of Rector rule class-strings to activate.
     *
     * @param string $composerLockPath Absolute path to the target project's composer.lock
     * @param string $hop              The upgrade hop identifier, e.g. "9-to-10"
     * @return list<string>            FQCN Rector rule class-strings to merge into the hop config
     */
    public function activate(string $composerLockPath, string $hop): array
    {
        $installedPackages = $this->readInstalledPackages($composerLockPath);

        if ($installedPackages === []) {
            return [];
        }

        $ruleClasses = [];

        foreach (self::KNOWN_PACKAGES as $packageName) {
            if (! isset($installedPackages[$packageName])) {
                continue;
            }

            $installedVersion = $installedPackages[$packageName];

            $this->versionMatrix->warnIfUnsupported($packageName, $installedVersion, $hop);

            $rules = $this->versionMatrix->getRules($packageName, $installedVersion, $hop);

            foreach ($rules as $ruleClass) {
                $ruleClasses[] = $ruleClass;
            }
        }

        if ($ruleClasses !== []) {
            fwrite(
                STDERR,
                sprintf(
                    '[PackageRuleActivator] hop=%s activated %d package rule(s): %s%s',
                    $hop,
                    count($ruleClasses),
                    implode(', ', $ruleClasses),
                    PHP_EOL,
                ),
            );
        }

        return $ruleClasses;
    }

    /**
     * Read all package names and their versions from composer.lock.
     *
     * @return array<string, string> Map of package-name → installed-version
     */
    private function readInstalledPackages(string $composerLockPath): array
    {
        if (! is_file($composerLockPath)) {
            return [];
        }

        $contents = file_get_contents($composerLockPath);
        if ($contents === false) {
            return [];
        }

        /**
         * @var array{
         *   packages?: list<array{name?: string, version?: string}>,
         *   packages-dev?: list<array{name?: string, version?: string}>
         * }|null $lock
         */
        $lock = json_decode($contents, true);
        if (! is_array($lock)) {
            return [];
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

        return $packages;
    }
}

<?php

declare(strict_types=1);

namespace App\Package;

use App\Composer\ComposerLockAnalysis;

/**
 * Host-side package rule activator (TRD §18).
 *
 * Detects which known packages are installed in the target project, resolves
 * the applicable Rector config file paths for the current hop via
 * {@see PackageVersionMatrix}, and returns them so the hop orchestrator can
 * pass them to the Rector subprocess.
 *
 * Rules are only activated when the package is present in composer.lock.
 * Unknown/unsupported package versions emit PHP warnings (not errors).
 *
 * Note: TRD §18 specifies `activate()` returning `RectorConfig[]`. This
 * implementation deliberately returns `list<string>` (absolute file paths)
 * because Rector is invoked as a subprocess — loading RectorConfig objects
 * in-process would violate the subprocess isolation constraint. The host
 * passes config file paths to the container via CLI arguments.
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

    public function __construct(
        private readonly PackageVersionMatrix $versionMatrix,
        private readonly string $workspaceRoot,
    ) {
    }

    /**
     * Detect installed packages and return a list of Rector config file paths to merge.
     *
     * @param ComposerLockAnalysis $lock       The parsed composer.lock of the target project
     * @param string               $hopVersion The upgrade hop identifier, e.g. "9-to-10"
     * @return list<string>                    Absolute paths to package-specific Rector configs
     */
    public function activate(ComposerLockAnalysis $lock, string $hopVersion): array
    {
        $configPaths = [];

        foreach (self::KNOWN_PACKAGES as $packageName) {
            $installedVersion = $lock->getVersion($packageName);

            if ($installedVersion === null) {
                continue;
            }

            $this->versionMatrix->warnIfUnsupported($packageName, $installedVersion, $hopVersion);

            $configPath = $this->versionMatrix->getRectorConfigPath(
                $packageName,
                $installedVersion,
                $hopVersion,
                $this->workspaceRoot,
            );

            if ($configPath !== null && ! in_array($configPath, $configPaths, true)) {
                $configPaths[] = $configPath;
            }
        }

        return $configPaths;
    }
}

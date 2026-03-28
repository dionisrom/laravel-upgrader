<?php

declare(strict_types=1);

namespace App\Package;

/**
 * Host-side package version matrix.
 *
 * Loads JSON version-matrix config files and resolves which Rector config file paths
 * to activate for a given (packageName, installedVersion, hop) triple.
 *
 * Each JSON file is structured as:
 * {
 *   "package": "livewire/livewire",
 *   "hops": {
 *     "hop-9-to-10": {
 *       "from_constraint": "^2.0",
 *       "rector_config":   "rector-configs/packages/rector.livewire-v2-v3.php",
 *       "notes": "..."
 *     }
 *   }
 * }
 */
final class PackageVersionMatrix
{
    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(private readonly string $configDir)
    {
    }

    /**
     * Return the Rector config file path (relative to workspace root) for a given
     * package/version/hop combination, or null if no config is applicable.
     *
     * @return string|null Absolute path to the package Rector config file, or null
     */
    public function getRectorConfigPath(
        string $packageName,
        string $installedVersion,
        string $hop,
        string $workspaceRoot,
    ): ?string {
        $matrix = $this->loadMatrix($packageName);

        if ($matrix === null) {
            return null;
        }

        $hopKey = 'hop-' . $hop;
        /** @var array<string, mixed> $hops */
        $hops = is_array($matrix['hops'] ?? null) ? $matrix['hops'] : [];

        if (! isset($hops[$hopKey]) || ! is_array($hops[$hopKey])) {
            return null;
        }

        /** @var array{from_constraint?: string, rector_config?: string} $hopConfig */
        $hopConfig = $hops[$hopKey];

        $fromConstraint = is_string($hopConfig['from_constraint'] ?? null)
            ? $hopConfig['from_constraint']
            : '*';

        if (! $this->versionSatisfies($installedVersion, $fromConstraint)) {
            return null;
        }

        $rectorConfig = $hopConfig['rector_config'] ?? null;
        if (! is_string($rectorConfig) || $rectorConfig === '') {
            return null;
        }

        // Resolve relative paths against the workspace root
        if (! str_starts_with($rectorConfig, '/')) {
            $rectorConfig = rtrim($workspaceRoot, '/\\') . DIRECTORY_SEPARATOR . $rectorConfig;
        }

        return is_file($rectorConfig) ? $rectorConfig : null;
    }

    /**
     * Emit a warning if no matrix entry exists for the given package/hop combination.
     */
    public function warnIfUnsupported(string $packageName, string $installedVersion, string $hop): void
    {
        $matrix = $this->loadMatrix($packageName);

        if ($matrix === null) {
            return;
        }

        $hopKey = 'hop-' . $hop;
        /** @var array<string, mixed> $hops */
        $hops = is_array($matrix['hops'] ?? null) ? $matrix['hops'] : [];

        if (! isset($hops[$hopKey])) {
            trigger_error(
                sprintf(
                    '[PackageVersionMatrix] WARNING: No matrix entry for "%s" on hop "%s" (installed: %s). Manual review recommended.',
                    $packageName,
                    $hop,
                    $installedVersion,
                ),
                E_USER_WARNING,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMatrix(string $packageName): ?array
    {
        if (array_key_exists($packageName, $this->cache)) {
            return $this->cache[$packageName];
        }

        $slug = str_replace('/', '-', $packageName);
        $path = rtrim($this->configDir, '/\\') . DIRECTORY_SEPARATOR . $slug . '.json';

        if (! is_file($path)) {
            $this->cache[$packageName] = null;

            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->cache[$packageName] = null;

            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($contents, true);
        $this->cache[$packageName] = is_array($data) ? $data : null;

        return $this->cache[$packageName];
    }

    /**
     * Simplified semver constraint satisfaction — supports *, ^X.Y, >=X.Y, ~X.Y.
     * Dev versions always satisfy any constraint.
     */
    private function versionSatisfies(string $version, string $constraint): bool
    {
        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        $cleanVersion = ltrim($version, 'v');

        if (str_starts_with($cleanVersion, 'dev-') || str_ends_with($cleanVersion, '-dev')) {
            return true;
        }

        $vParts = $this->parseSemver($cleanVersion);

        if (str_starts_with($constraint, '^')) {
            $cParts = $this->parseSemver(ltrim($constraint, '^v'));

            return $vParts[0] === $cParts[0] && $vParts[1] >= $cParts[1];
        }

        if (str_starts_with($constraint, '>=')) {
            $cParts = $this->parseSemver(ltrim(substr($constraint, 2), ' v'));

            return $vParts >= $cParts;
        }

        if (str_starts_with($constraint, '~')) {
            $cParts = $this->parseSemver(ltrim($constraint, '~v'));

            return $vParts[0] === $cParts[0] && $vParts[1] >= $cParts[1];
        }

        // Exact match fallback
        return $cleanVersion === ltrim($constraint, 'v');
    }

    /**
     * @return array{int, int, int}
     */
    private function parseSemver(string $version): array
    {
        $parts = explode('.', preg_replace('/[^0-9.]/', '', $version) ?? $version);

        return [
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0),
            (int) ($parts[2] ?? 0),
        ];
    }
}

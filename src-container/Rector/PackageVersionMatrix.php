<?php

declare(strict_types=1);

namespace AppContainer\Rector;

/**
 * Loads and resolves package version matrices from JSON config files.
 *
 * Each JSON file maps:  package → hops → (from_constraint, to_constraint, rules[])
 *
 * For a given (packageName, installedVersion, hop) triple this class returns
 * the list of fully-qualified Rector rule class names that should be activated.
 */
final class PackageVersionMatrix
{
    /** @var array<string, array<string, mixed>|null> In-memory cache keyed by package name. */
    private array $cache = [];

    public function __construct(private readonly string $configDir)
    {
    }

    /**
     * @return list<string> FQCN rule class-strings applicable for this package/version/hop.
     */
    public function getRules(string $packageName, string $installedVersion, string $hop): array
    {
        $matrix = $this->loadMatrix($packageName);

        if ($matrix === null) {
            return [];
        }

        $hopKey = 'hop-' . $hop;
        /** @var array<string, mixed> $hops */
        $hops = is_array($matrix['hops'] ?? null) ? $matrix['hops'] : [];

        if (! isset($hops[$hopKey]) || ! is_array($hops[$hopKey])) {
            return [];
        }

        /** @var array{from_constraint?: string, rules?: list<string>} $hopConfig */
        $hopConfig = $hops[$hopKey];

        $fromConstraint = is_string($hopConfig['from_constraint'] ?? null)
            ? $hopConfig['from_constraint']
            : '*';

        if (! $this->versionSatisfies($installedVersion, $fromConstraint)) {
            return [];
        }

        $rules = $hopConfig['rules'] ?? [];
        if (! is_array($rules)) {
            return [];
        }

        /** @var list<string> */
        return array_values(array_filter($rules, static fn (mixed $v): bool => is_string($v)));
    }

    /**
     * Emit a stderr warning when an installed package has no matrix entry for the given hop.
     * Unknown/unsupported versions emit warnings, not errors (per acceptance criteria).
     */
    public function warnIfUnsupported(string $packageName, string $installedVersion, string $hop): void
    {
        $matrix = $this->loadMatrix($packageName);

        if ($matrix === null) {
            return; // No matrix file — silently skip (package not in our known list for rules)
        }

        $hopKey = 'hop-' . $hop;
        /** @var array<string, mixed> $hops */
        $hops = is_array($matrix['hops'] ?? null) ? $matrix['hops'] : [];

        if (! isset($hops[$hopKey])) {
            fwrite(
                STDERR,
                sprintf(
                    '[PackageVersionMatrix] WARNING: No matrix entry for "%s" on hop "%s" (installed: %s). Manual review recommended.' . PHP_EOL,
                    $packageName,
                    $hop,
                    $installedVersion,
                ),
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
     * Simplified semver constraint satisfaction check.
     *
     * Supports: *, ^X.Y, >=X.Y, ~X.Y  (sufficient for hop-based matching).
     * Dev versions always satisfy any constraint.
     */
    private function versionSatisfies(string $version, string $constraint): bool
    {
        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        $cleanVersion = ltrim($version, 'v');

        // Dev versions: cannot be statically analysed — assume they satisfy.
        if (str_starts_with($cleanVersion, 'dev-') || str_ends_with($cleanVersion, '-dev')) {
            return true;
        }

        $vParts = $this->parseSemver($cleanVersion);

        if (str_starts_with($constraint, '^')) {
            $cParts = $this->parseSemver(ltrim($constraint, '^v'));
            // Compatible release: same major, at least the specified minor.
            return $vParts[0] === $cParts[0] && $vParts[1] >= $cParts[1];
        }

        if (str_starts_with($constraint, '>=')) {
            $cParts = $this->parseSemver(ltrim(substr($constraint, 2), ' v'));

            return $vParts >= $cParts;
        }

        if (str_starts_with($constraint, '~')) {
            $cParts = $this->parseSemver(ltrim($constraint, '~v'));
            // Approximately equal: same major, at least the specified minor.
            return $vParts[0] === $cParts[0] && $vParts[1] >= $cParts[1];
        }

        // Fallback: major.minor prefix match.
        $cParts = $this->parseSemver(ltrim($constraint, 'v'));

        return $vParts[0] === $cParts[0] && $vParts[1] === $cParts[1];
    }

    /**
     * @return array{int, int, int}
     */
    private function parseSemver(string $version): array
    {
        $clean = preg_replace('/[^0-9.]/', '', $version) ?? '0';
        /** @var list<string> $parts */
        $parts = array_pad(explode('.', $clean), 3, '0');

        return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
    }
}

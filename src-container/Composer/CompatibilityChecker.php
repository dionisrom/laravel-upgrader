<?php

declare(strict_types=1);

namespace AppContainer\Composer;

use AppContainer\Composer\Exception\CompatibilityDataException;

final class CompatibilityChecker
{
    private const DATA_FILE = __DIR__ . '/package-compatibility.json';

    /** @var array<string, array{support: bool|string, recommended_version: string|null, notes: string}> */
    private array $packages;

    public function __construct(private readonly ?string $dataFile = null)
    {
        $this->packages = $this->loadCompatibilityData();
    }

    public function check(string $package, string $currentConstraint): PackageCompatibility
    {
        if (!isset($this->packages[$package])) {
            return new PackageCompatibility(
                package: $package,
                support: 'unknown',
                recommendedVersion: null,
                notes: 'Package not found in compatibility matrix.',
            );
        }

        $entry = $this->packages[$package];

        return new PackageCompatibility(
            package: $package,
            support: $entry['support'],
            recommendedVersion: $entry['recommended_version'],
            notes: $entry['notes'],
        );
    }

    public function isBlocker(string $package): bool
    {
        return $this->check($package, '')->isBlocker();
    }

    /**
     * @return array<string, array{support: bool|string, recommended_version: string|null, notes: string}>
     */
    private function loadCompatibilityData(): array
    {
        $path = $this->dataFile ?? self::DATA_FILE;

        if (!file_exists($path)) {
            throw new CompatibilityDataException(
                'package-compatibility.json not found at: ' . $path
            );
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new CompatibilityDataException(
                'Could not read package-compatibility.json'
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CompatibilityDataException(
                'Invalid JSON in package-compatibility.json: ' . json_last_error_msg()
            );
        }

        if (!is_array($decoded) || !isset($decoded['packages']) || !is_array($decoded['packages'])) {
            throw new CompatibilityDataException(
                'package-compatibility.json must have a "packages" object at root.'
            );
        }

        /** @var array<string, array{support: bool|string, recommended_version: string|null, notes: string}> */
        return $decoded['packages'];
    }
}

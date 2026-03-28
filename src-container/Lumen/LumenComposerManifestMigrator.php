<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final class LumenComposerManifestMigrator
{
    private const RESERVED_REQUIRE = [
        'php',
        'ext-json',
        'ext-mbstring',
        'ext-openssl',
        'laravel/framework',
        'laravel/lumen-framework',
    ];

    public function migrate(string $sourceWorkspace, string $targetWorkspace): LumenComposerMigrationResult
    {
        $sourcePath = $sourceWorkspace . '/composer.json';
        $targetPath = $targetWorkspace . '/composer.json';

        $source = $this->readComposerJson($sourcePath);
        $target = $this->readComposerJson($targetPath);

        $manualReviewItems = [];
        $removedPackages = [];

        $target['name'] = $source['name'] ?? ($target['name'] ?? 'upgrader/lumen-migrated-app');
        $target['description'] = $source['description'] ?? ($target['description'] ?? 'Migrated from Lumen');
        $target['type'] = $source['type'] ?? ($target['type'] ?? 'project');
        $target['repositories'] = $source['repositories'] ?? ($target['repositories'] ?? []);
        $target['minimum-stability'] = $source['minimum-stability'] ?? ($target['minimum-stability'] ?? 'stable');
        $target['prefer-stable'] = $source['prefer-stable'] ?? ($target['prefer-stable'] ?? true);

        $target['autoload'] = $this->mergeAssoc(
            $target['autoload'] ?? [],
            $source['autoload'] ?? [],
        );
        $target['autoload-dev'] = $this->mergeAssoc(
            $target['autoload-dev'] ?? [],
            $source['autoload-dev'] ?? [],
        );
        $target['scripts'] = $this->mergeAssoc(
            $target['scripts'] ?? [],
            $source['scripts'] ?? [],
        );
        $target['config'] = $this->mergeAssoc(
            $target['config'] ?? [],
            $source['config'] ?? [],
        );

        $sourceRequire = is_array($source['require'] ?? null) ? $source['require'] : [];
        $sourceRequireDev = is_array($source['require-dev'] ?? null) ? $source['require-dev'] : [];
        $targetRequire = is_array($target['require'] ?? null) ? $target['require'] : [];
        $targetRequireDev = is_array($target['require-dev'] ?? null) ? $target['require-dev'] : [];

        if (isset($sourceRequire['php'])) {
            $targetRequire['php'] = $sourceRequire['php'];
        }

        foreach ($sourceRequire as $package => $constraint) {
            if (in_array($package, self::RESERVED_REQUIRE, true)) {
                continue;
            }

            if ($this->shouldSkipLumenSpecificPackage($package)) {
                $removedPackages[] = $package;
                $manualReviewItems[] = LumenManualReviewItem::other(
                    'composer.json',
                    0,
                    sprintf('Skipped Lumen-specific Composer package %s during migration.', $package),
                    'warning',
                    'Review the package for a Laravel-compatible replacement before deployment.',
                );
                continue;
            }

            $targetRequire[$package] = $constraint;
        }

        foreach ($sourceRequireDev as $package => $constraint) {
            if ($package === 'laravel/lumen-framework') {
                continue;
            }

            $targetRequireDev[$package] = $constraint;
        }

        unset($targetRequire['laravel/lumen-framework'], $targetRequireDev['laravel/lumen-framework']);

        $target['require'] = $targetRequire;
        $target['require-dev'] = $targetRequireDev;

        $this->writeComposerJson($targetPath, $target);

        $lockPath = $targetWorkspace . '/composer.lock';
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }

        echo json_encode([
            'event' => 'lumen_composer_manifest_migrated',
            'ts' => time(),
            'target' => $targetPath,
            'removed_packages' => $removedPackages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return new LumenComposerMigrationResult($removedPackages, $manualReviewItems);
    }

    private function shouldSkipLumenSpecificPackage(string $package): bool
    {
        $normalized = strtolower($package);

        return $normalized !== 'laravel/lumen-framework'
            && str_contains($normalized, 'lumen');
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function mergeAssoc(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->mergeAssoc($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read composer manifest: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid composer manifest: {$path}");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeComposerJson(string $path, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode migrated composer manifest.');
        }

        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException("Failed to write migrated composer manifest: {$path}");
        }
    }
}
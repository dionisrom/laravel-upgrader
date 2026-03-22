<?php

declare(strict_types=1);

namespace AppContainer\Config;

use AppContainer\Config\Exception\SnapshotException;

/**
 * Creates and restores atomic tar-archive snapshots of a workspace's
 * config/*.php and .env* files.
 *
 * Snapshot lifecycle:
 *   $path = $manager->snapshot($workspacePath);   // creates tar
 *   // … apply migrations …
 *   $manager->restore($path, $workspacePath);      // on failure: rollback
 *   $manager->cleanup($path);                      // on success: remove tar
 */
final class ConfigSnapshotManager
{
    private const SNAPSHOT_BASE = 'upgrader/snapshots';

    /** @var string[] env files to always include when present */
    private const ENV_FILES = ['.env', '.env.example', '.env.testing', '.env.staging'];

    /**
     * Snapshot all config/*.php and .env* files into a tar archive.
     *
     * @throws SnapshotException
     */
    public function snapshot(string $workspacePath): string
    {
        $snapshotDir = sys_get_temp_dir() . '/' . self::SNAPSHOT_BASE;
        $this->ensureDirectory($snapshotDir);

        $snapshotPath = $snapshotDir . '/' . uniqid('config_', true) . '.tar';

        // Stage files preserving workspace-relative paths
        $stageDir = $snapshotDir . '/' . uniqid('stage_', true);
        $this->ensureDirectory($stageDir);

        try {
            $files = $this->collectFiles($workspacePath);

            foreach ($files as $absPath => $relPath) {
                $dest = $stageDir . '/' . $relPath;
                $this->ensureDirectory(dirname($dest));

                if (!copy($absPath, $dest)) {
                    throw new SnapshotException("Cannot copy file to snapshot: {$absPath}");
                }
            }

            $phar = new \PharData($snapshotPath);
            $phar->buildFromDirectory($stageDir);
        } catch (SnapshotException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SnapshotException(
                "Snapshot creation failed: " . $e->getMessage(),
                0,
                $e
            );
        } finally {
            $this->removeDirectory($stageDir);
        }

        return $snapshotPath;
    }

    /**
     * Restore a previously created snapshot over the workspace.
     *
     * Overwrites existing files; files that no longer exist in the snapshot
     * are left in place (the snapshot only tracks files that existed at
     * snapshot time, custom additions are harmless).
     *
     * @throws SnapshotException
     */
    public function restore(string $snapshotPath, string $workspacePath): void
    {
        if (!file_exists($snapshotPath)) {
            throw new SnapshotException("Snapshot archive not found: {$snapshotPath}");
        }

        try {
            $phar = new \PharData($snapshotPath);
            $phar->extractTo($workspacePath, null, true);
        } catch (\Throwable $e) {
            throw new SnapshotException(
                "Snapshot restore failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete the snapshot archive after a successful migration.
     */
    public function cleanup(string $snapshotPath): void
    {
        if (file_exists($snapshotPath)) {
            @unlink($snapshotPath);
        }
    }

    /**
     * Collect config/*.php and .env* files as absolute → relative path map.
     *
     * @return array<string, string>
     */
    private function collectFiles(string $workspacePath): array
    {
        $files = [];
        $root = rtrim($workspacePath, '/\\');

        $configDir = $root . '/config';
        if (is_dir($configDir)) {
            foreach (glob($configDir . '/*.php') ?: [] as $file) {
                $files[$file] = 'config/' . basename($file);
            }
        }

        foreach (self::ENV_FILES as $envFile) {
            $abs = $root . '/' . $envFile;
            if (file_exists($abs)) {
                $files[$abs] = $envFile;
            }
        }

        return $files;
    }

    /** @throws SnapshotException */
    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new SnapshotException("Cannot create directory: {$dir}");
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            assert($item instanceof \SplFileInfo);
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }
}

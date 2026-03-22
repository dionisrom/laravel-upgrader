<?php

declare(strict_types=1);

namespace AppContainer\Detector;

use AppContainer\Detector\Exception\DetectionException;

final class InventoryScanner
{
    private const EXCLUDE_DIRS = ['vendor', '.upgrader-state'];

    /**
     * Walks the workspace directory, categorizes all files, emits
     * the pipeline_start JSON-ND event to stdout, and returns a DetectionResult.
     */
    public function scan(string $workspacePath, string $hop = '8_to_9', int $seq = 1): DetectionResult
    {
        if (!is_dir($workspacePath)) {
            throw new DetectionException(
                "Workspace path is not a directory: {$workspacePath}"
            );
        }

        $versionDetector   = new VersionDetector();
        $frameworkDetector = new FrameworkDetector();

        $framework      = $frameworkDetector->detect($workspacePath);
        $laravelVersion = $versionDetector->detectLaravelVersion($workspacePath);
        $phpConstraint  = $versionDetector->detectPhpConstraint($workspacePath);

        $phpFiles        = 0;
        $configFiles     = 0;
        $routeFiles      = 0;
        $migrationFiles  = 0;
        $viewFiles       = 0;
        $totalFiles      = 0;

        $iterator = $this->buildIterator($workspacePath);

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $totalFiles++;

            $relativePath = $this->relativePath($workspacePath, $file->getPathname());

            if ($this->isExcluded($relativePath)) {
                $totalFiles--;
                continue;
            }

            $filename  = $file->getFilename();
            $extension = $file->getExtension();

            if ($extension === 'php') {
                $phpFiles++;
            }

            if ($this->isConfigFile($relativePath, $filename, $extension)) {
                $configFiles++;
            } elseif ($this->isRouteFile($relativePath, $filename, $extension)) {
                $routeFiles++;
            } elseif ($this->isMigrationFile($relativePath, $filename, $extension)) {
                $migrationFiles++;
            } elseif ($this->isViewFile($relativePath, $filename)) {
                $viewFiles++;
            }
        }

        $this->emitPipelineStart($totalFiles, $phpFiles, $configFiles, $hop, $seq);

        return new DetectionResult(
            framework:      $framework,
            laravelVersion: $laravelVersion,
            phpConstraint:  $phpConstraint,
            totalFiles:     $totalFiles,
            phpFiles:       $phpFiles,
            configFiles:    $configFiles,
            routeFiles:     $routeFiles,
            migrationFiles: $migrationFiles,
            viewFiles:      $viewFiles,
        );
    }

    /**
     * @return \RecursiveIteratorIterator<\RecursiveCallbackFilterIterator<mixed, mixed, \RecursiveDirectoryIterator>>
     */
    private function buildIterator(string $workspacePath): \RecursiveIteratorIterator
    {
        $directoryIterator = new \RecursiveDirectoryIterator(
            $workspacePath,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $filterIterator = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (\SplFileInfo $file, string $key, \RecursiveDirectoryIterator $iterator): bool {
                if ($iterator->hasChildren()) {
                    $basename = $file->getBasename();

                    foreach (self::EXCLUDE_DIRS as $excluded) {
                        if ($basename === $excluded) {
                            return false;
                        }
                    }
                }

                return true;
            }
        );

        return new \RecursiveIteratorIterator(
            $filterIterator,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    private function relativePath(string $workspacePath, string $absolutePath): string
    {
        $workspacePath = rtrim(str_replace('\\', '/', $workspacePath), '/');
        $absolutePath  = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($absolutePath, $workspacePath . '/')) {
            return substr($absolutePath, strlen($workspacePath) + 1);
        }

        return $absolutePath;
    }

    private function isExcluded(string $relativePath): bool
    {
        foreach (self::EXCLUDE_DIRS as $excludedDir) {
            if (str_starts_with($relativePath, $excludedDir . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isConfigFile(string $relativePath, string $filename, string $extension): bool
    {
        return $extension === 'php'
            && str_starts_with($relativePath, 'config/');
    }

    private function isRouteFile(string $relativePath, string $filename, string $extension): bool
    {
        return $extension === 'php'
            && str_starts_with($relativePath, 'routes/');
    }

    private function isMigrationFile(string $relativePath, string $filename, string $extension): bool
    {
        return $extension === 'php'
            && str_starts_with($relativePath, 'database/migrations/');
    }

    private function isViewFile(string $relativePath, string $filename): bool
    {
        return str_starts_with($relativePath, 'resources/views/')
            && str_ends_with($filename, '.blade.php');
    }

    private function emitPipelineStart(
        int $totalFiles,
        int $phpFiles,
        int $configFiles,
        string $hop,
        int $seq
    ): void {
        $event = [
            'event'        => 'pipeline_start',
            'total_files'  => $totalFiles,
            'php_files'    => $phpFiles,
            'config_files' => $configFiles,
            'hop'          => $hop,
            'ts'           => time(),
            'seq'          => $seq,
        ];

        echo json_encode($event) . "\n";
    }
}

<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use AppContainer\Lumen\Exception\ScaffoldException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Generates a Laravel 9 scaffold for a Lumen → Laravel migration (TRD §10.2).
 *
 * IMPORTANT: The caller MUST NOT run this step with --network=none.
 * `composer create-project` requires outbound network access to download
 * the Laravel 9 skeleton from Packagist. All other migration steps are
 * network-isolated, but this one must be exempted in the orchestrator.
 *
 * Process:
 *   1. Run `composer create-project laravel/laravel:^9.0 {targetPath}`
 *   2. Preserve original `bootstrap/app.php` as `bootstrap/lumen-app-original.php`
 *   3. Emit `lumen_scaffold_created` JSON-ND event
 */
final class ScaffoldGenerator
{
    private const LARAVEL_VERSION = '^9.0';
    private const COMPOSER_TIMEOUT = 300;

    public function __construct(
        private readonly string $composerBin = 'composer',
    ) {}

    /**
     * @param string $targetPath  absolute path where the new Laravel scaffold will be created
     * @param string $lumenSource absolute path to the existing Lumen workspace (used to preserve bootstrap/app.php)
     * @throws ScaffoldException
     */
    public function generate(string $targetPath, string $lumenSource): ScaffoldResult
    {
        $this->ensureTargetEmpty($targetPath);
        $this->preserveOriginalBootstrap($lumenSource);

        try {
            $this->runComposerCreateProject($targetPath);
        } catch (\Throwable $e) {
            $result = ScaffoldResult::failure($targetPath, $e->getMessage());
            $this->emitEvent('lumen_scaffold_failed', [
                'target_path' => $targetPath,
                'error'       => $e->getMessage(),
            ]);
            return $result;
        }

        $originalBootstrap = $lumenSource . '/bootstrap/lumen-app-original.php';
        $result = ScaffoldResult::success($targetPath, $originalBootstrap);

        $this->emitEvent('lumen_scaffold_created', [
            'target_path'        => $targetPath,
            'lumen_source'       => $lumenSource,
            'original_bootstrap' => $originalBootstrap,
            'laravel_version'    => self::LARAVEL_VERSION,
        ]);

        return $result;
    }

    private function ensureTargetEmpty(string $targetPath): void
    {
        if (is_dir($targetPath) && count(scandir($targetPath) ?: []) > 2) {
            throw new ScaffoldException(
                "Target path already exists and is non-empty: {$targetPath}. " .
                "Remove it before generating a fresh scaffold."
            );
        }
    }

    private function preserveOriginalBootstrap(string $lumenSource): void
    {
        $original = $lumenSource . '/bootstrap/app.php';
        $preserved = $lumenSource . '/bootstrap/lumen-app-original.php';

        if (!file_exists($original)) {
            return;
        }

        if (file_exists($preserved)) {
            // Already preserved from a previous run; skip.
            return;
        }

        if (!copy($original, $preserved)) {
            throw new ScaffoldException(
                "Failed to preserve original bootstrap/app.php to bootstrap/lumen-app-original.php"
            );
        }
    }

    private function runComposerCreateProject(string $targetPath): void
    {
        $command = [
            $this->composerBin,
            'create-project',
            'laravel/laravel:' . self::LARAVEL_VERSION,
            $targetPath,
            '--no-interaction',
            '--no-scripts',
        ];

        $process = new Process($command, timeout: self::COMPOSER_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ScaffoldException(
                "composer create-project failed (exit {$process->getExitCode()}): " .
                $process->getErrorOutput()
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $event, array $data): void
    {
        echo json_encode(['event' => $event, 'ts' => time()] + $data) . "\n";
    }
}

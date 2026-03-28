<?php

declare(strict_types=1);

namespace AppContainer\Detector;

use AppContainer\Detector\Exception\DetectionException;

final class FrameworkDetector
{
    /**
     * Returns 'laravel', 'lumen', or 'lumen_ambiguous'.
     *
     * 'lumen'           = BOTH conditions met:
     *                     1. laravel/lumen-framework in composer.json require
     *                     2. "new Laravel\Lumen\Application" in bootstrap/app.php
     *
     * 'lumen_ambiguous' = only ONE condition met (emits warning event to stdout)
     * 'laravel'         = neither condition met (laravel/framework present)
     */
    public function detect(string $workspacePath): string
    {
        $composerFile = $workspacePath . '/composer.json';

        if (!file_exists($composerFile)) {
            throw new DetectionException(
                "composer.json not found at: {$composerFile}"
            );
        }

        $contents = file_get_contents($composerFile);

        if ($contents === false) {
            throw new DetectionException(
                "Failed to read composer.json at: {$composerFile}"
            );
        }

        /** @var array{require?: array<string, string>, require-dev?: array<string, string>} $composer */
        $composer = json_decode($contents, true);

        if (!is_array($composer)) {
            throw new DetectionException('composer.json is malformed');
        }

        $hasLumenPackage = self::composerDeclaresLumen($composer);

        $bootstrapFile = $workspacePath . '/bootstrap/app.php';
        $hasLumenBootstrap = file_exists($bootstrapFile)
            && str_contains((string) file_get_contents($bootstrapFile), 'new Laravel\Lumen\Application');

        if ($hasLumenPackage && $hasLumenBootstrap) {
            return 'lumen';
        }

        if ($hasLumenPackage || $hasLumenBootstrap) {
            $this->emitWarning('lumen_ambiguous', $workspacePath);

            return 'lumen_ambiguous';
        }

        return 'laravel';
    }

    private function emitWarning(string $type, string $workspacePath): void
    {
        $event = [
            'event'     => 'warning',
            'type'      => $type,
            'workspace' => $workspacePath,
            'message'   => 'Lumen detected via only one condition (package or bootstrap). '
                . 'Verify laravel/lumen-framework in composer.json AND '
                . '"new Laravel\Lumen\Application" in bootstrap/app.php.',
            'ts'        => time(),
        ];

        echo json_encode($event) . "\n";
    }

    /**
     * @param array{require?: array<string, string>, require-dev?: array<string, string>} $composer
     */
    public static function composerDeclaresLumen(array $composer): bool
    {
        return isset($composer['require']['laravel/lumen-framework'])
            || isset($composer['require-dev']['laravel/lumen-framework']);
    }
}

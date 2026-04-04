<?php

declare(strict_types=1);

namespace App\Composer;

final class FrameworkDetector
{
    /**
     * Returns 'laravel', 'lumen', or 'lumen_ambiguous'.
     */
    public function detect(string $workspacePath): string
    {
        $composerFile = rtrim($workspacePath, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerFile)) {
            return 'laravel';
        }

        $contents = file_get_contents($composerFile);
        if ($contents === false) {
            return 'laravel';
        }

        /** @var array{require?: array<string, string>, require-dev?: array<string, string>}|mixed $composer */
        $composer = json_decode($contents, true);
        if (!is_array($composer)) {
            return 'laravel';
        }

        $hasLumenPackage = self::composerDeclaresLumen($composer);

        $bootstrapFile = rtrim($workspacePath, '/\\') . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        $hasLumenBootstrap = is_file($bootstrapFile)
            && str_contains((string) file_get_contents($bootstrapFile), 'new Laravel\\Lumen\\Application');

        if ($hasLumenPackage && $hasLumenBootstrap) {
            return 'lumen';
        }

        if ($hasLumenPackage || $hasLumenBootstrap) {
            return 'lumen_ambiguous';
        }

        return 'laravel';
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
<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

final readonly class LumenDetectionResult
{
    /**
     * @param 'lumen'|'lumen_ambiguous'|'laravel' $framework
     * @param bool $hasLumenPackage   laravel/lumen-framework found in composer.json
     * @param bool $hasLumenBootstrap "new Laravel\Lumen\Application" found in bootstrap/app.php
     */
    public function __construct(
        public readonly string $framework,
        public readonly bool $hasLumenPackage,
        public readonly bool $hasLumenBootstrap,
        public readonly string $workspacePath,
    ) {}

    public static function definitive(string $workspacePath): self
    {
        return new self(
            framework: 'lumen',
            hasLumenPackage: true,
            hasLumenBootstrap: true,
            workspacePath: $workspacePath,
        );
    }

    public static function ambiguous(string $workspacePath, bool $hasPackage, bool $hasBootstrap): self
    {
        return new self(
            framework: 'lumen_ambiguous',
            hasLumenPackage: $hasPackage,
            hasLumenBootstrap: $hasBootstrap,
            workspacePath: $workspacePath,
        );
    }

    public static function notLumen(string $workspacePath): self
    {
        return new self(
            framework: 'laravel',
            hasLumenPackage: false,
            hasLumenBootstrap: false,
            workspacePath: $workspacePath,
        );
    }

    public function isLumen(): bool
    {
        return $this->framework === 'lumen';
    }
}

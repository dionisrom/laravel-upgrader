<?php

declare(strict_types=1);

namespace App\Orchestrator;

/**
 * Carries user-specified pipeline options from the CLI layer to the orchestrator
 * and Docker runner without polluting method signatures with booleans.
 */
final readonly class UpgradeOptions
{
    /**
     * @param list<string> $reportFormats
     */
    public function __construct(
        public bool $skipPhpstan = false,
        public bool $withArtisanVerify = false,
        public array $reportFormats = ['html', 'json', 'md'],
        public bool $dryRun = false,
        public ?string $extraComposerCacheDir = null,
        public bool $skipDependencyUpgrader = false,
    ) {}
}

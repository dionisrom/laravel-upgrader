<?php

declare(strict_types=1);

// ============================================================
// Package Rector Config: filament/filament V2 → V3
// Hop: 9-to-10
// ============================================================

use AppContainer\Rector\Rules\Package\Filament\FilamentFormTableNamespaceRector;
use Rector\Config\RectorConfig;

require_once __DIR__ . '/../workspace-skip-paths.php';

$config = RectorConfig::configure()
    ->withPaths(['/workspace']);

foreach (upgraderWorkspaceSkipPaths('/workspace') as $skipPath) {
    $config = $config->withSkipPath($skipPath);
}

return $config
    ->withRules([
        FilamentFormTableNamespaceRector::class,
    ]);

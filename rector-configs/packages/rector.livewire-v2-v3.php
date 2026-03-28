<?php

declare(strict_types=1);

// ============================================================
// Package Rector Config: livewire/livewire V2 → V3
// Hop: 9-to-10 (and may be applied on 10-to-11 if skipped)
// ============================================================

use AppContainer\Rector\Rules\Package\Livewire\ComputedPropertyRector;
use AppContainer\Rector\Rules\Package\Livewire\EmitToDispatchRector;
use Rector\Config\RectorConfig;

require_once __DIR__ . '/../workspace-skip-paths.php';

$config = RectorConfig::configure()
    ->withPaths(['/workspace']);

foreach (upgraderWorkspaceSkipPaths('/workspace') as $skipPath) {
    $config = $config->withSkipPath($skipPath);
}

return $config
    ->withRules([
        EmitToDispatchRector::class,
        ComputedPropertyRector::class,
    ]);

<?php

declare(strict_types=1);

// ============================================================
// Rector Config: hop-9-to-10
// Laravel 9 → Laravel 10 upgrade rules
//
// Includes:
//   - driftingly/rector-laravel LaravelSetList::LARAVEL_100
//   - Custom gap-fill rules from src-container/Rector/Rules/L9ToL10/
// ============================================================

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

// Custom gap-fill rules
use AppContainer\Rector\Rules\L9ToL10\AssertDeletedToAssertModelMissingRector;
use AppContainer\Rector\Rules\L9ToL10\LaravelModelReturnTypeRector;
use AppContainer\Rector\PackageRuleActivator;
use AppContainer\Rector\PackageVersionMatrix;

require_once __DIR__ . '/workspace-skip-paths.php';

$workspacePath = getenv('UPGRADER_WORKSPACE') ?: '/repo';

// ── Package-aware rule activation ─────────────────────────────────────────────
$packageRules = [];
$composerLockPath = $workspacePath . '/composer.lock';
$packageRulesConfigDir = getenv('UPGRADER_PACKAGE_RULES_DIR') ?: '/upgrader/docs';

if (is_file($composerLockPath) && is_dir($packageRulesConfigDir)) {
    $activator = new PackageRuleActivator(new PackageVersionMatrix($packageRulesConfigDir));
    $packageRules = $activator->activate($composerLockPath, '9-to-10');
}

$config = RectorConfig::configure()
    ->withPaths([$workspacePath]);

foreach (upgraderWorkspaceSkipPaths($workspacePath) as $skipPath) {
    $config = $config->withSkipPath($skipPath);
}

return $config

    // ── Upstream rector-laravel L10 set ───────────────────────────────────────
    ->withSets([
        LaravelSetList::LARAVEL_100,
    ])

    // ── Custom gap-fill rules ─────────────────────────────────────────────────
    ->withRules(array_merge([
        AssertDeletedToAssertModelMissingRector::class,
        LaravelModelReturnTypeRector::class,
    ], $packageRules))

    ->withoutParallel()
    ->withImportNames(importDocBlockNames: false);

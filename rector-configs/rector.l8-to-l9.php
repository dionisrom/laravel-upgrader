<?php

declare(strict_types=1);

// ============================================================
// Rector Config: hop-8-to-9
// Laravel 8 → Laravel 9 upgrade rules
//
// Includes:
//   - driftingly/rector-laravel LaravelSetList::LARAVEL_90
//   - Custom gap-fill rules from src-container/Rector/Rules/L8ToL9/
// ============================================================

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

// Custom gap-fill rules
use Upgrader\Rector\Rules\L8ToL9\HttpKernelMiddlewareRector;
use Upgrader\Rector\Rules\L8ToL9\ModelUnguardRector;
use Upgrader\Rector\Rules\L8ToL9\PasswordRuleRector;
use Upgrader\Rector\Rules\L8ToL9\WhereNotToWhereNotInRector;

return RectorConfig::configure()
    ->withPaths(['/workspace'])
    ->withSkipPath('/workspace/vendor')
    ->withSkipPath('/workspace/storage')
    ->withSkipPath('/workspace/bootstrap/cache')
    ->withSkipPath('/workspace/node_modules')

    // ── Upstream rector-laravel L9 set ────────────────────────────────────────
    ->withSets([
        LaravelSetList::LARAVEL_90,
    ])

    // ── Custom gap-fill rules ─────────────────────────────────────────────────
    ->withRules([
        HttpKernelMiddlewareRector::class,
        ModelUnguardRector::class,
        PasswordRuleRector::class,
        WhereNotToWhereNotInRector::class,
    ])

    ->withParallel()
    ->withImportNames(importDocBlockClassNames: false);

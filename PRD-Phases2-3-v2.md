# Laravel Enterprise Upgrader — Phase 2 & Phase 3
## Product Requirements Document · v2.0 (Post-Audit)

> **Depends on:** Phase 1 MVP stable + design spike outputs  
> **Phase 2 confidence:** 90% · **Phase 3 confidence:** 88%  
> **Combined confidence with spikes completed:** 96%  
> **Status:** Revised Draft · March 2026

---

## Table of Contents

1. [Document Overview](#1-document-overview)
2. [Phase 2 — Strategic Context](#2-phase-2--strategic-context)
3. [Phase 2 — Hop Container Specifications](#3-phase-2--hop-container-specifications)
4. [Phase 2 — L10→L11 Slim Skeleton](#4-phase-2--l10l11-slim-skeleton)
5. [Phase 2 — Multi-Hop Orchestration](#5-phase-2--multi-hop-orchestration)
6. [Phase 2 — Package Rule Sets](#6-phase-2--package-rule-sets)
7. [Phase 2 — CI/CD Integration](#7-phase-2--cicd-integration)
8. [Phase 2 — HTML Diff Viewer v2](#8-phase-2--html-diff-viewer-v2)
9. [Phase 2 — Delivery Plan](#9-phase-2--delivery-plan)
10. [Phase 3 — Strategic Context](#10-phase-3--strategic-context)
11. [Phase 3 — PHP Hop Containers](#11-phase-3--php-hop-containers)
12. [Phase 3 — 2D HopPlanner](#12-phase-3--2d-hopplanner)
13. [Phase 3 — Extension Compatibility Checker](#13-phase-3--extension-compatibility-checker)
14. [Phase 3 — Silent Change Scanner](#14-phase-3--silent-change-scanner)
15. [Phase 3 — Dashboard 2D Timeline](#15-phase-3--dashboard-2d-timeline)
16. [Phase 3 — Delivery Plan](#16-phase-3--delivery-plan)
17. [Acceptance Criteria — All Phases](#17-acceptance-criteria--all-phases)
18. [Full Product Roadmap](#18-full-product-roadmap)

---

## 1. Document Overview

This document covers Phase 2 (full Laravel 9→13 suite + enterprise features) and Phase 3 (PHP version upgrader). Both phases build directly on the audited, corrected Phase 1 foundation.

### Foundation Inherited from Phase 1

All Phase 2 and Phase 3 modules inherit these architectural decisions from Phase 1:

- **Rector subprocess model** — `vendor/bin/rector --dry-run --output-format=json` (never programmatic)
- **ReactPHP dashboard** — non-blocking SSE, concurrent connections, client disconnect detection
- **TransformCheckpoint + WorkspaceReconciler** — checkpoint-based resume across all hops
- **Static-first verification** — no artisan boot required; artisan opt-in with `--with-artisan-verify`
- **Atomic ConfigMigrator** — snapshot-all, migrate-all, rollback-on-failure
- **Diff2Html inline** — no CDN, fully offline reports
- **JSON-ND event streaming** — all container→host communication
- **Content-addressed workspaces + advisory locks** — concurrent run safety
- **Test suite pattern** — `AbstractRectorTestCase` + `.php.inc` fixtures for all new rules

### Confidence Trajectory

| Phase | Duration | Confidence | Gating Requirement |
|---|---|---|---|
| Phase 1 | 22 weeks | 96% | Audit findings resolved |
| Phase 2 | 22 weeks | 90% | Phase 1 stable + design spikes from P1 weeks 20–21 |
| Phase 3 | 14 weeks | 88% | Phase 2 stable on 10+ enterprise repos |
| **Total** | **~58 weeks** | **96%** | All phases + spikes complete |

---

## 2. Phase 2 — Strategic Context

### 2.1 Entry Criteria

Phase 2 **must not begin** until all of the following are satisfied:

- Phase 1 MVP validated on minimum 3 real-world enterprise Laravel 8 repositories
- Verification pass rate ≥ 95%; false positive transformation rate < 2%
- L10→L11 slim skeleton design spike document committed (from Phase 1 week 20)
- Livewire V2→V3 scope design spike document committed (from Phase 1 week 21)
- ReactPHP dashboard proven stable under 40-minute upgrade sessions
- `TransformCheckpoint` resume tested on at least one interrupted real upgrade

### 2.2 What Phase 2 Adds

| Deliverable | Informed by Phase 1 | Informed by Spikes |
|---|---|---|
| L9→L10, L10→L11, L11→L12, L12→L13 hop containers | Docker hop model proven in Phase 1 | L10→L11 spike defines module list |
| Multi-hop orchestration (L8→L13 in one command) | `HopPlanner` and `TransformCheckpoint` from Phase 1 | — |
| L10→L11 slim skeleton restructure | Scaffold generator pattern from Lumen migration | **L10→L11 spike is mandatory input** |
| Package rule sets (Spatie, Livewire, Sanctum, etc.) | Package detection from Phase 1 inventory scanner | **Livewire V2→V3 spike defines scope boundary** |
| CI/CD integration templates | Subprocess model + `--no-dashboard` CI mode | — |
| HTML diff viewer v2 + chain resumability | State/Checkpoint from Phase 1 | — |

### 2.3 Scope Boundary

**In scope — Phase 2:**
- Laravel hops: L9→L10, L10→L11, L11→L12, L12→L13
- Multi-hop orchestration: L8→L13 in a single command
- Package rule sets: Spatie, Livewire (spike-scoped), Sanctum, Passport, Filament, Nova, Horizon
- CI/CD templates: GitHub Actions, GitLab CI, Bitbucket
- HTML diff viewer v2: file tree, filters, hop navigation, annotations, sign-off, PDF export

**Out of scope — Phase 2:**
- PHP version upgrades (Phase 3)
- SaaS/cloud deployment
- Plugin/extension ecosystem
- Packages not listed above

---

## 3. Phase 2 — Hop Container Specifications

### 3.1 Docker Images

| Image | PHP Base | Hardest Challenge | Key Custom Modules |
|---|---|---|---|
| `upgrader:hop-9-to-10` | PHP 8.1 | Native return types across all framework classes | `ReturnTypeRector`, `PackageRuleActivator` |
| `upgrader:hop-10-to-11` | PHP 8.2 | Slim skeleton — full `bootstrap/app.php` restructure | `SlimSkeletonGenerator`, `KernelMigrator`, `ExceptionMigrator` |
| `upgrader:hop-11-to-12` | PHP 8.2 | Route model binding changes, `once()` helper | `RouteBindingAuditor`, `OnceHelperIntroducer` |
| `upgrader:hop-12-to-13` | PHP 8.3 | PHP 8.3 minimum enforcement, Eloquent changes | `PhpMinimumEnforcer`, `EloquentBreakingChangeRector` |

### 3.2 Breaking Changes Per Hop

Each hop image bundles its own `docs/breaking-changes.json`. Key breaking changes per hop:

**L9 → L10:**
- PHP 8.1 minimum requirement
- Native return types added across all framework classes (high volume, Rector handles)
- `Model::unguard()` removed
- Various deprecated helpers removed

**L10 → L11:**
- PHP 8.2 minimum requirement
- Slim skeleton restructure (see Section 4)
- Middleware registration moved to `bootstrap/app.php`
- Exception handling moved to `bootstrap/app.php`
- `app/Http/Kernel.php` and `app/Exceptions/Handler.php` removed by default

**L11 → L12:**
- Route model binding changes
- `once()` helper introduced
- Various Eloquent and collection changes

**L12 → L13:**
- PHP 8.3 minimum requirement
- Ongoing Eloquent API changes
- New language features available (typed class constants, etc.)

---

## 4. Phase 2 — L10→L11 Slim Skeleton

> ⚠️ **This hop requires a fundamentally different approach.** Standard AST transformation is insufficient. This is a scaffold regeneration informed by analysing the existing app — the same approach used for Lumen migration in Phase 1.

### 4.1 Why This Hop Is Different

Laravel 11 replaced the full application skeleton with a dramatically slimmed version:

- `app/Http/Kernel.php` — **removed** by default
- `app/Exceptions/Handler.php` — **removed** by default
- All middleware, exception handling, and service configuration → **`bootstrap/app.php`**
- This is not an AST transformation — it is a scaffold regeneration

### 4.2 New Modules Required (from L10→L11 design spike)

```
src-container/SlimSkeleton/
├── SlimSkeletonGenerator.php        # generates new bootstrap/app.php
├── KernelMigrator.php               # reads existing Kernel.php → bootstrap/app.php
├── ExceptionHandlerMigrator.php     # reads existing Handler.php → bootstrap/app.php
├── MiddlewareRegistrationMigrator.php
├── ServiceProviderMigrator.php
├── CustomLogicDetector.php          # detects non-standard logic that must be preserved
└── SlimSkeletonAuditReport.php      # dedicated diff section in HTML report
```

### 4.3 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| SK-01 | Analyse existing `Kernel.php` and generate `bootstrap/app.php` middleware registrations | Must Have | Not Rector-based |
| SK-02 | Analyse existing `Handler.php` and generate `bootstrap/app.php` exception handlers | Must Have | |
| SK-03 | Migrate service provider registrations to `bootstrap/app.php` | Must Have | |
| SK-04 | Preserve custom `Kernel.php` / `Handler.php` if they contain non-standard logic | Must Have | Never delete custom code |
| SK-05 | `SlimSkeletonGenerator` uses design spike output as its specification | Must Have | Spike from Phase 1 w20 |
| SK-06 | Dashboard shows dedicated slim skeleton migration progress sub-view | Must Have | |
| SK-07 | HTML report includes dedicated skeleton migration diff section | Must Have | |
| SK-08 | Unknown middleware / handler patterns flagged as manual review with explanation | Must Have | |

---

## 5. Phase 2 — Multi-Hop Orchestration

### 5.1 Overview

With all five Laravel hop containers available, `HopPlanner` is extended to coordinate a full L8→L13 upgrade as a single orchestrated command. State is carried across container boundaries via the `TransformCheckpoint` system established in Phase 1.

### 5.2 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| MH-01 | Plan full L8→L13 upgrade as ordered hop sequence automatically | Must Have | |
| MH-02 | Each hop receives the verified workspace output of the previous hop | Must Have | Chain verified output only |
| MH-03 | `TransformCheckpoint` used across all hops — single resumable chain | Must Have | Phase 1 foundation |
| MH-04 | Failed hop halts the chain; subsequent hops do not run | Must Have | Safety critical |
| MH-05 | `--resume` resumes multi-hop chain from last completed hop checkpoint | Must Have | |
| MH-06 | User can target any intermediate version (e.g. `--to=11` stops after L11) | Must Have | |
| MH-07 | Single unified HTML report covering all hops with hop-by-hop navigation | Must Have | |
| MH-08 | Single unified `audit.log.json` spanning all hops in sequence | Must Have | |
| MH-09 | Dashboard shows overall multi-hop timeline with per-hop confidence scores | Must Have | |

### 5.3 Multi-Hop CLI Usage

```bash
# Full upgrade L8 → L13
upgrader run --repo=github:org/app --from=8 --to=13

# Stop at intermediate version
upgrader run --repo=github:org/app --from=8 --to=11

# Resume interrupted multi-hop upgrade
upgrader run --repo=github:org/app --from=8 --to=13 --resume

# CI/CD mode (no dashboard, JSON output, exit code)
upgrader run --repo=github:org/app --from=8 --to=13 --no-dashboard --format=json
```

---

## 6. Phase 2 — Package Rule Sets

### 6.1 Package Detection

Package detection is automatic:

1. At inventory scan time, installed packages are read from `composer.lock`
2. `PackageRuleActivator` automatically enables relevant rule sets for detected packages
3. Package rules are bundled in each hop image — no network access required
4. Unknown package versions are flagged as manual review with specific guidance

### 6.2 Supported Packages Per Hop

| Package | Hops Covered | Automation Level | Key Changes Covered |
|---|---|---|---|
| `spatie/laravel-permission` | L9→L10, L10→L11 | High | Model method signatures, permission cache |
| `spatie/laravel-medialibrary` | L9→L10, L10→L11 | Medium | Conversion API; custom conversions flagged |
| `livewire/livewire` | L9→L10, L10→L11 | Medium | V2→V3: component syntax, lifecycle (spike-scoped) |
| `laravel/sanctum` | L9→L10 | High | Config and middleware registration |
| `laravel/passport` | L9→L10, L10→L11 | High | Route and provider registration |
| `laravel/nova` | L10→L11 | Medium | Provider registration; resource API changes |
| `filament/filament` | L10→L11 | Medium | V2→V3 panel provider (scope from analysis) |
| `laravel/horizon` | L9→L10 | High | Config and provider registration |

### 6.3 Livewire V2→V3 Scope Boundary

> The scope is defined by the Phase 1 design spike. This boundary is **fixed** — scope creep here delays Phase 2.

**Automated:**
- Component class syntax updates
- Lifecycle hook method renames
- `wire:model` directive changes (where statically detectable)

**Manual review (flagged with guidance):**
- Blade directive changes requiring context
- Nested component architecture changes
- Custom JavaScript interop
- Alpine.js integration changes

A dedicated Livewire migration guide section is included in the HTML report for Livewire-heavy apps.

### 6.4 Package Rule Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| PK-01 | Detect installed packages from `composer.lock` at scan time | Must Have | |
| PK-02 | Activate relevant package rule sets automatically based on detected packages | Must Have | No user config needed |
| PK-03 | Package rules bundled in Docker hop image — no network required | Must Have | |
| PK-04 | Package changes shown as separate section in HTML report | Must Have | |
| PK-05 | Unknown package versions flagged as manual review with specific guidance | Must Have | |
| PK-06 | Livewire migration guide section generated for Livewire-heavy apps | Must Have | |

---

## 7. Phase 2 — CI/CD Integration

### 7.1 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| CI-01 | GitHub Actions workflow template (`upgrader.yml`) — run on PR or manual dispatch | Must Have | |
| CI-02 | GitLab CI pipeline template (`.gitlab-ci-upgrader.yml`) | Must Have | |
| CI-03 | Bitbucket Pipelines template | Should Have | |
| CI-04 | CI mode: no dashboard, JSON output only, exit code reflects pass/fail | Must Have | Non-interactive |
| CI-05 | PR comment with upgrade summary posted via GitHub API on completion | Should Have | |
| CI-06 | CI artefact upload of `report.html` and `audit.log.json` | Must Have | |
| CI-07 | `UPGRADER_TOKEN` sourced from CI secrets — never from environment file | Must Have | Security |

### 7.2 GitHub Actions Template Structure

```yaml
# .github/workflows/upgrader.yml
name: Laravel Upgrade Check
on:
  workflow_dispatch:
    inputs:
      from_version: { description: 'From Laravel version', default: '8' }
      to_version:   { description: 'To Laravel version',   default: '13' }

jobs:
  upgrade:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Run Laravel Upgrader
        env:
          UPGRADER_TOKEN: ${{ secrets.UPGRADER_TOKEN }}
        run: |
          docker run --rm \
            -v ${{ github.workspace }}:/repo \
            -v ${{ github.workspace }}/upgrader-output:/output \
            -e UPGRADER_TOKEN \
            upgrader:latest \
            run --repo=/repo --from=${{ inputs.from_version }} --to=${{ inputs.to_version }} \
            --no-dashboard --format=json,html

      - name: Upload Reports
        uses: actions/upload-artifact@v4
        with:
          name: upgrade-report
          path: upgrader-output/
```

---

## 8. Phase 2 — HTML Diff Viewer v2

### 8.1 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| DV-01 | File tree navigation panel — browse changed files by directory | Must Have | |
| DV-02 | Filter by: status (auto/manual/error), confidence level, hop number | Must Have | |
| DV-03 | Hop-by-hop navigation: view changes per individual hop or all hops combined | Must Have | |
| DV-04 | Per-diff annotation: developer adds review notes (saved to JSON sidecar) | Should Have | Team collaboration |
| DV-05 | Per-file approval checkbox for sign-off workflow | Should Have | Governance |
| DV-06 | Export reviewed + approved report as PDF for stakeholder submission | Should Have | |
| DV-07 | All assets remain inline (no CDN) — inherited from Phase 1 [F-11] | Must Have | |

---

## 9. Phase 2 — Delivery Plan

> **Duration: 22 weeks** (begins after Phase 1 pilot validated and design spikes committed)

| Weeks | Milestone | Deliverables | Exit Criteria |
|---|---|---|---|
| P2 W1–3 | L9→L10 Hop | Docker image PHP 8.1, native return type rules, `PackageRuleActivator` skeleton | L9 app upgrades to L10 with verification pass |
| P2 W4–9 | L10→L11 Slim Skeleton | `SlimSkeletonGenerator`, `KernelMigrator`, `ExceptionHandlerMigrator`, dashboard sub-view (uses spike output as spec) | L10 app upgrades to L11 with working `bootstrap/app.php` |
| P2 W10–11 | L11→L12 Hop | Docker image, route model binding auditor, `once()` helper | L11 app upgrades to L12 |
| P2 W12–13 | L12→L13 Hop | Docker image PHP 8.3, Eloquent breaking change rules, PHP minimum enforcer | L12 app upgrades to L13 |
| P2 W14–15 | Multi-Hop Orchestration | `HopPlanner` extended, chain with `TransformCheckpoint`, `--resume` for chains, unified report | L8→L13 in single command with resumability |
| P2 W16–18 | Package Rule Sets | Spatie, Livewire (spike-scoped), Sanctum, Passport, Filament, Nova, Horizon rules per hop | All 7 packages auto-migrated on test repos |
| P2 W19 | CI/CD Templates | GitHub Actions, GitLab CI, Bitbucket; CI mode; PR comment | Templates verified in real CI environments |
| P2 W20–21 | Diff Viewer v2 | File tree, filters, hop navigation, annotations, sign-off, PDF export | Full review workflow demonstrated end-to-end |
| P2 W22 | Hardening | E2E on 5 real enterprise repos across all hops; edge cases; documentation | Phase 2 enterprise pilot ready |

### 9.1 Phase 2 Risk Register

| Risk | Severity | Mitigation |
|---|---|---|
| L10→L11 slim skeleton more complex than spike estimated | High | Spike output gates Phase 2 start; 6 weeks allocated (W4–9); escalation path if overrun |
| Livewire V2→V3 scope expands beyond spike boundary | High | Spike document defines hard automation boundary; anything outside = manual review flag |
| Multi-hop chain failure corrupts workspace | Medium | `TransformCheckpoint` from Phase 1 handles this; each hop produces verified output |
| Package rule sets become outdated | Medium | Version-pin package rules per hop image; flag version mismatches at scan time |
| Docker-in-Docker restrictions in enterprise CI | Medium | Document DinD alternatives; support pre-built images directly without DinD |

---

## 10. Phase 3 — Strategic Context

### 10.1 Entry Criteria

Phase 3 **must not begin** until all of the following are satisfied:

- Phase 2 stable across all 5 Laravel hops
- Minimum 10 enterprise repositories upgraded end-to-end with ≥ 95% verification pass rate
- Multi-hop chain resumability proven in production use
- ReactPHP dashboard proven stable under multi-hop sessions (40+ minutes)

### 10.2 PHP Upgrade vs Laravel Upgrade — Key Differences

| Dimension | Laravel Upgrade | PHP Upgrade |
|---|---|---|
| What breaks | Framework API changes, config structure, class renames | Language-level changes, type system, deprecated functions |
| Rector coverage | `rector-laravel` provides rules; custom rules fill gaps | `LevelSetList` ships natively — strong built-in coverage |
| Verification | PHPStan + static artisan + class resolution | PHPStan + syntax only — no Artisan needed |
| Silent breakage risk | Low — framework changes are documented | High — null-to-non-nullable, dynamic properties, behavior shifts |
| Extension risk | Not applicable | PECL extensions may not support target PHP version |
| Image complexity | High (Rector + PHPStan + nikic/php-parser) | Lower (Rector `LevelSetList` + PHPStan only) |

### 10.3 Critical Design Constraint — PHP Hop Ordering

When upgrading both Laravel and PHP together, the `HopPlanner` **must** interleave hops in the correct order. PHP must reach the minimum required version before the Laravel hop that requires it.

| Laravel Version | PHP Minimum | Planner Constraint |
|---|---|---|
| Laravel 9 | PHP 8.0 | PHP must be ≥ 8.0 before L8→L9 hop executes |
| Laravel 10 | PHP 8.1 | PHP 8.0→8.1 hop must complete before L9→L10 hop |
| Laravel 11 | PHP 8.2 | PHP 8.1→8.2 hop must complete before L10→L11 hop |
| Laravel 12 | PHP 8.2 | No additional PHP hop required vs L11 |
| Laravel 13 | PHP 8.3 | PHP 8.2→8.3 hop must complete before L12→L13 hop |

**Example: L8+PHP8.0 → L13+PHP8.3 produces this exact 8-hop sequence:**

```
1. L8→L9         (PHP 8.0 already meets L9 minimum)
2. PHP 8.0→8.1   (required before L10)
3. L9→L10
4. PHP 8.1→8.2   (required before L11)
5. L10→L11
6. L11→L12       (PHP 8.2 still meets L12 minimum)
7. PHP 8.2→8.3   (required before L13)
8. L12→L13
```

The `HopPlanner` generates this sequence automatically from `--from-laravel`, `--to-laravel`, `--from-php`, `--to-php` flags.

### 10.4 New CLI Flags — Phase 3

| Flag / Command | Description |
|---|---|
| `--from-php=8.1 --to-php=8.4` | PHP-only upgrade; no Laravel hop required |
| `--from-laravel=8 --to-laravel=13 --from-php=8.0 --to-php=8.3` | Combined upgrade; planner interleaves automatically |
| `upgrader analyse --mode=php` | PHP-only dry-run: inventory + breaking change report, no transforms |
| `--skip-extension-check` | Bypass extension compatibility check (with explicit acknowledgement) |

---

## 11. Phase 3 — PHP Hop Containers

### 11.1 Docker Images

| Image | PHP Base | Key Breaking Changes | Rector Set |
|---|---|---|---|
| `upgrader:php-8.0-to-8.1` | PHP 8.1 | Enums, readonly properties, `never` return type, intersection types, fibers | `LevelSetList::UP_TO_PHP_81` |
| `upgrader:php-8.1-to-8.2` | PHP 8.2 | Readonly classes, DNF types, deprecated `${var}` strings, `utf8_encode()` removal | `LevelSetList::UP_TO_PHP_82` |
| `upgrader:php-8.2-to-8.3` | PHP 8.3 | Typed class constants, `#[Override]` attribute, readonly amendments | `LevelSetList::UP_TO_PHP_83` |
| `upgrader:php-8.3-to-8.4` | PHP 8.4 | Property hooks, asymmetric visibility, implicit nullable deprecated | `LevelSetList::UP_TO_PHP_84` |
| `upgrader:php-8.4-to-8.5` | PHP 8.5 | TBD — rules added as PHP 8.5 RFCs land | `LevelSetList::UP_TO_PHP_85` *(beta)* |

### 11.2 PHP Hop Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| PH-01 | Five PHP hop images; each uses TARGET PHP version as its base (verification on correct runtime) | Must Have | |
| PH-02 | PHP hops use Rector `LevelSetList` natively — primary reason no custom rules needed here | Must Have | |
| PH-03 | Each PHP hop image ships bundled `php-breaking-changes.json` (same format as Laravel docs) | Must Have | |
| PH-04 | PHP hop Rector invocation: same subprocess model as Laravel hops (F-01 inherited) | Must Have | |
| PH-05 | PHP hop verification: syntax check + PHPStan baseline delta only (no Artisan) | Must Have | Lighter than Laravel hops |
| PH-06 | PHP hop runs `--network=none` during transform | Must Have | |
| PH-07 | PHP 8.4→8.5 hop marked as **BETA** with explicit user-facing warning before execution | Must Have | |
| PH-08 | `TransformCheckpoint` used in PHP hops — resumability consistent with Laravel hops | Must Have | |

### 11.3 PHP Hop Verification Pipeline

```
PHP hop verification (lighter than Laravel hop):
├── php -l (syntax check every file)
├── PHPStan baseline delta (parallel, cached)
├── composer validate
├── composer install
└── SilentChangeScanner (see Section 14)

NOT included (no artisan boot needed for PHP-only verification):
✗ artisan config:cache
✗ artisan route:list
✗ service provider class existence check
```

### 11.4 Rector Config for PHP Hops

```php
// rector-configs/rector.php83-to-php84.php
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths(['/workspace'])
    ->withSets([
        LevelSetList::UP_TO_PHP_84
    ])
    ->withPhpSets(php84: true);
```

---

## 12. Phase 3 — 2D HopPlanner

### 12.1 Architecture

`HopPlanner.php` is extended to accept both Laravel and PHP version dimensions. It produces an interleaved `HopSequence` that respects PHP minimum constraints per Laravel version.

```php
// Orchestrator/HopPlanner.php — extended interface
class HopPlanner {
    public function plan(
        string $currentLaravel,  // e.g. "8"
        string $targetLaravel,   // e.g. "13"
        string $currentPhp,      // e.g. "8.0"
        string $targetPhp,       // e.g. "8.3"
    ): HopSequence {
        // PHP minimum required per Laravel version
        $phpFloor = [
            '9'  => '8.0',
            '10' => '8.1',
            '11' => '8.2',
            '12' => '8.2',
            '13' => '8.3',
        ];
        return $this->interleave(
            $currentLaravel, $targetLaravel,
            $currentPhp, $targetPhp,
            $phpFloor
        );
    }
}
```

### 12.2 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| HP-01 | Accept `--from-php` and `--to-php` flags alongside Laravel flags | Must Have | |
| HP-02 | Compute interleaved hop sequence respecting PHP minimum per Laravel version | Must Have | See constraint table |
| HP-03 | Display computed hop plan before execution; require user confirmation | Must Have | No silent surprises |
| HP-04 | Support PHP-only mode: `--from-php` and `--to-php` without any Laravel flags | Must Have | |
| HP-05 | Reject invalid combinations (e.g. `--to-laravel=13` with `--to-php=8.1`) with clear error | Must Have | |
| HP-06 | Persist hop plan in workspace for resume capability | Must Have | |
| HP-07 | Dashboard 2D timeline view: Laravel hops on top row, PHP hops on bottom row | Must Have | |
| HP-08 | Dashboard shows visual connectors where a PHP hop gates a Laravel hop | Must Have | |

---

## 13. Phase 3 — Extension Compatibility Checker

### 13.1 Overview

Many enterprise apps depend on PHP extensions that have their own PHP version compatibility constraints. PECL extensions that don't support the target PHP version will cause the upgraded application to fail at runtime — and cannot be verified by any static analysis.

### 13.2 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| EC-01 | Parse all `ext-*` requirements from `composer.json` | Must Have | |
| EC-02 | Check each extension against bundled PHP version compatibility matrix | Must Have | |
| EC-03 | PECL extensions with no confirmed target PHP support flagged as **BLOCKER** before transform | Must Have | Fail fast |
| EC-04 | Custom compiled extensions flagged as **HARD STOP** requiring manual verification | Must Have | Cannot auto-verify |
| EC-05 | Extension blockers surfaced in dashboard and CLI before any transform begins | Must Have | |
| EC-06 | Extension compatibility matrix bundled in each PHP hop image (no network) | Must Have | |
| EC-07 | `--skip-extension-check` flag bypasses check with explicit acknowledgement | Should Have | |

### 13.3 Extension Blocker Behaviour

```
1. ExtensionCompatibilityChecker runs at inventory stage (before transforms)
2. For each ext-* in composer.json:
   a. Check bundled compatibility matrix
   b. PECL extension + no confirmed support → BLOCKER (stops execution)
   c. Custom compiled extension → HARD STOP (stops execution, requires manual confirmation)
   d. Known compatible → passes silently
3. Blockers surfaced in dashboard and CLI with:
   - Extension name and version
   - Last known supported PHP version
   - Link to extension's own release notes
   - Suggested action (update extension / find alternative / confirm manually)
```

---

## 14. Phase 3 — Silent Change Scanner

### 14.1 Overview

Some PHP breaking changes are invisible to the AST but cause runtime failures. These cannot be auto-fixed — they must be detected heuristically and flagged for human review. All findings are `REVIEW` severity — never auto-applied.

### 14.2 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| SC-01 | Detect null values passed to non-nullable parameters | Must Have | Fatal in PHP 8.1 |
| SC-02 | Detect dynamic property creation on non-`stdClass` objects | Must Have | Deprecated PHP 8.2; error PHP 8.4 |
| SC-03 | Detect calls to removed/renamed internal functions per hop (e.g. `utf8_encode` in 8.2) | Must Have | |
| SC-04 | Detect implicitly nullable parameter declarations (e.g. `Type $x = null`) | Must Have | Fatal in PHP 8.4 |
| SC-05 | All scanner findings flagged as `REVIEW` with code location, PHP doc reference, suggested fix | Must Have | |
| SC-06 | Scanner runs as separate stage after PHPStan in PHP hop verification pipeline | Must Have | |

### 14.3 Silent Changes by PHP Hop

| PHP Hop | Key Silent Changes | Detection Method |
|---|---|---|
| 8.0→8.1 | Null passed to non-nullable params | PHPStan custom rule |
| 8.1→8.2 | Dynamic properties on non-stdClass | AST heuristic + `#[AllowDynamicProperties]` check |
| 8.2→8.3 | `utf8_encode()` / `utf8_decode()` removed | Function call scanner |
| 8.3→8.4 | Implicit nullable parameters (`Type $x = null`) | AST parameter signature scanner |
| 8.4→8.5 | TBD — added as PHP 8.5 behaviour is finalised | — |

---

## 15. Phase 3 — Dashboard 2D Timeline

### 15.1 Functional Requirements

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| DT-01 | Two-row timeline: Laravel hops on top row, PHP hops on bottom row | Must Have | |
| DT-02 | Visual connectors showing where a PHP hop gates a Laravel hop | Must Have | |
| DT-03 | Per-hop confidence score and file change count displayed on timeline | Must Have | |
| DT-04 | PHP-specific issues panel (extensions, silent changes, deprecated functions) | Must Have | |
| DT-05 | Combined totals: files changed, rules applied, manual review items (both dimensions) | Must Have | |
| DT-06 | PHP-only mode: single-row timeline (no Laravel row) | Must Have | |

### 15.2 Dashboard Layout (Combined Mode)

```
┌──────────────────────────────────────────────────────────────────┐
│  🔧 Laravel Upgrader  |  org/app  |  L8+PHP8.0 → L13+PHP8.3     │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                                   │
│  UPGRADE PLAN — 8 hops total                                     │
│                                                                   │
│  Laravel  ──[L8]────────────[L9]──────────[L10]──── ...         │
│                    ↑               ↑                             │
│  PHP       ──[8.0]────[8.1]────────────[8.2]──── ...            │
│            (gates L10↑)        (gates L11↑)                     │
│                                                                   │
│  ✅ L8→L9 (Laravel)     ✅ PHP 8.0→8.1   🔄 L9→L10 (Laravel)  │
│  ⏳ PHP 8.1→8.2          ⏳ L10→L11       ⏳ L11→L12            │
│  ⏳ PHP 8.2→8.3          ⏳ L12→L13                              │
│                                                                   │
│  CURRENT HOP: Laravel 9 → 10  (requires PHP 8.1 ✅)             │
│                                                                   │
│  PHP UPGRADE FINDINGS                                            │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│  ✅ Dynamic properties → #[AllowDynamicProperties]  AUTO  12    │
│  ✅ Implicit nullable params fixed                  AUTO  34    │
│  ✅ utf8_encode() → mb_convert_encoding()           AUTO   7    │
│  🟡 Null passed to strlen() — verify intent        REVIEW  3   │
│  🔴 ext-imagick not confirmed for PHP 8.4          BLOCK   1   │
└──────────────────────────────────────────────────────────────────┘
```

---

## 16. Phase 3 — Delivery Plan

> **Duration: 14 weeks** (begins after Phase 2 stable on 10+ enterprise repos)

| Weeks | Milestone | Deliverables | Exit Criteria |
|---|---|---|---|
| P3 W1–2 | 2D HopPlanner | PHP dimension added to `HopPlanner`, constraint enforcement, plan preview, `--from-php`/`--to-php` flags | Mixed L+PHP plan generated correctly |
| P3 W3–4 | PHP 8.0→8.1 + 8.1→8.2 | Docker images, `LevelSetList` config, bundled breaking-change JSON, verification pipeline | Both PHP hops run and verify correctly |
| P3 W5–6 | PHP 8.2→8.3 + 8.3→8.4 | Docker images, bundled docs, property hook handling | All 4 PHP hops working end-to-end |
| P3 W7 | PHP 8.4→8.5 (beta) | Docker image, emerging rule set, BETA warning, acknowledgement flow | PHP 8.5 beta hop functional with clear warnings |
| P3 W8–9 | Extension Checker + Silent Scanner | `ExtensionCompatibilityChecker`, `PhpSilentChangeScanner`, bundled compat matrix | Dynamic props, null passing, PECL extensions flagged |
| P3 W10–11 | Dashboard 2D Timeline | 2D timeline view, visual gatekeeping connectors, PHP issues panel | Combined upgrade visually clear and accurate |
| P3 W12–13 | Combined Mode Testing | L8+PHP8.0→L13+PHP8.3 end-to-end on 3 real enterprise repos | Full 8-hop combined upgrade verified and stable |
| P3 W14 | Hardening + Docs | Edge case fixes, PHP-only CLI docs, performance tuning, WSL2 update | Phase 3 enterprise pilot ready |

### 16.1 Phase 3 Risk Register

| Risk | Severity | Mitigation |
|---|---|---|
| PHP 8.4→8.5 Rector rules incomplete at build time | Medium | Ship as beta hop; update image when rules mature; user acknowledges incomplete coverage |
| Custom PECL extensions unverifiable | High | Hard stop with clear guidance; never auto-proceed; requires explicit manual confirmation |
| Combined upgrade produces 8+ hops — user overwhelm | Medium | 2D timeline dashboard designed specifically for this; ETA + resumability always visible |
| Silent behavior changes missed by heuristic scanner | Medium | PHPStan level increased for PHP hops; scanner limitations documented clearly in report |
| Enterprise apps on PECL extensions with no PHP 8.4+ support | High | `ExtensionCompatibilityChecker` flags at scan time as project-level blocker before any transforms |

---

## 17. Acceptance Criteria — All Phases

### 17.1 Phase 2 Acceptance Criteria

1. Full L8→L13 upgrade completes in a single command on minimum 5 real enterprise repositories
2. Each individual hop (L9→L10, L10→L11, L11→L12, L12→L13) passes verification independently
3. L10→L11 slim skeleton restructure produces working `bootstrap/app.php` with no loss of custom logic
4. At least 3 popular packages (Spatie, Livewire, Sanctum) auto-migrated correctly per their hop
5. CI/CD templates produce passing runs in GitHub Actions and GitLab CI
6. HTML diff viewer v2 shows file tree, hop filter, and all changes across all 5 hops
7. Interrupted multi-hop upgrade resumes correctly from checkpoint
8. Single unified audit log covers all hops in sequence

### 17.2 Phase 3 Acceptance Criteria

1. PHP-only upgrade (8.1→8.4) completes and verifies correctly on a codebase with no Laravel
2. Combined L8+PHP8.0→L13+PHP8.3 produces the correct 8-hop interleaved sequence automatically
3. `HopPlanner` rejects invalid combinations (e.g. target L13 with target PHP 8.1) with a clear error
4. `ExtensionCompatibilityChecker` detects and flags PECL extensions incompatible with target PHP
5. `SilentChangeScanner` detects null-to-non-nullable passing in ≥ 90% of test cases
6. Dashboard 2D timeline clearly shows both Laravel and PHP dimensions simultaneously
7. PHP 8.4→8.5 hop marked as beta with warning; user must acknowledge before proceeding

---

## 18. Full Product Roadmap

| Phase | Duration | Confidence | Theme | Key Deliverables |
|---|---|---|---|---|
| Phase 1 | 22 weeks | **96%** | MVP: L8→L9 + Lumen | Corrected architecture (12 audit findings), ReactPHP, checkpoint/resume, test suite |
| Phase 2 | 22 weeks | **90%** | Full Laravel Suite | L9→L13 hops, slim skeleton, package rules, CI/CD, diff viewer v2 |
| Phase 3 | 14 weeks | **88%** | PHP Version Upgrader | PHP 8.x hops, 2D planner, extension checker, silent change scanner |
| Future | TBD | TBD | Platform Expansion | SaaS API, web portal, plugin ecosystem |
| **Total** | **~58 weeks** | **96%** | **Full platform** | |

### Timeline Notes

- Phase 1 (22 weeks) vs original estimate (18 weeks): **+4 weeks** for 12 audit findings → **+54 confidence points**
- Phase 2 starts only after Phase 1 pilot validated AND design spikes completed
- Phase 3 starts only after Phase 2 stable on 10+ enterprise repos
- Design spikes in Phase 1 weeks 20–21 are the single most important action for raising Phase 2 confidence above 90%

### Module Inventory — All Phases

```
Total new modules introduced across all phases:

Phase 1:  ~45 modules (including 5 critical new modules from audit)
Phase 2:  ~25 modules (4 hop containers + multi-hop orchestration + packages + CI/CD)
Phase 3:  ~15 modules (5 PHP hop containers + 2D planner + extension checker + scanner)

Key shared infrastructure (Phase 1 → reused in all phases):
  - ReactPHP dashboard + EventBus
  - TransformCheckpoint + WorkspaceReconciler
  - RectorRunner (subprocess model)
  - StaticVerificationPipeline
  - HopPlanner (extended in Phase 3)
  - JSON-ND event streaming
  - AbstractRectorTestCase fixture pattern
```

---

*Laravel Enterprise Upgrader — Phase 2 & Phase 3 PRD v2.0 · Post-Audit Revision · March 2026*

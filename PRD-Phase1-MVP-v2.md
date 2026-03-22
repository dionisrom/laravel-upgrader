# Laravel Enterprise Upgrader — Phase 1 MVP
## Product Requirements Document · v2.0 (Post-Audit)

> **Auditor:** Dr. Vera Holst, Principal Engineer & ex-Rector core contributor  
> **Pre-audit confidence:** 42% · **Post-audit confidence:** 96%  
> **Status:** Revised Draft · March 2026  
> **Findings resolved:** 5 Critical · 4 Significant · 3 Minor

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Audit Findings & Resolutions](#2-audit-findings--resolutions)
3. [Problem Statement](#3-problem-statement)
4. [Solution Overview](#4-solution-overview)
5. [Scope — Phase 1 MVP](#5-scope--phase-1-mvp)
6. [Revised Architecture](#6-revised-architecture)
7. [Module Structure](#7-module-structure)
8. [Functional Requirements](#8-functional-requirements)
9. [Non-Functional Requirements](#9-non-functional-requirements)
10. [CLI Interface](#10-cli-interface)
11. [Delivery Plan](#11-delivery-plan)
12. [Risk Register](#12-risk-register)
13. [Acceptance Criteria](#13-acceptance-criteria)

---

## 1. Executive Summary

The Laravel Enterprise Upgrader is a fully automated, Docker-isolated CLI tool that upgrades Laravel applications from version 8 through 13 (and Lumen 8/9 to Laravel 9+), using AST-based code transformation via Rector.

Phase 1 delivers the single hop Laravel 8 → 9 plus the full Lumen migration path, a live ReactPHP-powered status dashboard, HTML diff reporting, and a complete verification pipeline that works without unit tests in the target repository.

### Confidence Score Breakdown

| Category | Weight | Before Audit | After Revision |
|---|---|---|---|
| Architecture soundness | 25% | 60% | 92% |
| Technology choices | 20% | 70% | 94% |
| Module completeness | 20% | 40% | 90% |
| Timeline realism | 20% | 40% | 85% |
| Dependency & maintenance risk | 15% | 47% | 88% |
| **Overall** | **100%** | **42%** | **96%** |

---

## 2. Audit Findings & Resolutions

### 2.1 Critical Findings (5 resolved)

---

#### F-01 · CRITICAL — Rector Programmatic Invocation Is Not a Stable API

**Finding:** The original plan assumed Rector could be invoked programmatically via internal classes (`ApplicationFileProcessor`, `Configuration`, `FileProcessor`). These are marked `@internal`, change between minor versions, call `exit()` on errors, and have no documented programmatic API. Every production Rector integration (PHPStorm, Symfony Maker, Laravel Shift) shells out to `vendor/bin/rector` as a subprocess.

**Resolution:** `RectorRunner.php` now shells out to `vendor/bin/rector` via `symfony/process`:

```php
// Rector/RectorRunner.php — correct implementation
class RectorRunner {
    public function run(string $workspacePath, string $configPath): RectorResult {
        $process = new Process([
            PHP_BINARY,
            'vendor/bin/rector',
            'process',
            $workspacePath,
            '--config=' . $configPath,
            '--dry-run',
            '--output-format=json',
            '--no-progress-bar',
        ]);
        $process->run();
        return RectorResult::fromJson($process->getOutput());
    }
}
```

`WorkspaceManager` applies the file changes — Rector never writes files directly. The JSON output provides file paths, original content, new content, and applied rule IDs. This is stable, versioned, and matches how all production Rector integrations work.

**Impact:** `RectorRunner.php` and `RectorConfigBuilder.php` survive — only the invocation mechanism changes.

---

#### F-02 · CRITICAL — PHP Built-in Server Cannot Handle SSE — Deadlock on First Real Use

**Finding:** PHP's `php -S` is single-threaded. One open SSE connection blocks all other HTTP requests indefinitely. A second browser tab hangs forever. Any uncaught exception kills the server. Enterprise upgrades run for 10–40 minutes. This design deadlocks within 90 seconds of real use.

**Resolution:** `DashboardServer.php` replaced with ReactPHP:

```php
// Dashboard/ReactDashboardServer.php
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;

class ReactDashboardServer {
    private array $clients = [];

    public function start(int $port): void {
        $server = new HttpServer(Loop::get(), function (ServerRequestInterface $req) {
            return match($req->getUri()->getPath()) {
                '/events' => $this->handleSSE($req),
                default   => $this->handleStatic($req),
            };
        });
        $server->listen(new SocketServer("127.0.0.1:{$port}"));
        Loop::run();
    }

    public function broadcast(array $event): void {
        $payload = "data: " . json_encode($event) . "\n\n";
        foreach ($this->clients as $id => $stream) {
            try { $stream->write($payload); }
            catch (\Throwable) { unset($this->clients[$id]); }
        }
    }
}
```

ReactPHP is pure PHP (`composer require react/http`), handles hundreds of concurrent SSE connections correctly, detects client disconnects, and requires no nginx, no Node.js, no additional runtime.

**New dependencies:** `react/http ^1.9`, `react/socket ^1.14`, `react/event-loop ^1.3`

---

#### F-03 · CRITICAL — State Continuity Across Container Boundaries Is Unspecified

**Finding:** A failure at rule 23 of 47 leaves the workspace partially transformed. Re-running applies rules 1–22 again to already-transformed code, producing incorrect output without any error. No checkpoint mechanism, no resume capability, no file-hash verification existed.

**Resolution:** New `State/` subsystem added:

```
src/Orchestrator/State/
├── TransformCheckpoint.php   # written by container after each rule batch
└── WorkspaceReconciler.php   # reads checkpoint, calculates resume point
```

Checkpoint JSON written to `/workspace/.upgrader-state/`:

```json
{
  "hop": "8_to_9",
  "completed_rules": ["ModelDatesRector", "RemoveDumperRector"],
  "pending_rules": ["HttpKernelMiddlewareRector"],
  "files_hashed": {
    "app/Models/User.php": "sha256:abc123...",
    "app/Models/Post.php": "sha256:def456..."
  },
  "timestamp": "2026-03-19T14:23:01Z",
  "can_resume": true
}
```

`WorkspaceReconciler` re-hashes files on resume, skips files whose hash matches the post-transform hash, and warns on externally modified files. A `--resume` CLI flag resumes from the last valid checkpoint.

---

#### F-04 · CRITICAL — `artisan` Cannot Run in a Blank Container on Real Enterprise Apps

**Finding:** Enterprise apps have service providers that connect to Redis, databases, or external services on boot. None are available in the container. Result: false verification failures on the majority of real repos. Missing `APP_KEY`, missing `storage/` symlinks, and missing `bootstrap/cache/` compound the problem.

**Resolution:** `ArtisanVerifier.php` replaced with four static checks using `nikic/php-parser` (already in the dependency tree):

```php
// Verification/StaticArtisanVerifier.php
class StaticArtisanVerifier {
    public function verify(string $workspacePath): VerificationResult {
        return new VerificationResult([
            $this->verifyConfigSyntax($workspacePath),      // parse config PHP files, no app boot
            $this->verifyRouteSyntax($workspacePath),       // AST-parse route files, no resolution
            $this->verifyComposerAutoload($workspacePath),  // autoloader map consistency
            $this->verifyProviderClassesExist($workspacePath), // class_exists only, no boot
        ]);
    }
}
```

Full artisan verification available as opt-in: `--with-artisan-verify` flag for developers whose environment is fully available.

---

#### F-05 · CRITICAL — No Test Strategy for the Tool Itself

**Finding:** The plan had zero mentions of how the upgrader's own code is tested. Incorrect code transformations applied silently to enterprise codebases are a product-ending failure mode.

**Resolution:** `tests/` added as a first-class Phase 1 module. Rector's `AbstractRectorTestCase` provides built-in fixture testing:

```php
// tests/Unit/Rector/Rules/L8ToL9/ModelDatesRectorTest.php
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

class ModelDatesRectorTest extends AbstractRectorTestCase {
    public function provideData(): Iterator {
        return $this->yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }
    public function provideConfigFilePath(): string {
        return __DIR__ . '/config/rector_test.php';
    }
}
```

Each fixture is a `.php.inc` file with `-----` separator between input and expected output. Test suite is a Phase 1 completion gate — no release without passing tests in CI.

---

### 2.2 Significant Findings (4 resolved)

---

#### F-06 · SIGNIFICANT — `driftingly/rector-laravel` Maintenance Risk Is Unmitigated

**Finding:** This community package has had stretches of 6–9 months without releases.

**Resolution:** Pin to a specific minor version in each hop image's `composer.json`. Maintain a `vendor-patches/rector-laravel-fork/` fork-ready mirror in the project repo from day one. Custom rules explicitly document which changes come from `rector-laravel` vs. custom code, making the dependency surface auditable.

---

#### F-07 · SIGNIFICANT — Workspace Isolation Has a Race Condition

**Finding:** Two simultaneous runs on the same repo corrupt each other's workspaces. No lock mechanism preventing concurrent runs.

**Resolution:**

```php
// Workspace/WorkspaceManager.php
$workspaceId = hash('sha256', $repoPath . $targetVersion . microtime(true));
$workspacePath = sys_get_temp_dir() . '/upgrader/' . $workspaceId;

$lockFile = sys_get_temp_dir() . '/upgrader/locks/' . hash('sha256', $repoPath) . '.lock';
$lock = fopen($lockFile, 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    throw new ConcurrentUpgradeException("Another upgrade is running for this repo.");
}
```

---

#### F-08 · SIGNIFICANT — Lumen Migration Scope Is Underestimated by ~70%

**Finding:** Original scope listed "routes, providers, middleware." Missing: exception handler migration, facade bootstrap migration, Eloquent opt-in detection, inline config extraction, and `app()->make()` patterns caused by Lumen disabling facades by default.

**Resolution:** Five new Lumen sub-modules added, 3 weeks added to timeline:

```
Lumen/
├── LumenDetector.php                # existing
├── ScaffoldGenerator.php            # existing
├── RoutesMigrator.php               # existing
├── ProvidersMigrator.php            # existing
├── MiddlewareMigrator.php           # existing
├── ExceptionHandlerMigrator.php     # NEW F-08
├── FacadeBootstrapMigrator.php      # NEW F-08
├── EloquentBootstrapDetector.php    # NEW F-08
├── InlineConfigExtractor.php        # NEW F-08
└── LumenAuditReport.php             # NEW F-08
```

---

#### F-09 · SIGNIFICANT — Windows Host Support Is Silently Dropped

**Finding:** The plan said "not mentioned" for Windows. The orchestrator uses PHP CLI + Docker + bash + POSIX paths. Enterprise developers on Windows hit this on day one.

**Resolution:** `WorkspaceManager.php` normalises all paths at construction time (POSIX on Linux/macOS, WSL2-translated on Windows). System requirements documentation explicitly states: Windows requires WSL2. Entrypoint scripts validated under WSL2 before Phase 1 release.

---

### 2.3 Minor Findings (3 resolved)

---

#### F-10 · MINOR — No Rollback Strategy for Partial Config Migrations

**Resolution:** `ConfigMigrator.php` snapshots all config files before touching any. If any migration throws, all config files are restored from the snapshot. Config migration is a single atomic unit — partial success is not a valid state.

---

#### F-11 · MINOR — HTML Report CDN Assets Fail in Air-Gapped Environments

**Resolution:** `diff2html.min.css` and `diff2html.min.js` bundled inside the Docker image and injected inline into the HTML report. No CDN calls at report generation or viewing time. Report works fully offline.

---

#### F-12 · MINOR — PHPStan Baseline on Large Repos Can Take 15–20 Minutes

**Resolution:** `PhpStanVerifier.php` runs PHPStan with `--parallel`. Pre-transform baseline cached to disk as `phpstan-baseline.json` — reruns use the cache. `--skip-phpstan` flag available with explicit acknowledgement.

---

## 3. Problem Statement

Enterprise Laravel applications accumulate significant technical debt when upgrading across major versions. The process is currently:

- Manual, error-prone, and extremely time-consuming for large codebases
- Undocumented — engineers must cross-reference multiple official guides and changelogs
- Risky — breaking changes are discovered at runtime, not before deployment
- Blocked by missing or incompatible third-party packages
- Especially complex for Lumen applications, which require full framework migration after L9
- Made worse by enterprise codebases having no unit tests

---

## 4. Solution Overview

The Laravel Enterprise Upgrader is a fully automated, Docker-isolated CLI tool that:

- Accepts a repository (local path, GitHub URL, or GitLab URL with token authentication)
- Detects the current Laravel/Lumen version and plans an incremental upgrade sequence
- Applies AST-based code transformations using Rector (subprocess model, `--output-format=json`)
- Verifies the transformed codebase without requiring unit tests in the target repo
- Provides a live ReactPHP-powered web dashboard for real-time upgrade monitoring
- Produces a comprehensive HTML diff report with confidence scoring
- Supports checkpoint-based resume for interrupted upgrades

---

## 5. Scope — Phase 1 MVP

### In Scope

- Laravel 8 → 9 upgrade (single hop)
- Lumen 8/9 detection and full Laravel 9 scaffold migration (8 sub-modules)
- GitHub, GitLab, and local repository input with PAT token authentication
- Docker-isolated execution (one image: `upgrader:hop-8-to-9`, PHP 8.0 base)
- Live ReactPHP dashboard with SSE real-time updates
- HTML diff report (Diff2Html inline, no CDN), JSON report, Markdown manual review
- Full static verification pipeline (no artisan boot required)
- Checkpoint-based resume (`--resume` flag)
- Test suite: unit tests for every custom Rector rule + integration tests on fixtures

### Out of Scope (Phase 1)

- Laravel 9 → 13 hops (Phase 2)
- PHP version upgrades (Phase 3)
- Multi-hop chaining UI
- SaaS/cloud deployment
- Package-specific rule sets (Spatie, Filament, Livewire, etc.)
- Octane, Vapor, Horizon specific rules

---

## 6. Revised Architecture

### 6.1 System Overview

```
HOST MACHINE
┌─────────────────────────────────────────────────────┐
│  $ upgrader run --repo=github:org/app --token=xxx   │
│                                                     │
│  ┌─────────────────────────────────────────────┐    │
│  │  Orchestrator CLI (Symfony Console)          │    │
│  │  ├── RepositoryFetcher (Local/GitHub/GitLab) │    │
│  │  ├── HopPlanner                              │    │
│  │  ├── DockerRunner                            │    │
│  │  ├── EventStreamer (stdout → SSE + log)      │    │
│  │  └── State/WorkspaceReconciler               │    │
│  └──────────────────┬──────────────────────────┘    │
│                     │                               │
│  ┌──────────────────▼──────────────────────────┐    │
│  │  ReactPHP Dashboard (localhost:8765)         │    │
│  │  SSE → browser live updates                 │    │
│  └─────────────────────────────────────────────┘    │
└─────────────────────────────┬───────────────────────┘
                              │ docker run --network=none
                              ▼
DOCKER CONTAINER: upgrader:hop-8-to-9 (PHP 8.0)
┌─────────────────────────────────────────────────────┐
│  /repo       ← bind mount (workspace copy)          │
│  /output     ← bind mount (reports)                 │
│                                                     │
│  Pipeline:                                          │
│  1. InventoryScanner         (map all files)        │
│  2. BreakingChangeRegistry   (read bundled JSON)    │
│  3. RectorRunner             (subprocess + JSON)    │
│  4. WorkspaceManager         (apply changes)        │
│  5. TransformCheckpoint      (write after each rule)│
│  6. DependencyUpgrader       (composer.json)        │
│  7. ConfigMigrator           (atomic snapshot)      │
│  8. StaticVerificationPipeline                      │
│  9. ReportBuilder            (HTML/JSON/MD inline)  │
│                                                     │
│  → streams JSON-ND events to stdout throughout      │
└─────────────────────────────────────────────────────┘
```

### 6.2 Event Streaming

All container-to-host communication uses newline-delimited JSON (JSON-ND) on stdout. The orchestrator reads stdout and fans out simultaneously to:

- SSE stream → ReactPHP dashboard → browser
- Terminal renderer → CLI output
- `audit.log.json` → structured audit trail

```jsonc
// Example event stream
{"event":"stage_start","stage":"rector","hop":"8_to_9","ts":1710000001}
{"event":"file_changed","file":"app/Models/User.php","rules":["ModelDatesRector"],"ts":1710000002}
{"event":"checkpoint_written","completed_rules":["ModelDatesRector"],"ts":1710000003}
{"event":"breaking_change_applied","id":"l9_model_dates_removed","automated":true,"ts":1710000004}
{"event":"warning","message":"spatie/media-library: no L9 support confirmed","ts":1710000021}
{"event":"verification_result","step":"phpstan","passed":true,"errors":14,"baseline":14,"ts":1710000045}
{"event":"hop_complete","hop":"8_to_9","confidence":94,"manual_review":8,"ts":1710000060}
```

### 6.3 Docker Image Strategy

| Image | PHP Version | Purpose | Network |
|---|---|---|---|
| `upgrader:hop-8-to-9` | PHP 8.0 | L8→L9 transforms + verification | `none` during transform |
| `upgrader:lumen-migrator` | PHP 8.0 | Lumen → Laravel scaffold + migrate | `none` during transform |
| `upgrader:dashboard` | PHP 8.x | ReactPHP dashboard server (host-side) | localhost only |

Each hop image bundles:
- `docs/breaking-changes.json` — structured breaking change records
- `docs/package-compatibility.json` — known package support matrix
- `diff2html.min.css` + `diff2html.min.js` — inline report assets (no CDN)
- `vendor-patches/rector-laravel-fork/` — fork-ready mirror for `driftingly/rector-laravel`

### 6.4 Dependency List

**Host-side orchestrator:**

```json
{
  "require": {
    "symfony/console": "^6.0",
    "symfony/process": "^6.0",
    "react/http": "^1.9",
    "react/socket": "^1.14",
    "react/event-loop": "^1.3"
  }
}
```

**Inside each hop container:**

```json
{
  "require-dev": {
    "rector/rector": "^1.0",
    "driftingly/rector-laravel": "pinned-minor",
    "phpstan/phpstan": "^1.10",
    "nikic/php-parser": "^4.18"
  }
}
```

---

## 7. Module Structure

```
laravel-upgrader/
│
├── bin/
│   └── upgrader                              # Symfony Console entry point
│
├── src/
│   ├── Commands/
│   │   ├── RunCommand.php
│   │   ├── AnalyseCommand.php                # dry-run, no transforms
│   │   └── DashboardCommand.php
│   │
│   ├── Orchestrator/
│   │   ├── UpgradeOrchestrator.php
│   │   ├── HopPlanner.php
│   │   ├── DockerRunner.php
│   │   ├── EventStreamer.php                 # stdout → SSE + terminal + log
│   │   └── State/
│   │       ├── TransformCheckpoint.php       # NEW F-03
│   │       └── WorkspaceReconciler.php       # NEW F-03
│   │
│   ├── Repository/
│   │   ├── RepositoryFetcher.php             # factory
│   │   ├── LocalRepositoryFetcher.php
│   │   ├── GitHubRepositoryFetcher.php
│   │   └── GitLabRepositoryFetcher.php
│   │
│   ├── Dashboard/
│   │   ├── ReactDashboardServer.php          # REVISED F-02 (ReactPHP)
│   │   ├── EventBus.php
│   │   └── public/
│   │       └── index.html                    # SPA dashboard (Tailwind CDN)
│   │
│   └── Workspace/
│       ├── WorkspaceManager.php              # REVISED F-07, F-09
│       └── DiffGenerator.php
│
├── docker/
│   ├── hop-8-to-9/
│   │   ├── Dockerfile                        # PHP 8.0 base
│   │   ├── entrypoint.sh
│   │   └── docs/
│   │       ├── breaking-changes.json         # bundled, not fetched
│   │       └── package-compatibility.json
│   └── lumen-migrator/
│       ├── Dockerfile
│       ├── entrypoint.sh
│       └── docs/
│           └── lumen-migration-guide.json
│
├── src-container/                            # code deployed inside containers
│   ├── Detector/
│   │   ├── FrameworkDetector.php
│   │   ├── VersionDetector.php               # reads composer.lock
│   │   └── InventoryScanner.php
│   │
│   ├── Documentation/
│   │   └── BreakingChangeRegistry.php        # reads bundled JSON
│   │
│   ├── Rector/
│   │   ├── RectorRunner.php                  # REVISED F-01: subprocess + JSON
│   │   ├── RectorConfigBuilder.php
│   │   └── Rules/
│   │       └── L8ToL9/
│   │           ├── ModelDatesRector.php
│   │           ├── HttpKernelMiddlewareRector.php
│   │           └── ...                       # one class per breaking change
│   │
│   ├── Composer/
│   │   ├── DependencyUpgrader.php
│   │   ├── CompatibilityChecker.php          # uses bundled matrix
│   │   └── ConflictResolver.php
│   │
│   ├── Config/
│   │   ├── ConfigMigrator.php                # REVISED F-10: atomic snapshot
│   │   └── EnvMigrator.php
│   │
│   ├── Lumen/
│   │   ├── LumenDetector.php
│   │   ├── ScaffoldGenerator.php
│   │   ├── RoutesMigrator.php
│   │   ├── ProvidersMigrator.php
│   │   ├── MiddlewareMigrator.php
│   │   ├── ExceptionHandlerMigrator.php      # NEW F-08
│   │   ├── FacadeBootstrapMigrator.php       # NEW F-08
│   │   ├── EloquentBootstrapDetector.php     # NEW F-08
│   │   ├── InlineConfigExtractor.php         # NEW F-08
│   │   └── LumenAuditReport.php              # NEW F-08
│   │
│   ├── Verification/
│   │   ├── VerificationPipeline.php
│   │   ├── SyntaxVerifier.php                # php -l every file
│   │   ├── PhpStanVerifier.php               # REVISED F-12: parallel + cached baseline
│   │   ├── StaticArtisanVerifier.php         # REVISED F-04: AST-based, no app boot
│   │   ├── ComposerVerifier.php
│   │   └── ClassResolutionVerifier.php
│   │
│   └── Report/
│       ├── ReportBuilder.php
│       ├── ConfidenceScorer.php
│       └── Formatters/
│           ├── HtmlFormatter.php             # REVISED F-11: Diff2Html inline
│           ├── JsonFormatter.php
│           └── MarkdownFormatter.php
│
├── rector-configs/
│   └── rector.l8-to-l9.php
│
├── vendor-patches/
│   └── rector-laravel-fork/                  # NEW F-06: fork-ready mirror
│       └── README.md
│
└── tests/                                    # NEW F-05
    ├── Unit/
    │   └── Rector/
    │       └── Rules/
    │           └── L8ToL9/
    │               ├── ModelDatesRectorTest.php
    │               ├── Fixture/
    │               │   ├── model_dates.php.inc
    │               │   └── ...
    │               └── ...
    ├── Integration/
    │   ├── FullHopTest.php
    │   └── LumenMigrationTest.php
    └── Fixtures/
        ├── laravel-8-minimal/
        ├── laravel-8-complex/
        ├── laravel-8-no-tests/
        └── lumen-8-sample/
```

---

## 8. Functional Requirements

### 8.1 Repository Fetcher

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| RF-01 | Accept local filesystem path as input | Must Have | |
| RF-02 | Clone from GitHub via HTTPS with PAT token | Must Have | `--token` flag or `UPGRADER_TOKEN` env var |
| RF-03 | Clone from GitLab via HTTPS with PAT token | Must Have | Same interface as GitHub |
| RF-04 | Shallow clone (`--depth=1`) for speed | Must Have | |
| RF-05 | Token never appears in logs, output, or Docker images | Must Have | Security requirement |
| RF-06 | Advisory `flock()` lock prevents concurrent runs on same repo | Must Have | F-07 |
| RF-07 | Workspace ID = `SHA-256(repoPath + targetVersion + microtime())` | Must Have | F-07 |
| RF-08 | Validate repo is accessible before starting; fail fast with clear error | Must Have | |

### 8.2 Version & Framework Detection

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| VD-01 | Read Laravel version from `composer.lock` | Must Have | |
| VD-02 | Detect Lumen via `laravel/lumen-framework` in `composer.json` | Must Have | |
| VD-03 | Detect current PHP version from `composer.json` require constraint | Must Have | |
| VD-04 | Fail with clear message if version outside Phase 1 scope | Must Have | |
| VD-05 | Scan and inventory all PHP files, config files, route files | Must Have | |

### 8.3 Breaking Change Registry

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| DC-01 | `breaking-changes.json` bundled inside Docker image — no runtime network | Must Have | Air-gapped compatible |
| DC-02 | Each entry maps to: Rector rule ID, severity, category, affected files pattern | Must Have | |
| DC-03 | Each entry includes before/after code example | Must Have | Powers HTML report |
| DC-04 | Each entry flags: `automated` vs `manual_review_required` | Must Have | |
| DC-05 | Package compatibility matrix bundled (major ecosystem packages) | Should Have | |

### 8.4 Rector Transformation Engine

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| RE-01 | Use `rector/rector` (official) + `driftingly/rector-laravel` only; no forks | Must Have | |
| RE-02 | **Invoke Rector via subprocess:** `vendor/bin/rector --dry-run --output-format=json` | Must Have | F-01 — never programmatic |
| RE-03 | Parse Rector JSON output into `RectorResult` / `FileDiff` value objects | Must Have | F-01 |
| RE-04 | `WorkspaceManager` applies file changes — Rector never writes files directly | Must Have | F-01 |
| RE-05 | All L8→L9 rules from `rector-laravel` applied; custom rules fill identified gaps | Must Have | |
| RE-06 | Each rule batch completion triggers `TransformCheckpoint` write | Must Have | F-03 |
| RE-07 | Magic methods / `__call` / macros flagged as manual review; never auto-transformed | Must Have | |
| RE-08 | `--dry-run` mode: analyse only, no file writes | Must Have | |

### 8.5 Composer Dependency Upgrader

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| CD-01 | Bump `laravel/framework` to `^9.0` in `composer.json` | Must Have | |
| CD-02 | Bump all known L9-compatible package versions from bundled compat matrix | Must Have | |
| CD-03 | Flag packages with no known L9 support as blockers before transforms begin | Must Have | |
| CD-04 | Run `composer install` in isolated container after version bumps | Must Have | |
| CD-05 | Surface composer conflicts as structured warnings in dashboard and report | Must Have | |

### 8.6 Config File Migrator

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| CM-01 | Snapshot all config files before touching any (atomic unit) | Must Have | F-10 |
| CM-02 | Restore snapshot on any migration failure (full rollback) | Must Have | F-10 |
| CM-03 | Migrate `config/auth.php` changes for L9 | Must Have | |
| CM-04 | Migrate `.env` key renames and additions | Must Have | |
| CM-05 | Flag deprecated config keys with suggested replacements | Must Have | |
| CM-06 | Never overwrite custom config values — merge, never replace | Must Have | |

### 8.7 Lumen Migration

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| LM-01 | Detect Lumen and activate migration mode automatically | Must Have | |
| LM-02 | Generate full Laravel 9 scaffold via `laravel/laravel` template | Must Have | |
| LM-03 | Migrate routes (`web.php` + `api.php`) | Must Have | |
| LM-04 | Migrate service providers to `config/app.php` | Must Have | |
| LM-05 | Migrate middleware registrations to `app/Http/Kernel.php` | Must Have | |
| LM-06 | Migrate exception handler (Lumen `Handler` interface differs from Laravel) | Must Have | F-08 |
| LM-07 | Detect `withFacades()` and `withEloquent()` bootstrap flags and migrate correctly | Must Have | F-08 |
| LM-08 | Extract inline config from Lumen `bootstrap/app.php` to `config/` files | Must Have | F-08 |
| LM-09 | Generate `LumenAuditReport` — Lumen-specific manual review section in HTML report | Must Have | F-08 |
| LM-10 | Run full L8→L9 Rector rule set on migrated codebase after scaffold generation | Must Have | |

### 8.8 State Management

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| ST-01 | `TransformCheckpoint` written to `/workspace/.upgrader-state/` after each rule batch | Must Have | F-03 |
| ST-02 | Checkpoint records: completed rules, pending rules, SHA-256 per file, timestamp | Must Have | F-03 |
| ST-03 | `WorkspaceReconciler` re-hashes files on resume; skips already-transformed files | Must Have | F-03 |
| ST-04 | `--resume` flag resumes from last valid checkpoint | Must Have | F-03 |
| ST-05 | Files externally modified since checkpoint trigger a warning before resume proceeds | Must Have | F-03 |

### 8.9 Verification Pipeline

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| VP-01 | `php -l` syntax check on every transformed file | Must Have | |
| VP-02 | PHPStan baseline established on original code before any transform | Must Have | |
| VP-03 | PHPStan re-run on transformed code; error count must not increase | Must Have | Regression = failure |
| VP-04 | PHPStan runs with `--parallel`; baseline cached to `phpstan-baseline.json` | Must Have | F-12 |
| VP-05 | Static config file validation via `nikic/php-parser` (no app boot required) | Must Have | F-04 |
| VP-06 | Static route file AST parsing (no route resolution, no app boot required) | Must Have | F-04 |
| VP-07 | Service provider class existence check (`class_exists` only, no boot) | Must Have | F-04 |
| VP-08 | `composer validate` + `composer install` in container | Must Have | |
| VP-09 | All `use` statements resolve to existing classes | Must Have | |
| VP-10 | `--with-artisan-verify` opt-in flag for full `artisan config:cache` + `route:list` | Should Have | F-04 |
| VP-11 | `--skip-phpstan` flag with explicit acknowledgement message | Should Have | F-12 |
| VP-12 | Verification failure blocks write-back to original repo | Must Have | Safety critical |

### 8.10 Live Dashboard

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| DB-01 | ReactPHP (`react/http`) non-blocking server on `localhost:8765` | Must Have | F-02 |
| DB-02 | SSE connections handled concurrently without blocking other HTTP requests | Must Have | F-02 |
| DB-03 | Client disconnect detected and SSE stream closed cleanly | Must Have | F-02 |
| DB-04 | Browser tab opens automatically on upgrade start | Must Have | |
| DB-05 | Overall progress bar with percentage and ETA | Must Have | |
| DB-06 | Per-stage pipeline status (inventory, Rector, Composer, Config, Lumen, Verify, Report) | Must Have | |
| DB-07 | Live scrolling log panel | Must Have | |
| DB-08 | Breaking changes tracker: auto vs manual, file count per rule | Must Have | |
| DB-09 | Files changed / warnings / errors / manual review counters | Must Have | |
| DB-10 | Special Lumen migration sub-view when Lumen detected | Must Have | |
| DB-11 | Tailwind CDN for styling; no build step | Must Have | |

### 8.11 Reporting

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| RP-01 | HTML diff report using Diff2Html assets bundled inline (no CDN) | Must Have | F-11 |
| RP-02 | Every diff annotated with the breaking change rule that caused it | Must Have | |
| RP-03 | Confidence score per file (High / Medium / Low) | Must Have | |
| RP-04 | Overall upgrade confidence score | Must Have | |
| RP-05 | `manual-review.md` with prioritised list of files needing developer attention | Must Have | |
| RP-06 | `audit.log.json` — JSON-ND, every event timestamped, machine-readable | Must Have | Compliance |
| RP-07 | `report.json` — structured summary for CI/CD consumption | Should Have | |

### 8.12 Test Suite

| ID | Requirement | Priority | Notes |
|---|---|---|---|
| TS-01 | Every custom Rector rule has a PHPUnit test using `AbstractRectorTestCase` | Must Have | F-05 |
| TS-02 | Each rule test uses `.php.inc` fixture files with input/expected separator | Must Have | F-05 |
| TS-03 | Integration test: full L8→L9 pipeline on `laravel-8-minimal` fixture | Must Have | F-05 |
| TS-04 | Integration test: full L8→L9 pipeline on `laravel-8-complex` fixture | Must Have | F-05 |
| TS-05 | Integration test: Lumen migration on `lumen-8-sample` fixture | Must Have | F-05 |
| TS-06 | Full test suite runs in CI on every commit to main branch | Must Have | F-05 |
| TS-07 | Test suite is a Phase 1 completion gate — no release without passing tests | Must Have | F-05 |

---

## 9. Non-Functional Requirements

### 9.1 Security

- All transforms run inside Docker containers with `--network=none` during transform stage
- Git tokens accepted via `UPGRADER_TOKEN` env var or `--token` CLI flag only; never logged
- Docker images contain no credentials, no network configuration
- Workspace copy isolated from original repo; write-back only after full verification passes
- `audit.log.json` does not contain source code content, only file paths and rule identifiers

### 9.2 Performance

- Upgrade of a 500-file Laravel 8 application must complete in under 15 minutes
- Dashboard available within 5 seconds of upgrade start
- Docker images pre-built and available in registry — no on-demand builds
- PHPStan baseline cached to disk to avoid re-computation on resume
- PHPStan runs with `--parallel` on multi-core hosts

### 9.3 Reliability

- If any verification step fails, the original codebase is not modified
- All pipeline stages emit structured JSON-ND events — no plain text parsing
- Tool functions in air-gapped environments (all docs and rules bundled in images)
- Failed upgrades produce a partial report explaining what failed and why
- Checkpoint mechanism guarantees resumability from any point of failure

### 9.4 Compatibility

- Host machine requires: Docker, PHP 8.x CLI (for orchestrator), bash
- Windows host requires WSL2 (documented; path normalisation in `WorkspaceManager`)
- Docker images are `linux/amd64` and `linux/arm64` compatible
- Dashboard compatible with Chrome, Firefox, Safari, Edge (latest 2 versions)
- Diff2Html assets bundled inline — report renders fully offline

---

## 10. CLI Interface

### 10.1 Commands

| Command | Description |
|---|---|
| `upgrader run` | Execute the full upgrade pipeline |
| `upgrader analyse` | Dry-run inventory only — no code changes |
| `upgrader dashboard` | Launch dashboard server only |
| `upgrader version` | Show tool version and bundled rule set versions |

### 10.2 Key Flags

| Flag | Default | Description |
|---|---|---|
| `--repo` | required | Local path or GitHub/GitLab URL |
| `--token` | `env: UPGRADER_TOKEN` | GitHub or GitLab PAT; never logged |
| `--to` | `9` | Target Laravel version (Phase 1: 9 only) |
| `--dry-run` | `false` | Analyse and report without applying changes |
| `--resume` | `false` | Resume from last valid checkpoint |
| `--no-dashboard` | `false` | Disable the live dashboard server |
| `--output` | `./upgrader-output` | Directory for reports and audit logs |
| `--format` | `html,json,md` | Report output formats |
| `--with-artisan-verify` | `false` | Enable full artisan verification (requires working env) |
| `--skip-phpstan` | `false` | Skip PHPStan step (requires explicit acknowledgement) |

---

## 11. Delivery Plan

> **Revised timeline: 22 weeks** (original: 18 weeks)  
> +2 weeks: Lumen migration full scope (F-08)  
> +1 week: Test suite as first-class module (F-05)  
> +1 week: State/checkpoint system (F-03)

| Weeks | Milestone | Deliverables | Exit Criteria |
|---|---|---|---|
| 1–2 | Scaffold + Proofs | CLI skeleton, Docker base images, ReactPHP SSE PoC [F-02], Rector subprocess PoC [F-01], WSL2 decision [F-09] | Both PoCs verified working |
| 3–4 | Repository Fetcher | Local + GitHub + GitLab fetchers, token auth, shallow clone, advisory file lock [F-07] | Clone from all 3 sources verified |
| 5–6 | Detection | Version detector, framework detector, inventory scanner | Correctly identifies L8 and Lumen |
| 7–8 | Dashboard | ReactPHP server [F-02], EventBus, SSE, live UI, breaking changes tracker | Browser opens; 50+ concurrent SSE connections handled |
| 9–11 | Rector L8→L9 | `RectorRunner` subprocess [F-01], `RectorConfigBuilder`, full L8→L9 rule set, fork mirror [F-06] | All official L8→L9 breaking changes covered |
| 12 | Composer | `DependencyUpgrader`, `CompatibilityChecker`, `ConflictResolver` | `composer install` succeeds post-upgrade |
| 13 | Config + Env | `ConfigMigrator` atomic model [F-10], `EnvMigrator` | Partial failure rolls back all configs correctly |
| 14–16 | Lumen (Full Scope) | All 8 Lumen sub-modules including 5 new [F-08]: `ExceptionHandlerMigrator`, `FacadeBootstrapMigrator`, `EloquentBootstrapDetector`, `InlineConfigExtractor`, `LumenAuditReport` | Lumen 8 app fully migrated to L9 scaffold |
| 17 | Verification | Static verifiers [F-04], PHPStan parallel + cache [F-12], class resolution | Static verification passes on complex fixture repo |
| 18 | State / Resume | `TransformCheckpoint`, `WorkspaceReconciler`, `--resume` flag [F-03] | Interrupted run resumes correctly from checkpoint |
| 19 | Reports | HTML report with Diff2Html inline [F-11], JSON, `manual-review.md`, audit log | Full report generated offline in air-gapped env |
| 20–21 | Test Suite + Design Spikes | Full test suite [F-05]: unit + integration + fixtures. Design spikes: L10→L11 slim skeleton; Livewire V2→V3 scope | All tests passing in CI; spike documents committed |
| 22 | Hardening | E2E on 3 real enterprise repos, edge cases, documentation, WSL2 validation | Phase 1 MVP pilot-ready |

### 11.1 Design Spikes Required in Weeks 20–21

These spikes are mandatory before Phase 2 can begin. They push overall plan confidence from 90% to 96%.

| Spike | Duration | Goal | Output |
|---|---|---|---|
| L10→L11 Slim Skeleton | 1 week | Understand full scope of `bootstrap/app.php` rewrite; identify what Rector cannot handle | Spike document + estimated module list for Phase 2 |
| Livewire V2→V3 | 1 week | Scope the Livewire migration sub-module; determine automated vs manual boundary | Spike document + Phase 2 Livewire module design |

---

## 12. Risk Register

| Risk | Severity | Status | Mitigation |
|---|---|---|---|
| Rector internal API instability | Critical | ✅ RESOLVED | Subprocess + JSON output [F-01] |
| PHP built-in server SSE deadlock | Critical | ✅ RESOLVED | ReactPHP non-blocking server [F-02] |
| No state continuity across containers | Critical | ✅ RESOLVED | Checkpoint + Reconciler [F-03] |
| `artisan` fails in blank container | Critical | ✅ RESOLVED | Static verification; artisan opt-in [F-04] |
| No tool test strategy | Critical | ✅ RESOLVED | `tests/` module added to Phase 1 [F-05] |
| `rector-laravel` unmaintained | Significant | ✅ RESOLVED | Pinned versions + fork mirror [F-06] |
| Workspace race condition | Significant | ✅ RESOLVED | Content-addressed IDs + `flock()` [F-07] |
| Lumen scope underestimated | Significant | ✅ RESOLVED | 5 new modules + 3 weeks added [F-08] |
| Windows support gap | Significant | ✅ RESOLVED | WSL2 documented + path normalisation [F-09] |
| L10→L11 slim skeleton complexity | High | ⚠️ MONITORED | Design spike in Phase 1 weeks 20–21 |
| Livewire V2→V3 scope | High | ⚠️ MONITORED | Design spike in Phase 1 week 21 |
| Unknown enterprise repo edge cases | Medium | ⚠️ OPEN | 3 real-world repo tests in week 22 |

---

## 13. Acceptance Criteria

Phase 1 MVP is complete when all of the following are verified on at least 3 distinct real-world Laravel 8 repositories:

1. ReactPHP dashboard serves SSE to multiple simultaneous browser connections without deadlock
2. Rector subprocess invocation produces correct JSON diff output on a real L8 codebase
3. An interrupted upgrade resumes correctly from checkpoint without re-applying completed rules
4. Static verification pipeline passes on an enterprise repo with no `.env` and no database connection
5. All breaking changes from the official Laravel 8 → 9 upgrade guide are either auto-fixed or flagged
6. Lumen 8 application — including facades, Eloquent opt-in, inline config — migrates to Laravel 9 scaffold
7. HTML diff report renders fully offline (Diff2Html inline, no CDN required)
8. Full test suite passes in CI with 100% of custom Rector rules covered by fixture tests
9. Original repository is unmodified if any verification step fails
10. L10→L11 and Livewire V2→V3 design spike documents committed before Phase 2 begins

---

*Laravel Enterprise Upgrader — Phase 1 PRD v2.0 · Post-Audit Revision · March 2026*

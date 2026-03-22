# Laravel Enterprise Upgrader — Technical Requirements Document (TRD)
## All Phases · v1.0

> **Author:** Marcus Webb, Senior Technical Staff Lead  
> **Derived from:** PRD-Phase1-MVP-v2.md · PRD-Phases2-3-v2.md  
> **PRD Confidence:** 96%  
> **Status:** Draft · March 2026  
> **Audience:** Engineering team, AI coding agents, CI/CD pipeline authors

---

## About This Document

This TRD translates the Product Requirements Documents into precise, implementation-ready technical specifications. Every section answers: *what exactly needs to be built, how it must behave, what interfaces it exposes, and how it will be verified*.

Each requirement carries:
- A unique TRD ID (e.g. `TRD-CORE-001`) for traceability
- A reference to the originating PRD requirement (e.g. `← RF-02`)
- An explicit contract: inputs, outputs, error behaviour
- Concrete code signatures where the interface is non-obvious

**Conventions used throughout:**
- `MUST` = non-negotiable; failure to meet this is a build blocker
- `SHOULD` = strongly recommended; deviation requires documented justification
- `MAY` = optional enhancement
- `[P1]` / `[P2]` / `[P3]` = which phase introduces this requirement

---

## Table of Contents

1. [System Architecture](#1-system-architecture)
2. [Repository Layer](#2-repository-layer)
3. [Orchestrator & HopPlanner](#3-orchestrator--hopplanner)
4. [State & Checkpoint System](#4-state--checkpoint-system)
5. [Docker Container Specification](#5-docker-container-specification)
6. [Rector Transformation Engine](#6-rector-transformation-engine)
7. [Breaking Change Registry](#7-breaking-change-registry)
8. [Composer Dependency Upgrader](#8-composer-dependency-upgrader)
9. [Config & Env Migrator](#9-config--env-migrator)
10. [Lumen Migration Suite](#10-lumen-migration-suite)
11. [Verification Pipeline](#11-verification-pipeline)
12. [ReactPHP Dashboard Server](#12-reactphp-dashboard-server)
13. [Event Streaming (JSON-ND)](#13-event-streaming-json-nd)
14. [Report Generator](#14-report-generator)
15. [Test Suite](#15-test-suite)
16. [CLI Interface](#16-cli-interface)
17. [Phase 2 — Additional Hop Containers](#17-phase-2--additional-hop-containers)
18. [Phase 2 — Package Rule Sets](#18-phase-2--package-rule-sets)
19. [Phase 2 — CI/CD Integration](#19-phase-2--cicd-integration)
20. [Phase 2 — Multi-Hop Orchestration](#20-phase-2--multi-hop-orchestration)
21. [Phase 3 — PHP Hop Containers](#21-phase-3--php-hop-containers)
22. [Phase 3 — 2D HopPlanner](#22-phase-3--2d-hopplanner)
23. [Phase 3 — Extension Compatibility Checker](#23-phase-3--extension-compatibility-checker)
24. [Phase 3 — Silent Change Scanner](#24-phase-3--silent-change-scanner)
25. [Security Requirements](#25-security-requirements)
26. [Performance Requirements](#26-performance-requirements)
27. [Data Contracts](#27-data-contracts)
28. [Dependency Manifest](#28-dependency-manifest)
29. [Build & CI Requirements](#29-build--ci-requirements)
30. [Traceability Matrix](#30-traceability-matrix)

---

## 1. System Architecture

### 1.1 Execution Model

```
┌─────────────────────────────────────────────────────────────┐
│  HOST PROCESS (orchestrator)                                │
│  Language: PHP 8.2+   Framework: Symfony Console           │
│                                                             │
│  ┌──────────────┐  ┌─────────────┐  ┌───────────────────┐  │
│  │ RunCommand   │  │ HopPlanner  │  │ ReactDashboard    │  │
│  │ (entry)      │→ │ (sequence)  │  │ Server (SSE)      │  │
│  └──────────────┘  └──────┬──────┘  └───────────────────┘  │
│                           │                                 │
│  ┌────────────────────────▼──────────────────────────────┐  │
│  │  DockerRunner                                         │  │
│  │  - Spawns one container per hop                      │  │
│  │  - Streams stdout as JSON-ND                         │  │
│  │  - Mounts: /repo (workspace copy), /output           │  │
│  └────────────────────────┬──────────────────────────────┘  │
│                           │ stdout (JSON-ND)               │
│  ┌────────────────────────▼──────────────────────────────┐  │
│  │  EventStreamer                                        │  │
│  │  → ReactPHP SSE broadcast                            │  │
│  │  → Terminal renderer                                 │  │
│  │  → audit.log.json append                             │  │
│  └───────────────────────────────────────────────────────┘  │
└──────────────────────────────┬──────────────────────────────┘
                               │ docker run --network=none
                               ▼
┌─────────────────────────────────────────────────────────────┐
│  CONTAINER (one per hop)                                    │
│  e.g. upgrader:hop-8-to-9  — PHP 8.0 base                 │
│                                                             │
│  Pipeline (sequential, each emits JSON-ND to stdout):      │
│  1. InventoryScanner                                        │
│  2. BreakingChangeRegistry (reads bundled JSON)            │
│  3. RectorRunner (subprocess → JSON diff)                  │
│  4. WorkspaceManager (applies diffs)                       │
│  5. TransformCheckpoint (writes state)                     │
│  6. DependencyUpgrader (composer.json)                     │
│  7. ConfigMigrator (atomic snapshot model)                 │
│  8. VerificationPipeline (static, no app boot)             │
│  9. ReportBuilder (HTML/JSON/MD, all assets inline)        │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 Language & Runtime Requirements

| Component | Language | Min Version | Justification |
|---|---|---|---|
| Orchestrator (host) | PHP | 8.2 | Readonly classes, match expressions, fibers for ReactPHP |
| Hop containers | PHP | Per-hop base (see §5) | Verification must match target runtime |
| Dashboard frontend | Vanilla JS | ES2020 | No build step; Tailwind CDN only |
| Entrypoint scripts | bash | 5.x | Alpine Linux default in Docker images |

### 1.3 Repository Layout

```
laravel-upgrader/
├── bin/upgrader                    # Symfony Console binary
├── composer.json                   # host-side dependencies
├── composer.lock
│
├── src/                            # Host-side PHP (orchestrator)
│   ├── Commands/
│   ├── Orchestrator/
│   │   └── State/
│   ├── Repository/
│   ├── Dashboard/
│   │   └── public/
│   └── Workspace/
│
├── src-container/                  # PHP deployed inside containers
│   ├── Detector/
│   ├── Documentation/
│   ├── Rector/
│   │   └── Rules/
│   ├── Composer/
│   ├── Config/
│   ├── Lumen/
│   ├── Verification/
│   └── Report/
│
├── docker/
│   ├── hop-8-to-9/
│   │   ├── Dockerfile
│   │   ├── entrypoint.sh
│   │   └── docs/
│   │       ├── breaking-changes.json
│   │       └── package-compatibility.json
│   └── lumen-migrator/
│       ├── Dockerfile
│       ├── entrypoint.sh
│       └── docs/
│
├── rector-configs/
│   └── rector.l8-to-l9.php
│
├── vendor-patches/
│   └── rector-laravel-fork/        # Fork-ready mirror (F-06)
│
└── tests/
    ├── Unit/Rector/Rules/L8ToL9/
    ├── Integration/
    └── Fixtures/
        ├── laravel-8-minimal/
        ├── laravel-8-complex/
        ├── laravel-8-no-tests/
        └── lumen-8-sample/
```

---

## 2. Repository Layer

### 2.1 Interface: `RepositoryFetcherInterface` [P1]

```php
namespace App\Repository;

interface RepositoryFetcherInterface
{
    /**
     * Clones or copies the repository into $targetPath.
     * MUST acquire an advisory lock before copying.
     * MUST throw ConcurrentUpgradeException if lock unavailable.
     * MUST NOT log $token in any output or exception message.
     *
     * @throws RepositoryNotFoundException
     * @throws AuthenticationException
     * @throws ConcurrentUpgradeException
     */
    public function fetch(string $source, string $targetPath, ?string $token = null): FetchResult;
}
```

### 2.2 Concrete Fetchers [P1] `← RF-01, RF-02, RF-03`

| Class | Source Pattern | Auth |
|---|---|---|
| `LocalRepositoryFetcher` | Absolute filesystem path | None |
| `GitHubRepositoryFetcher` | `github:org/repo` or `https://github.com/...` | PAT via `Authorization: token {PAT}` header |
| `GitLabRepositoryFetcher` | `gitlab:org/repo` or `https://gitlab.com/...` | PAT via `PRIVATE-TOKEN` header |

**TRD-REPO-001** [P1] `← RF-04`  
All git-based fetchers MUST use `git clone --depth=1 --single-branch`. Fetch MUST complete within 120 seconds or throw `FetchTimeoutException`.

**TRD-REPO-002** [P1] `← RF-05`  
The token MUST be passed to the git subprocess via the URL (masked) or `GIT_ASKPASS` helper. It MUST NOT appear in:
- Process arguments visible to `ps aux`
- Log output
- Exception messages
- The `audit.log.json`

**TRD-REPO-003** [P1] `← RF-06, F-07`  
Workspace ID MUST be computed as:
```php
$workspaceId = hash('sha256', $repoPath . $targetVersion . microtime(true));
$lockFile = sys_get_temp_dir() . '/upgrader/locks/' . hash('sha256', $repoPath) . '.lock';
```
Advisory lock MUST use `LOCK_EX | LOCK_NB`. On failure, throw `ConcurrentUpgradeException` with the message: `"An upgrade is already running for this repository. Use --resume to continue it, or wait for it to complete."`.

### 2.3 `FetchResult` Value Object [P1]

```php
final readonly class FetchResult
{
    public function __construct(
        public string $workspacePath,      // absolute path to cloned workspace
        public string $lockFilePath,       // path to held advisory lock file
        public string $defaultBranch,      // detected default branch
        public string $resolvedCommitSha,  // full SHA of cloned commit
    ) {}
}
```

---

## 3. Orchestrator & HopPlanner

### 3.1 `UpgradeOrchestrator` [P1]

**TRD-ORCH-001** [P1]  
`UpgradeOrchestrator::run()` MUST execute hops in the sequence provided by `HopPlanner`. Each hop MUST receive the verified workspace output of the previous hop. If any hop's `VerificationResult::$passed === false`, orchestration MUST halt immediately. Subsequent hops MUST NOT run.

**TRD-ORCH-002** [P1]  
The orchestrator MUST write-back the transformed workspace to the original repo path only after ALL hops complete with `$passed === true`. At no point during execution MUST the original repo be modified.

### 3.2 `HopPlanner` — Phase 1 [P1] `← VD-04`

```php
final class HopPlanner
{
    /**
     * Returns an ordered list of hops to execute.
     * Phase 1: validates that $from === '8' and $to === '9'.
     * Throws InvalidHopException for unsupported versions.
     */
    public function plan(string $from, string $to): HopSequence;
}

final class HopSequence
{
    /** @param Hop[] $hops */
    public function __construct(public readonly array $hops) {}
    public function count(): int { return count($this->hops); }
}

final readonly class Hop
{
    public function __construct(
        public string $dockerImage,     // e.g. 'upgrader:hop-8-to-9'
        public string $fromVersion,     // '8'
        public string $toVersion,       // '9'
        public string $type,            // 'laravel' | 'php'
        public ?string $phpBase,        // PHP version of container base, e.g. '8.0'
    ) {}
}
```

**TRD-ORCH-003** [P1]  
`HopPlanner::plan()` MUST throw `InvalidHopException` with a descriptive message for any combination where `$from >= $to` or either version is outside the supported range for the current phase.

### 3.3 `DockerRunner` [P1]

**TRD-ORCH-004** [P1]  
`DockerRunner` MUST construct and execute the following `docker run` command signature for each hop:

```bash
docker run --rm \
  --network=none \
  -v {workspacePath}:/repo:rw \
  -v {outputPath}:/output:rw \
  --env UPGRADER_HOP_FROM={from} \
  --env UPGRADER_HOP_TO={to} \
  {dockerImage}
```

**TRD-ORCH-005** [P1]  
`DockerRunner` MUST read container stdout line by line. Each line MUST be passed to `EventStreamer::dispatch()`. Container stderr MUST be captured and appended to `audit.log.json` under key `"stderr_lines"`. A non-zero container exit code MUST be treated as hop failure.

---

## 4. State & Checkpoint System

### 4.1 `TransformCheckpoint` [P1] `← ST-01, ST-02, F-03`

**TRD-STATE-001** [P1]  
`TransformCheckpoint` MUST write to `/workspace/.upgrader-state/checkpoint.json` after every Rector rule batch completes. Write MUST be atomic: write to `.checkpoint.json.tmp` then rename.

**Checkpoint schema:**

```typescript
interface Checkpoint {
  hop: string;                          // e.g. "8_to_9"
  schema_version: "1";
  completed_rules: string[];            // fully-qualified class names
  pending_rules: string[];
  files_hashed: Record<string, string>; // relative path → "sha256:{hex}"
  timestamp: string;                    // ISO 8601
  can_resume: boolean;
  host_version: string;                 // upgrader tool version
}
```

**TRD-STATE-002** [P1]  
SHA-256 hashes MUST be computed over the file content bytes (not metadata). The hash format MUST be the string `"sha256:{64-hex-chars}"`.

### 4.2 `WorkspaceReconciler` [P1] `← ST-03, ST-04, ST-05`

**TRD-STATE-003** [P1]  
On `--resume`, `WorkspaceReconciler::reconcile()` MUST:
1. Read `checkpoint.json`
2. Re-hash every file listed in `files_hashed`
3. For each file where current hash === checkpoint hash → mark as `already_transformed`, skip from pending rules
4. For each file where current hash differs from checkpoint hash → emit a `WARNING` event and prompt user confirmation before proceeding
5. Return a `ReconcileResult` with `$pendingRules` filtered to only those not in `$completedRules`

**TRD-STATE-004** [P1]  
If no checkpoint file exists and `--resume` is passed, MUST throw `NoCheckpointException` with message: `"No checkpoint found at {path}. Run without --resume to start a fresh upgrade."`.

---

## 5. Docker Container Specification

### 5.1 Base Image Requirements [P1] `← §6.3 PRD`

Each hop image MUST be built with the following structure:

```dockerfile
# Pattern — applied to every hop image
FROM php:{TARGET_PHP_VERSION}-cli-alpine

# System dependencies
RUN apk add --no-cache git unzip bash

# Composer (from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Upgrader container-side source
WORKDIR /upgrader
COPY composer.{hop}.json composer.json
RUN composer install --no-interaction --prefer-dist --no-dev

# Rector + PHPStan (dev deps)
COPY composer.{hop}.dev.json composer.dev.json
RUN composer install --no-interaction --working-dir=/upgrader --prefer-dist

# Bundled knowledge base (no network at runtime)
COPY docker/{hop}/docs/ /upgrader/docs/

# Container-side source code
COPY src-container/ /upgrader/src/

# Rector config for this hop
COPY rector-configs/rector.{hop}.php /upgrader/rector.php

# Inline Diff2Html assets (no CDN — F-11)
COPY assets/diff2html.min.css /upgrader/assets/
COPY assets/diff2html.min.js  /upgrader/assets/

ENTRYPOINT ["/entrypoint.sh"]
```

**TRD-DOCKER-001** [P1]  
Images MUST be built for both `linux/amd64` and `linux/arm64` using Docker buildx multi-platform builds.

**TRD-DOCKER-002** [P1]  
No credentials, API keys, or tokens MUST exist inside any image layer. CI build logs MUST be verified to confirm no secrets leak during `docker build`.

**TRD-DOCKER-003** [P1]  
The `--network=none` flag MUST be applied at runtime (not baked into the image). This preserves the ability to debug containers locally with network access.

### 5.2 Phase 1 Images

| Image Tag | PHP Base | Purpose |
|---|---|---|
| `upgrader:hop-8-to-9` | `php:8.0-cli-alpine` | Laravel 8→9 transforms + verification |
| `upgrader:lumen-migrator` | `php:8.0-cli-alpine` | Lumen → Laravel 9 scaffold migration |

### 5.3 Container Entrypoint Contract [P1]

The entrypoint script MUST:
1. Set `set -euo pipefail`
2. Run the pipeline stages in sequence
3. Emit a JSON-ND event to stdout for each stage start, stage complete, and any error
4. Exit with code `0` on success, `1` on pipeline failure, `2` on configuration error

---

## 6. Rector Transformation Engine

### 6.1 `RectorRunner` [P1] `← RE-01, RE-02, RE-03, RE-04, F-01`

**TRD-RECTOR-001** [P1] — **CRITICAL**  
`RectorRunner` MUST invoke Rector as a subprocess via `symfony/process`. Programmatic invocation via Rector internal classes MUST NOT be used. The exact subprocess command MUST be:

```php
$process = new Process([
    PHP_BINARY,
    'vendor/bin/rector',
    'process',
    $workspacePath,
    '--config=' . $configPath,
    '--dry-run',
    '--output-format=json',
    '--no-progress-bar',
    '--no-diffs',          // diffs are extracted from JSON, not stdout
]);
$process->setTimeout(600); // 10 minutes max
$process->run();
```

**TRD-RECTOR-002** [P1]  
If Rector exits with a non-zero code, `RectorRunner` MUST:
1. Capture the full stderr output
2. Emit a `rector_error` JSON-ND event with `"stderr": "{captured}"` and `"exit_code": {n}`
3. Throw `RectorExecutionException` with the stderr content

**TRD-RECTOR-003** [P1]  
`RectorResult` MUST be parsed from Rector's JSON output. The expected Rector JSON output shape is:

```typescript
interface RectorJsonOutput {
  file_diffs: FileDiff[];
  errors: RectorError[];
}
interface FileDiff {
  file: string;           // absolute path
  diff: string;           // unified diff string
  applied_rectors: string[]; // fully-qualified class names
}
```

### 6.2 `RectorConfigBuilder` [P1] `← RE-05`

**TRD-RECTOR-004** [P1]  
`RectorConfigBuilder` MUST generate a `rector.php` config file programmatically based on the hop and the detected packages. The config MUST include:
- `withPaths([$workspacePath])`
- `withSkipPath($workspacePath . '/.upgrader-state')` — never transform checkpoint files
- All rules from `driftingly/rector-laravel` applicable to the hop
- All custom rules from `Rector\Rules\{HopNamespace}\`

**TRD-RECTOR-005** [P1] `← RE-07`  
The following PHP patterns MUST be detected and emitted as `manual_review_required` events rather than transformed:
- Methods named `__call`, `__callStatic`, `__get`, `__set`
- Calls to `Macro::macro()` and `\Illuminate\Support\Traits\Macroable`
- Dynamic class instantiation: `new $className()`
- String-based method calls: `$obj->$methodName()`

### 6.3 `WorkspaceManager::applyDiffs()` [P1] `← RE-04`

**TRD-RECTOR-006** [P1]  
After `RectorRunner` returns a `RectorResult`, `WorkspaceManager::applyDiffs()` MUST:
1. For each `FileDiff`, write the new file content derived from applying the diff to the original
2. Verify the written file is valid PHP via `php -l` before moving on
3. Update `TransformCheckpoint` with the rule(s) applied and the new file hash
4. Emit a `file_changed` JSON-ND event per file

**TRD-RECTOR-007** [P1]  
If any individual file write fails (disk full, permissions, etc.), `WorkspaceManager` MUST:
1. Not continue to subsequent files
2. Emit a `pipeline_error` event
3. Leave the checkpoint in the last valid state (resumable)

---

## 7. Breaking Change Registry

### 7.1 Schema: `breaking-changes.json` [P1] `← DC-01, DC-02, DC-03, DC-04`

Each Docker image MUST bundle `/upgrader/docs/breaking-changes.json` conforming to:

```typescript
interface BreakingChangeRegistry {
  hop: string;              // e.g. "8_to_9"
  laravel_from: string;     // "8.x"
  laravel_to: string;       // "9.x"
  php_minimum: string;      // "8.0"
  last_curated: string;     // ISO date "2026-01-15"
  breaking_changes: BreakingChange[];
}

interface BreakingChange {
  id: string;               // e.g. "l9_model_dates_removed" — globally unique
  severity: "blocker" | "high" | "medium" | "low";
  category: "eloquent" | "routing" | "middleware" | "config" | "helpers"
           | "environment" | "package" | "lumen";
  title: string;
  description: string;
  rector_rule: string | null;    // fully-qualified class name, or null
  automated: boolean;
  affects_lumen: boolean;
  manual_review_required: boolean;
  detection_pattern?: string;    // regex or AST pattern hint
  migration_example: {
    before: string;              // PHP code snippet
    after: string;               // PHP code snippet
  };
  official_doc_anchor: string;   // URL fragment e.g. "#dates"
}
```

**TRD-REG-001** [P1]  
`BreakingChangeRegistry::load()` MUST validate the JSON against the above schema on startup. If validation fails, MUST throw `RegistryCorruptException` and halt the pipeline.

**TRD-REG-002** [P1]  
Every custom Rector rule in `Rector\Rules\L8ToL9\` MUST have a corresponding entry in `breaking-changes.json`. The `rector_rule` field MUST match the rule's fully-qualified class name exactly.

---

## 8. Composer Dependency Upgrader

### 8.1 `DependencyUpgrader` [P1] `← CD-01, CD-02, CD-03, CD-04, CD-05`

**TRD-COMP-001** [P1]  
`DependencyUpgrader::upgrade()` MUST:
1. Read `composer.json` from the workspace
2. Bump `laravel/framework` to `^{targetVersion}.0`
3. For each package in `require` and `require-dev`, check against the bundled `package-compatibility.json`
4. Apply known-good version bumps
5. Flag packages with `"l9_support": false` or `"unknown": true` as blockers

**TRD-COMP-002** [P1]  
Packages flagged as blockers MUST be emitted as `dependency_blocker` events BEFORE any Rector transforms begin. If any blocker has severity `"critical"`, the pipeline MUST halt unless `--ignore-dependency-blockers` is passed (with an explicit warning in the report).

**TRD-COMP-003** [P1]  
After modifying `composer.json`, `DependencyUpgrader` MUST run:

```bash
composer install --no-interaction --prefer-dist --no-scripts
```

inside the container. If this fails, MUST emit `composer_install_failed` event and halt.

### 8.2 Schema: `package-compatibility.json` [P1] `← DC-05`

```typescript
interface PackageCompatibilityMatrix {
  generated: string;    // ISO date
  packages: Record<string, PackageSupport>;
}
interface PackageSupport {
  "l9_support": boolean | "unknown";
  "l10_support": boolean | "unknown";
  "recommended_version": string | null;  // e.g. "^3.0"
  "notes": string;
}
```

---

## 9. Config & Env Migrator

### 9.1 `ConfigMigrator` — Atomic Model [P1] `← CM-01, CM-02, F-10`

**TRD-CONFIG-001** [P1] — **CRITICAL**  
`ConfigMigrator::migrate()` MUST implement the snapshot-rollback pattern:

```php
public function migrate(string $workspacePath): MigrationResult
{
    $snapshot = $this->snapshotAllConfigs($workspacePath);
    try {
        foreach ($this->getMigrationsForHop() as $migration) {
            $migration->apply($workspacePath);
        }
        return MigrationResult::success();
    } catch (\Throwable $e) {
        $this->restoreSnapshot($snapshot, $workspacePath);
        return MigrationResult::failure($e->getMessage());
    }
}
```

Partial migration state MUST NOT be possible. Either all config migrations apply, or none do.

**TRD-CONFIG-002** [P1]  
`snapshotAllConfigs()` MUST copy all files matching `config/*.php` and `.env*` to a temporary snapshot directory before any migration begins. Snapshot MUST be written atomically as a tar archive.

**TRD-CONFIG-003** [P1] `← CM-06`  
Config migrations MUST use a deep-merge strategy for PHP array config files. Custom keys that do not exist in the standard Laravel config MUST be preserved verbatim. Only keys whose names match known-changed keys in the breaking change registry MUST be touched.

### 9.2 `EnvMigrator` [P1] `← CM-04`

**TRD-CONFIG-004** [P1]  
`EnvMigrator` MUST parse `.env` files using a line-by-line approach that preserves:
- Comments (lines starting with `#`)
- Blank lines
- Quoted values
- Multiline values (using `\n` escapes)

Renamed keys MUST be added with the new name while the old name is preserved with a `# DEPRECATED: use {new_key}` comment inline.

---

## 10. Lumen Migration Suite

### 10.1 Lumen Detection [P1] `← LM-01`

**TRD-LUMEN-001** [P1]  
`LumenDetector::detect()` MUST check for the presence of `laravel/lumen-framework` in `composer.json` require or require-dev. Additionally MUST check for the pattern `$app = new Laravel\Lumen\Application` in `bootstrap/app.php`. Both conditions MUST be met for definitive detection. Either condition alone MUST emit a `lumen_ambiguous` warning.

### 10.2 `ScaffoldGenerator` [P1] `← LM-02`

**TRD-LUMEN-002** [P1]  
`ScaffoldGenerator` MUST generate a Laravel 9 scaffold by running:

```bash
composer create-project laravel/laravel:^9.0 /tmp/laravel-scaffold --no-interaction
```

inside the container, then merging the Lumen app's source files into the new scaffold. The original Lumen `bootstrap/app.php` MUST be preserved at `bootstrap/lumen-app-original.php` for reference.

### 10.3 Route Migration [P1] `← LM-03`

**TRD-LUMEN-003** [P1]  
`RoutesMigrator` MUST:
1. Parse Lumen's `routes/web.php` and `routes/api.php` using `nikic/php-parser`
2. Map Lumen route group syntax to Laravel route syntax (they differ in closure binding)
3. Preserve route names, middleware assignments, and prefix groups
4. Flag any route using Lumen-specific `$router->group()` patterns as manual review

### 10.4 Facade & Eloquent Bootstrap [P1] `← LM-07, F-08`

**TRD-LUMEN-004** [P1]  
`FacadeBootstrapMigrator` MUST detect `$app->withFacades()` and `$app->withEloquent()` calls in `bootstrap/app.php`. If found:
- `withFacades()` → facades are enabled in the Lumen app; the migrated Laravel app already has facades — no additional action, but MUST log this for audit
- `withEloquent()` → Eloquent is explicitly enabled; verify `config/database.php` is correctly migrated
- If either is ABSENT → emit `lumen_feature_disabled` event flagging that the migrated app may have unexpected facade/Eloquent availability

### 10.5 Inline Config Extraction [P1] `← LM-08, F-08`

**TRD-LUMEN-005** [P1]  
`InlineConfigExtractor` MUST scan `bootstrap/app.php` for `$app->configure('...')` calls. For each called config name not present in the scaffold's `config/` directory, MUST:
1. Locate the corresponding file in `config/` of the Lumen app (if it exists)
2. Copy it to the scaffold's `config/` directory
3. If it doesn't exist, generate a stub config and flag as manual review

### 10.6 Exception Handler Migration [P1] `← LM-06, F-08`

**TRD-LUMEN-006** [P1]  
`ExceptionHandlerMigrator` MUST:
1. Read Lumen's `app/Exceptions/Handler.php`
2. Detect which methods are overridden beyond the Lumen base (`report`, `render`, `shouldReport`)
3. Map each to the equivalent Laravel `Handler.php` method signature (slightly different)
4. Emit `manual_review_required` for any handler method that cannot be automatically mapped

---

## 11. Verification Pipeline

### 11.1 Pipeline Composition [P1] `← VP-01 through VP-12`

**TRD-VERIFY-001** [P1]  
`VerificationPipeline::run()` MUST execute verifiers in this exact order. A failing verifier MUST halt the pipeline (subsequent verifiers do not run):

```
1. SyntaxVerifier          (php -l on all PHP files)
2. ComposerVerifier        (composer validate + composer install)
3. ClassResolutionVerifier (all use statements resolve)
4. PhpStanVerifier         (baseline delta; --parallel)
5. StaticArtisanVerifier   (config + route AST parsing; no app boot)
```

**TRD-VERIFY-002** [P1]  
Each verifier MUST implement:
```php
interface VerifierInterface
{
    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult;
}

final readonly class VerifierResult
{
    public function __construct(
        public bool   $passed,
        public string $verifierName,
        public int    $issueCount,
        public array  $issues,      // VerificationIssue[]
        public float  $durationSeconds,
    ) {}
}
```

### 11.2 `SyntaxVerifier` [P1] `← VP-01`

**TRD-VERIFY-003** [P1]  
MUST run `php -l {file}` for every `.php` file in the workspace using parallel execution (max 8 concurrent processes). ANY syntax error MUST be treated as a pipeline failure. Zero tolerance.

### 11.3 `PhpStanVerifier` [P1] `← VP-02, VP-03, VP-04, F-12`

**TRD-VERIFY-004** [P1]  
PHPStan MUST be run with these flags:

```bash
vendor/bin/phpstan analyse \
  {workspacePath} \
  --level=3 \
  --no-progress \
  --error-format=json \
  --parallel \
  --memory-limit=1G
```

**TRD-VERIFY-005** [P1]  
Baseline workflow:
1. If `phpstan-baseline.json` does not exist in `/output/`: run PHPStan on original code BEFORE Rector transforms, write baseline to `/output/phpstan-baseline.json`
2. After transforms: run PHPStan again and compare error count
3. If `post_error_count > pre_error_count`: MUST emit `phpstan_regression` event and fail verification
4. The baseline file MUST persist across `--resume` runs (do not re-generate if it exists)

### 11.4 `StaticArtisanVerifier` [P1] `← VP-05, VP-06, VP-07, F-04`

**TRD-VERIFY-006** [P1]  
MUST use `nikic/php-parser` to statically verify:

**Config validation:** Parse every file in `config/*.php`. Ensure it returns a PHP array literal (not expressions, not function calls at top level). Flag any config file that is not parseable as a plain array.

**Route validation:** Parse `routes/web.php` and `routes/api.php`. Verify that all controller class references in `Route::get('/', [SomeController::class, 'method'])` resolve to existing classes via `class_exists()` after composer autoload.

**Provider validation:** For each entry in `config/app.php` providers array, verify `class_exists()` returns true.

**TRD-VERIFY-007** [P1]  
`StaticArtisanVerifier` MUST NOT instantiate any class, boot any service container, or execute any application code. It is a pure static analysis step.

### 11.5 Opt-in Artisan Verification [P1] `← VP-10`

**TRD-VERIFY-008** [P1]  
When `--with-artisan-verify` is passed, AFTER all static verification passes, run:

```bash
php artisan config:cache --quiet
php artisan route:list --json > /dev/null
```

These commands MUST run inside the container with the workspace's `.env` mounted. If they fail, emit `artisan_verify_warning` (not `artisan_verify_error`) — artisan verification failures are advisory only, not blocking.

---

## 12. ReactPHP Dashboard Server

### 12.1 Architecture [P1] `← DB-01, DB-02, DB-03, F-02`

**TRD-DASH-001** [P1] — **CRITICAL**  
The dashboard MUST use ReactPHP (`react/http ^1.9`) — NOT PHP's built-in server. The event loop MUST handle SSE connections concurrently without blocking.

**TRD-DASH-002** [P1]  
`ReactDashboardServer` MUST implement:

```php
final class ReactDashboardServer
{
    private array $sseClients = [];  // string $id => ThroughStream $stream

    public function start(int $port = 8765): void;
    public function broadcast(array $event): void;
    public function stop(): void;

    // Routes:
    // GET /        → serves public/index.html
    // GET /events  → SSE endpoint; adds client to $sseClients
    // GET /static/* → serves assets from public/
}
```

**TRD-DASH-003** [P1]  
SSE endpoint (`GET /events`) MUST:
1. Respond with headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`
2. Create a `ThroughStream` and add to `$sseClients` with a unique ID
3. Register a `close` listener that removes the client from `$sseClients`
4. Send an initial `data: {"event":"connected"}\n\n` heartbeat immediately

**TRD-DASH-004** [P1]  
`broadcast()` MUST catch `\Throwable` per client. A dead client (closed connection) MUST be silently removed from `$sseClients`. A `broadcast()` failure for one client MUST NOT affect other clients.

**TRD-DASH-005** [P1]  
The dashboard MUST open in the user's default browser automatically. Use `xdg-open` (Linux), `open` (macOS), or `start` (Windows/WSL2) via subprocess.

### 12.2 Dashboard Frontend [P1] `← DB-05 through DB-11`

**TRD-DASH-006** [P1]  
`public/index.html` MUST be a self-contained single file. ALL CSS and JS MUST be inline or from Tailwind CDN only. No build toolchain required.

**TRD-DASH-007** [P1]  
The frontend SSE client MUST implement automatic reconnection with exponential backoff (initial: 1s, max: 30s) using the `EventSource` API's native reconnection or manual `setTimeout`.

**TRD-DASH-008** [P1]  
The dashboard MUST display these panels simultaneously:
- Overall progress bar with `{completedHops}/{totalHops}` and elapsed time
- Current hop name and current pipeline stage
- Per-stage status icons: `⏳ pending` / `🔄 running` / `✅ done` / `❌ failed`
- Live log panel (last 100 entries, auto-scroll, pause on hover)
- Breaking changes tracker (rule name / AUTO or MANUAL / file count)
- Summary counters: Files Changed, Warnings, Errors, Manual Review Required

---

## 13. Event Streaming (JSON-ND)

### 13.1 Event Schema [P1]

All events emitted to stdout from containers MUST conform to this base shape:

```typescript
interface BaseEvent {
  event: string;       // event type (see catalogue below)
  hop: string;         // e.g. "8_to_9"
  ts: number;          // Unix timestamp (seconds, float)
  seq: number;         // monotonically increasing sequence number per run
}
```

**TRD-EVENTS-001** [P1]  
Every line of container stdout MUST be valid JSON. Non-JSON lines MUST be discarded by the `EventStreamer` and logged to stderr with a `"malformed_event"` warning.

### 13.2 Event Catalogue [P1]

| Event Type | When Emitted | Required Fields |
|---|---|---|
| `pipeline_start` | Container starts | `total_files`, `php_files`, `config_files` |
| `stage_start` | Stage begins | `stage` (one of: inventory, rector, composer, config, lumen, verify, report) |
| `stage_complete` | Stage completes | `stage`, `duration_seconds`, `issues_found` |
| `file_changed` | File transformed | `file` (relative path), `rules` (array of rule IDs), `lines_added`, `lines_removed` |
| `checkpoint_written` | Checkpoint saved | `completed_rules_count`, `pending_rules_count` |
| `breaking_change_applied` | BC auto-fixed | `id` (from registry), `automated: true`, `file_count` |
| `manual_review_required` | BC cannot be auto-fixed | `id`, `automated: false`, `reason`, `files` (array) |
| `dependency_blocker` | Package incompatible | `package`, `current_version`, `severity` |
| `verification_result` | Each verifier completes | `step`, `passed`, `issue_count`, `duration_seconds` |
| `phpstan_regression` | PHPStan error count increased | `before_count`, `after_count`, `new_errors` (array) |
| `hop_complete` | Container exits successfully | `confidence`, `manual_review_count`, `files_changed` |
| `pipeline_error` | Unrecoverable error | `message`, `stage`, `recoverable: false` |
| `warning` | Non-fatal issue | `message`, `context` |

**TRD-EVENTS-002** [P1]  
`EventStreamer` MUST fan out each parsed event to THREE consumers simultaneously:
1. `ReactDashboardServer::broadcast()` — for SSE
2. `TerminalRenderer::render()` — for CLI output
3. `AuditLogWriter::append()` — for `audit.log.json`

Failure in any single consumer MUST NOT prevent delivery to the other two.

---

## 14. Report Generator

### 14.1 HTML Report [P1] `← RP-01, RP-02, RP-03, RP-04, F-11`

**TRD-REPORT-001** [P1] — **CRITICAL**  
The HTML report MUST include `diff2html.min.css` and `diff2html.min.js` as inline `<style>` and `<script>` tags. External CDN links MUST NOT be present. The report MUST render fully without internet access.

**TRD-REPORT-002** [P1]  
`HtmlFormatter` MUST generate a report with these sections:
- Header: repo name, upgrade path, overall confidence score, timestamp
- Summary panel: total files changed, auto-fixed, manual review required, skipped
- Breaking changes index: table linking each change ID to its diff section
- Per-file diffs: side-by-side unified diff view using Diff2Html, annotated with the rule ID that caused each change
- Manual review section: grouped list of files requiring human attention, with specific guidance per issue

**TRD-REPORT-003** [P1]  
Confidence score MUST be computed by `ConfidenceScorer` as:

```
base_score = 100
per_manual_review_file: -2 points (max deduction: 30)
per_unresolved_blocker:  -10 points
per_phpstan_regression:  -15 points
syntax_error_anywhere:   report as 0% (always)
floor: 0, ceiling: 100
```

### 14.2 `manual-review.md` [P1] `← RP-05`

**TRD-REPORT-004** [P1]  
MUST be a Markdown file sorted by severity descending (blockers first). Each entry MUST include:
- File path (relative to repo root)
- Issue ID (from breaking change registry or `MANUAL-{n}` for unregistered issues)
- Human-readable description of what needs to be changed and why
- Code snippet showing the problematic pattern

### 14.3 `audit.log.json` [P1] `← RP-06`

**TRD-REPORT-005** [P1]  
MUST be a newline-delimited JSON file (JSON-ND). Each line MUST be the raw JSON-ND event as emitted from the container, enriched with:
- `"run_id"`: UUID v4 generated at orchestrator start
- `"host_version"`: upgrader tool semver
- `"repo_sha"`: commit SHA of the cloned repo

MUST NOT contain: source code content, file contents, or authentication tokens.

---

## 15. Test Suite

### 15.1 Unit Tests — Rector Rules [P1] `← TS-01, TS-02, F-05`

**TRD-TEST-001** [P1]  
Every class in `Rector\Rules\L8ToL9\` MUST have a corresponding test class in `tests/Unit/Rector/Rules/L8ToL9/` extending `Rector\Testing\PHPUnit\AbstractRectorTestCase`.

**TRD-TEST-002** [P1]  
Each test class MUST use `.php.inc` fixture files with the format:

```php
<?php
// Input code (before transformation)
class User extends Model {
    protected $dates = ['created_at', 'deleted_at'];
}

?>
-----
<?php
// Expected output (after transformation)
class User extends Model {
    protected $casts = ['created_at' => 'datetime', 'deleted_at' => 'datetime'];
}
```

**TRD-TEST-003** [P1]  
Test classes MUST NOT use mocks for the Rector testing infrastructure. `AbstractRectorTestCase` provides real AST transformation — use it as designed.

### 15.2 Integration Tests [P1] `← TS-03, TS-04, TS-05`

**TRD-TEST-004** [P1]  
`FullHopTest` MUST spin up the actual Docker container (`upgrader:hop-8-to-9`) against each fixture directory and assert:
- Exit code is `0`
- `report.json` contains `"confidence"` > 80
- `audit.log.json` contains a `hop_complete` event
- No files in the original fixture directory were modified

**TRD-TEST-005** [P1]  
Integration tests MUST be tagged `@group integration` and MUST be excluded from the default PHPUnit run. They MUST be run in CI as a separate step with Docker available.

### 15.3 CI Requirements [P1] `← TS-06, TS-07`

**TRD-TEST-006** [P1]  
The following MUST run on every push to `main` and every pull request:
- `composer test` → unit tests only (< 60 seconds)
- `composer test:integration` → integration tests (< 15 minutes, requires Docker)
- `composer phpstan` → PHPStan on the upgrader's own codebase at level 6
- `composer cs-check` → PSR-12 code style check

---

## 16. CLI Interface

### 16.1 Command: `upgrader run` [P1]

**TRD-CLI-001** [P1]  
`RunCommand` MUST validate all inputs before any Docker operation begins. Validation failures MUST print a clear error message and exit with code `2`.

**TRD-CLI-002** [P1]  
`RunCommand` MUST display a pre-flight summary before starting:

```
Laravel Enterprise Upgrader v{version}
══════════════════════════════════════
  Repository:  github:org/my-app
  From:        Laravel 8
  To:          Laravel 9
  Dashboard:   http://localhost:8765
  Output:      ./upgrader-output/
  Workspace:   /tmp/upgrader/a1b2c3d4.../

Estimated time: 8–15 minutes for a repo this size.
Press ENTER to confirm, or Ctrl+C to cancel.
```

This prompt MUST be skipped in `--no-interaction` / CI mode.

### 16.2 Full Flag Specification [P1]

| Flag | Type | Default | Validation |
|---|---|---|---|
| `--repo` | string | required | Must be valid path or `github:`/`gitlab:`/`https://` URL |
| `--token` | string | `$UPGRADER_TOKEN` env | If GitHub/GitLab URL, token is required |
| `--to` | int | `9` | Phase 1: must be `9`; Phase 2+: 9–13 |
| `--from` | int | auto-detect | Must be ≤ `--to`; must match detected version |
| `--dry-run` | bool | `false` | No transforms applied; report generated from analysis |
| `--resume` | bool | `false` | Requires checkpoint to exist |
| `--no-dashboard` | bool | `false` | Dashboard not started; all output to terminal only |
| `--output` | path | `./upgrader-output` | Directory created if absent |
| `--format` | string | `html,json,md` | Comma-separated list of `html`, `json`, `md` |
| `--with-artisan-verify` | bool | `false` | Runs artisan after static verification |
| `--skip-phpstan` | bool | `false` | Requires explicit confirmation prompt |
| `--no-interaction` | bool | `false` | Skips all confirmation prompts; safe for CI |

**TRD-CLI-003** [P1]  
`--skip-phpstan` MUST require the user to type `"I understand PHPStan will not run"` in the confirmation prompt unless `--no-interaction` is also set.

---

## 17. Phase 2 — Additional Hop Containers

### 17.1 Hop Container Template [P2]

All Phase 2 hop containers MUST follow the same structural pattern as the Phase 1 `hop-8-to-9` container (see §5). The following are the Phase 2 additions:

**TRD-P2HOP-001** [P2]  
Four new Docker images MUST be built:

| Image | PHP Base | Key Rector Sets |
|---|---|---|
| `upgrader:hop-9-to-10` | `php:8.1-cli-alpine` | `rector-laravel` L10 rules + custom `ReturnTypeRector` |
| `upgrader:hop-10-to-11` | `php:8.2-cli-alpine` | Slim skeleton suite (not Rector — see §4 PRD-P23) |
| `upgrader:hop-11-to-12` | `php:8.2-cli-alpine` | `rector-laravel` L12 rules + `RouteBindingAuditor` |
| `upgrader:hop-12-to-13` | `php:8.3-cli-alpine` | `rector-laravel` L13 rules + `PhpMinimumEnforcer` |

**TRD-P2HOP-002** [P2]  
Each new hop image MUST bundle its own `docs/breaking-changes.json` with all changes curated for that specific hop.

### 17.2 Slim Skeleton Generator [P2] `← SK-01 through SK-08`

**TRD-P2SLIM-001** [P2]  
The `hop-10-to-11` container MUST contain a `SlimSkeleton/` module (NOT a Rector rule set) consisting of:

```php
// Reads existing Kernel.php, generates bootstrap/app.php middleware section
class KernelMigrator {}

// Reads existing Handler.php, generates bootstrap/app.php exception section  
class ExceptionHandlerMigrator {}

// Detects non-standard logic that cannot be automatically migrated
class CustomLogicDetector {}

// Orchestrates the full skeleton replacement
class SlimSkeletonGenerator {}
```

**TRD-P2SLIM-002** [P2]  
`CustomLogicDetector` MUST preserve original `Kernel.php` and `Handler.php` at `app/Http/Kernel.php.lumen-backup` and `app/Exceptions/Handler.php.lumen-backup` respectively if they contain any method body beyond the framework default. These backup files MUST be included in the manual review report.

---

## 18. Phase 2 — Package Rule Sets

### 18.1 `PackageRuleActivator` [P2] `← PK-01, PK-02`

**TRD-P2PKG-001** [P2]  
`PackageRuleActivator::activate()` MUST:
1. Read `composer.lock` to get exact installed versions
2. For each supported package (see §6.2 PRD-P23), check if it's installed
3. If installed AND a rule set exists for the current hop → add rule set to `RectorConfigBuilder`
4. If installed AND version is outside the supported range → emit `package_version_mismatch` event and flag as manual review

**TRD-P2PKG-002** [P2]  
Package rule sets MUST be namespaced as `Rector\Rules\Packages\{PackageName}\{HopNamespace}\`. Each MUST follow the same `AbstractRectorTestCase` test pattern as core rules.

---

## 19. Phase 2 — CI/CD Integration

### 19.1 CI Mode [P2] `← CI-04`

**TRD-P2CI-001** [P2]  
When `--no-dashboard --no-interaction` are both set, the upgrader MUST:
- Write all output to stdout as JSON-ND (same format as container events)
- Exit with code `0` on success, `1` on upgrade failure, `2` on configuration error
- Write `report.json` and `audit.log.json` to `--output` directory
- NOT open a browser tab
- NOT prompt for any confirmation

### 19.2 GitHub Actions Template [P2] `← CI-01`

**TRD-P2CI-002** [P2]  
The bundled `ci-templates/github-actions/upgrader.yml` MUST include:
- `workflow_dispatch` trigger with `from_version` and `to_version` inputs
- Token sourced from `${{ secrets.UPGRADER_TOKEN }}` — NEVER hardcoded
- Artefact upload of the entire `upgrader-output/` directory
- Job summary written via `$GITHUB_STEP_SUMMARY` with upgrade stats

---

## 20. Phase 2 — Multi-Hop Orchestration

### 20.1 Extended `HopPlanner` [P2] `← MH-01 through MH-09`

**TRD-P2MULTI-001** [P2]  
`HopPlanner::plan()` MUST be extended to support `$from` 8–12 and `$to` 9–13. The returned `HopSequence` MUST contain hops in the correct incremental order (never skip a version).

**TRD-P2MULTI-002** [P2]  
The `UpgradeOrchestrator` MUST pass the verified workspace from hop N as the input workspace for hop N+1. The chain is: `original_repo → workspace_copy → hop1_output → hop2_output → ... → hopN_output → write_back_to_original`.

**TRD-P2MULTI-003** [P2]  
The unified report MUST be built incrementally. Each hop's `ReportBuilder` MUST append to a shared `report-context.json` in `/output/`, which the final hop assembles into the complete HTML report.

---

## 21. Phase 3 — PHP Hop Containers

### 21.1 PHP Image Template [P3] `← PH-01 through PH-08`

**TRD-P3PHP-001** [P3]  
Five PHP hop images MUST be built. Each MUST use the **target** PHP version as its base:

| Image | PHP Base | Rector Config |
|---|---|---|
| `upgrader:php-8.0-to-8.1` | `php:8.1-cli-alpine` | `LevelSetList::UP_TO_PHP_81` |
| `upgrader:php-8.1-to-8.2` | `php:8.2-cli-alpine` | `LevelSetList::UP_TO_PHP_82` |
| `upgrader:php-8.2-to-8.3` | `php:8.3-cli-alpine` | `LevelSetList::UP_TO_PHP_83` |
| `upgrader:php-8.3-to-8.4` | `php:8.4-cli-alpine` | `LevelSetList::UP_TO_PHP_84` |
| `upgrader:php-8.4-to-8.5` | `php:8.5-cli-alpine` | `LevelSetList::UP_TO_PHP_85` *(beta)* |

**TRD-P3PHP-002** [P3]  
PHP hop Rector configs MUST use `withPhpSets()` and MUST NOT include any `rector-laravel` rules. Example:

```php
return RectorConfig::configure()
    ->withPaths(['/workspace'])
    ->withSkipPath('/workspace/.upgrader-state')
    ->withSets([LevelSetList::UP_TO_PHP_84])
    ->withPhpSets(php84: true);
```

**TRD-P3PHP-003** [P3]  
The `upgrader:php-8.4-to-8.5` image MUST emit a `beta_hop_warning` event at startup:

```json
{
  "event": "beta_hop_warning",
  "message": "PHP 8.4→8.5 Rector rules are incomplete. This hop is BETA. Some breaking changes may not be detected or transformed automatically.",
  "requires_acknowledgement": true
}
```

The orchestrator MUST pause and require user confirmation (or `--no-interaction` flag) before proceeding.

---

## 22. Phase 3 — 2D HopPlanner

### 22.1 Extended Interface [P3] `← HP-01 through HP-08`

**TRD-P3PLAN-001** [P3]  
`HopPlanner::plan()` signature MUST be extended:

```php
public function plan(
    string $fromLaravel,    // e.g. "8" | "" (for PHP-only mode)
    string $toLaravel,      // e.g. "13" | "" (for PHP-only mode)
    string $fromPhp,        // e.g. "8.0" | "" (for Laravel-only mode)
    string $toPhp,          // e.g. "8.3" | "" (for Laravel-only mode)
): HopSequence;
```

**TRD-P3PLAN-002** [P3]  
The PHP minimum constraint table MUST be encoded as a constant and MUST NOT be hardcoded inline in planning logic:

```php
const PHP_FLOOR_PER_LARAVEL_VERSION = [
    '9'  => '8.0',
    '10' => '8.1',
    '11' => '8.2',
    '12' => '8.2',
    '13' => '8.3',
];
```

**TRD-P3PLAN-003** [P3]  
`HopPlanner::plan()` MUST throw `InvalidHopCombinationException` for invalid combinations. Error messages MUST be specific:

```
Cannot upgrade to Laravel 13 with target PHP 8.1.
Laravel 13 requires PHP >= 8.3. Set --to-php=8.3 or higher.
```

**TRD-P3PLAN-004** [P3]  
Before execution, the orchestrator MUST print the computed hop plan and require confirmation:

```
Computed upgrade plan (8 hops):
  1. [LARAVEL] 8 → 9   (using upgrader:hop-8-to-9,     PHP 8.0)
  2. [PHP]     8.0→8.1  (using upgrader:php-8.0-to-8.1, PHP 8.1)
  3. [LARAVEL] 9 → 10  (using upgrader:hop-9-to-10,    PHP 8.1)
  ...

Proceed? [y/N]:
```

---

## 23. Phase 3 — Extension Compatibility Checker

### 23.1 `ExtensionCompatibilityChecker` [P3] `← EC-01 through EC-07`

**TRD-P3EXT-001** [P3]  
`ExtensionCompatibilityChecker::check()` MUST run BEFORE any transforms begin. It MUST:
1. Parse all `ext-*` keys from `require` in `composer.json`
2. Load `/upgrader/docs/extension-compatibility.json` (bundled in each PHP hop image)
3. For each extension, determine support status for the target PHP version

**TRD-P3EXT-002** [P3]  
Outcome matrix:

| Status | Action |
|---|---|
| `confirmed_supported` | Silent pass |
| `confirmed_unsupported` | Emit `extension_blocker` event; halt unless `--skip-extension-check` |
| `unknown` | Emit `extension_warning` event; continue with warning in report |
| `custom_compiled` | Emit `extension_hard_stop` event; ALWAYS halt; cannot be overridden |

**TRD-P3EXT-003** [P3]  
Schema for bundled `extension-compatibility.json`:

```typescript
interface ExtensionCompatibilityMatrix {
  generated: string;
  php_versions: string[];   // ["8.0", "8.1", "8.2", "8.3", "8.4"]
  extensions: Record<string, ExtensionSupport>;
}
interface ExtensionSupport {
  [phpVersion: string]: "confirmed" | "unsupported" | "unknown";
  notes?: string;
}
```

---

## 24. Phase 3 — Silent Change Scanner

### 24.1 `PhpSilentChangeScanner` [P3] `← SC-01 through SC-06`

**TRD-P3SILENT-001** [P3]  
`PhpSilentChangeScanner` MUST run as a separate stage AFTER PHPStan in the PHP hop verification pipeline. It MUST use `nikic/php-parser` with a `NodeVisitor` to detect the following patterns:

**Null-to-non-nullable detection (PHP 8.1+):**
Detect function/method calls where an argument is `null` literal and the called function's parameter is typed as non-nullable. Flag as `REVIEW`.

**Dynamic property detection (PHP 8.2+):**
Detect property assignments on objects where the class does not declare `$property` and does not use `#[AllowDynamicProperties]`. Flag as `REVIEW`; do NOT auto-add the attribute (adding it silences a symptom, not the cause).

**Deprecated function detection:**
For each hop, a list of removed/deprecated functions is defined. Detect any calls to these functions via AST function call visitor. Flag as `REVIEW`.

**Implicit nullable detection (PHP 8.4+):**
Detect function/method signatures where a typed parameter has a default of `null` but the type is not nullable: `function foo(MyClass $x = null)`. Rector's `LevelSetList` SHOULD handle this; scanner runs as a verification that no cases were missed.

**TRD-P3SILENT-002** [P3]  
Each finding MUST emit a `silent_change_detected` event with:
```json
{
  "event": "silent_change_detected",
  "pattern": "null_to_nonnullable",
  "file": "app/Services/UserService.php",
  "line": 47,
  "column": 12,
  "php_version": "8.1",
  "description": "Passing null to non-nullable parameter $user of type User",
  "doc_url": "https://php.net/migration81.incompatible",
  "suggested_fix": "Change parameter type to ?User or update the call site"
}
```

---

## 25. Security Requirements

**TRD-SEC-001** [P1]  
`UPGRADER_TOKEN` MUST be read from environment variable or `--token` flag. It MUST be redacted in all log output. Implement a `TokenRedactor` utility that replaces any occurrence of the token string in log lines with `[REDACTED]`.

**TRD-SEC-002** [P1]  
Docker containers MUST run with `--network=none` at all times during the transform and verify stages. The pre-stage (composer dependency resolution) MAY use a network, but MUST be a separate container invocation.

**TRD-SEC-003** [P1]  
The `audit.log.json` MUST NOT contain:
- Source code content (file diffs, file contents)
- Authentication tokens
- Personally identifiable information
- Absolute host paths (use paths relative to repo root)

**TRD-SEC-004** [P1]  
Workspace directories MUST be created with mode `0700`. Report output directories MUST be created with mode `0755`. No world-readable workspace files.

**TRD-SEC-005** [P1]  
Docker images MUST be scanned with `docker scout` or equivalent as part of the CI build pipeline. Images with CRITICAL CVEs MUST NOT be published to the registry.

---

## 26. Performance Requirements

**TRD-PERF-001** [P1]  
A 500-file Laravel 8 repository MUST complete the full Phase 1 upgrade pipeline (L8→L9) in under 15 minutes on a machine with 4 CPU cores and 8 GB RAM.

**TRD-PERF-002** [P1]  
Dashboard MUST be reachable (HTTP 200 on `/`) within 5 seconds of `upgrader run` being invoked.

**TRD-PERF-003** [P1]  
PHPStan MUST run with `--parallel` using all available cores (detected via `\React\ChildProcess` or `nproc`). On a 4-core machine, this SHOULD reduce PHPStan time by 60–70% versus single-threaded.

**TRD-PERF-004** [P1]  
`SyntaxVerifier` MUST run `php -l` in parallel (max 8 concurrent processes via Symfony `Process` pool).

**TRD-PERF-005** [P1]  
`PHPStan` baseline MUST be cached to `/output/phpstan-baseline.json`. If this file exists at pipeline start, it MUST be used as the baseline without re-running PHPStan on the original code.

**TRD-PERF-006** [P2]  
A full L8→L13 multi-hop upgrade of a 500-file repository MUST complete in under 60 minutes total.

---

## 27. Data Contracts

### 27.1 Value Objects [P1]

All data passed between pipeline stages MUST be immutable value objects (PHP `readonly` classes). Mutable arrays as inter-stage contracts are prohibited.

```php
// Key value objects
final readonly class VerificationContext {
    public function __construct(
        public string $workspacePath,
        public string $hop,
        public string $phpVersion,
        public bool   $artisanEnabled,
        public bool   $phpStanEnabled,
    ) {}
}

final readonly class PipelineResult {
    public function __construct(
        public bool              $success,
        public int               $confidence,
        public VerifierResult[]  $verificationResults,
        public string[]          $manualReviewFiles,
        public string[]          $blockers,
    ) {}
}
```

### 27.2 File Output Contract [P1]

The `/output/` directory MUST contain exactly these files after a successful run:

```
upgrader-output/
├── report.html           # HTML diff report (offline-capable)
├── report.json           # Machine-readable summary
├── manual-review.md      # Developer action items
├── audit.log.json        # JSON-ND event log
├── phpstan-baseline.json # PHPStan baseline (cached for resume)
└── workspace/            # The upgraded codebase (ready to review)
    └── ...               # Full transformed repo
```

---

## 28. Dependency Manifest

### 28.1 Host-Side (Orchestrator) [P1]

```json
{
  "require": {
    "php": "^8.2",
    "symfony/console": "^6.4 || ^7.0",
    "symfony/process": "^6.4 || ^7.0",
    "react/http": "^1.9",
    "react/socket": "^1.14",
    "react/event-loop": "^1.3",
    "ramsey/uuid": "^4.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^1.10",
    "squizlabs/php_codesniffer": "^3.0"
  }
}
```

### 28.2 Container-Side — Hop 8→9 [P1]

```json
{
  "require": {
    "php": "^8.0",
    "nikic/php-parser": "^4.18",
    "symfony/process": "^6.0"
  },
  "require-dev": {
    "rector/rector": "^1.0",
    "driftingly/rector-laravel": "^1.0",
    "phpstan/phpstan": "^1.10",
    "phpunit/phpunit": "^10.0"
  }
}
```

> **F-06 NOTE:** `driftingly/rector-laravel` MUST be pinned to a specific minor version (e.g. `"1.2.3"` not `"^1.0"`) in each hop's `composer.json`. A fork mirror MUST be maintained at `vendor-patches/rector-laravel-fork/`.

### 28.3 Prohibited Dependencies

The following MUST NOT appear in any `composer.json` in this project:

| Package | Reason |
|---|---|
| Any Rector fork | F-01: only official `rector/rector` permitted |
| `laravel/framework` | Upgrader must not depend on what it transforms |
| Any package requiring PHP < 8.0 for host code | Host runs PHP 8.2+ |

---

## 29. Build & CI Requirements

### 29.1 CI Pipeline Structure [P1] `← TS-06`

```yaml
# Required CI jobs (order matters)
jobs:
  - name: lint
    run: composer cs-check
    
  - name: static-analysis
    run: composer phpstan
    
  - name: unit-tests
    run: composer test
    timeout: 5m
    
  - name: build-images
    run: docker buildx bake --platform linux/amd64,linux/arm64
    needs: [lint, static-analysis, unit-tests]
    
  - name: integration-tests
    run: composer test:integration
    needs: [build-images]
    timeout: 20m
    
  - name: image-security-scan
    run: docker scout cves upgrader:hop-8-to-9
    needs: [build-images]
```

### 29.2 Image Build Requirements [P1]

**TRD-BUILD-001** [P1]  
Docker images MUST be built deterministically. `composer.lock` MUST be committed for every container's `composer.json`. CI MUST fail if `composer.lock` is out of sync with `composer.json`.

**TRD-BUILD-002** [P1]  
Image tags MUST follow `{image}:{semver}` (e.g. `upgrader:hop-8-to-9:1.0.0`) for releases and `{image}:edge` for main branch builds.

**TRD-BUILD-003** [P1]  
The `rector-laravel` pinned version in each hop's `composer.lock` MUST be documented in `DEPENDENCY-AUDIT.md` with the date last reviewed and the upstream commit SHA it was pinned to.

---

## 30. Traceability Matrix

| TRD ID | PRD Requirement | Phase | Module | Status |
|---|---|---|---|---|
| TRD-REPO-001 | RF-04 | P1 | `LocalRepositoryFetcher`, `GitHubRepositoryFetcher` | Specified |
| TRD-REPO-002 | RF-05 | P1 | `TokenRedactor`, all fetchers | Specified |
| TRD-REPO-003 | RF-06, F-07 | P1 | `WorkspaceManager` | Specified |
| TRD-ORCH-001 | PRD §6 | P1 | `UpgradeOrchestrator` | Specified |
| TRD-ORCH-002 | VP-12 | P1 | `UpgradeOrchestrator` | Specified |
| TRD-ORCH-004 | PRD §6.3 | P1 | `DockerRunner` | Specified |
| TRD-ORCH-005 | PRD §6.2 | P1 | `DockerRunner`, `EventStreamer` | Specified |
| TRD-STATE-001 | ST-01, F-03 | P1 | `TransformCheckpoint` | Specified |
| TRD-STATE-003 | ST-03, ST-05 | P1 | `WorkspaceReconciler` | Specified |
| TRD-DOCKER-001 | PRD §6.3 | P1 | All Dockerfiles | Specified |
| TRD-DOCKER-003 | NFR §9.1 | P1 | `DockerRunner` | Specified |
| TRD-RECTOR-001 | RE-02, F-01 | P1 | `RectorRunner` | **CRITICAL** |
| TRD-RECTOR-004 | RE-05 | P1 | `RectorConfigBuilder` | Specified |
| TRD-RECTOR-005 | RE-07 | P1 | `RectorRunner` | Specified |
| TRD-RECTOR-006 | RE-04 | P1 | `WorkspaceManager` | Specified |
| TRD-REG-001 | DC-01 | P1 | `BreakingChangeRegistry` | Specified |
| TRD-COMP-001 | CD-01, CD-02, CD-03 | P1 | `DependencyUpgrader` | Specified |
| TRD-COMP-002 | CD-03 | P1 | `DependencyUpgrader` | Specified |
| TRD-CONFIG-001 | CM-01, CM-02, F-10 | P1 | `ConfigMigrator` | **CRITICAL** |
| TRD-CONFIG-003 | CM-06 | P1 | `ConfigMigrator` | Specified |
| TRD-LUMEN-001 | LM-01 | P1 | `LumenDetector` | Specified |
| TRD-LUMEN-004 | LM-07, F-08 | P1 | `FacadeBootstrapMigrator` | Specified |
| TRD-LUMEN-005 | LM-08, F-08 | P1 | `InlineConfigExtractor` | Specified |
| TRD-VERIFY-001 | VP-01–VP-12 | P1 | `VerificationPipeline` | Specified |
| TRD-VERIFY-004 | VP-04, F-12 | P1 | `PhpStanVerifier` | Specified |
| TRD-VERIFY-006 | VP-05–07, F-04 | P1 | `StaticArtisanVerifier` | **CRITICAL** |
| TRD-DASH-001 | DB-01, F-02 | P1 | `ReactDashboardServer` | **CRITICAL** |
| TRD-DASH-003 | DB-02, DB-03 | P1 | `ReactDashboardServer` | Specified |
| TRD-EVENTS-001 | PRD §6.2 | P1 | All containers | Specified |
| TRD-EVENTS-002 | PRD §6.2 | P1 | `EventStreamer` | Specified |
| TRD-REPORT-001 | RP-01, F-11 | P1 | `HtmlFormatter` | **CRITICAL** |
| TRD-REPORT-003 | RP-03, RP-04 | P1 | `ConfidenceScorer` | Specified |
| TRD-TEST-001 | TS-01, F-05 | P1 | `tests/Unit/` | Specified |
| TRD-TEST-004 | TS-03–05, F-05 | P1 | `tests/Integration/` | Specified |
| TRD-CLI-001 | PRD §10 | P1 | `RunCommand` | Specified |
| TRD-CLI-003 | `--skip-phpstan` | P1 | `RunCommand` | Specified |
| TRD-P2HOP-001 | PRD-P23 §3.1 | P2 | 4× Dockerfiles | Specified |
| TRD-P2SLIM-001 | SK-01–SK-08 | P2 | `SlimSkeleton/` module | Specified |
| TRD-P2PKG-001 | PK-01, PK-02 | P2 | `PackageRuleActivator` | Specified |
| TRD-P2CI-001 | CI-04 | P2 | `RunCommand` CI mode | Specified |
| TRD-P2MULTI-001 | MH-01–MH-09 | P2 | `HopPlanner` extended | Specified |
| TRD-P3PHP-001 | PH-01–PH-08 | P3 | 5× PHP Dockerfiles | Specified |
| TRD-P3PHP-003 | PH-07 | P3 | `upgrader:php-8.4-to-8.5` | Specified |
| TRD-P3PLAN-001 | HP-01–HP-08 | P3 | `HopPlanner` 2D | Specified |
| TRD-P3PLAN-003 | HP-05 | P3 | `HopPlanner` | Specified |
| TRD-P3EXT-001 | EC-01–EC-07 | P3 | `ExtensionCompatibilityChecker` | Specified |
| TRD-P3SILENT-001 | SC-01–SC-06 | P3 | `PhpSilentChangeScanner` | Specified |
| TRD-SEC-001 | NFR §9.1 | P1 | `TokenRedactor` | Specified |
| TRD-PERF-001 | NFR §9.2 | P1 | System-wide | Specified |
| TRD-BUILD-001 | TS-06 | P1 | CI pipeline | Specified |

---

*Laravel Enterprise Upgrader — Technical Requirements Document v1.0*  
*Authored by Marcus Webb, Senior Technical Staff Lead · March 2026*  
*Derived from PRD v2.0 (Post-Audit) — 96% confidence build plan*

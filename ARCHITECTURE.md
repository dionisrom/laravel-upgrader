# Architecture

## Overview

Laravel Enterprise Upgrader is a CLI tool that orchestrates Docker containers to upgrade Laravel applications across major versions. It is designed around two principles:

1. **Container isolation** — all code transformations run inside short-lived Docker containers with `--network=none`; the host process only orchestrates and reports
2. **Original-safe** — the original repository is never modified until all hops complete and verification passes

---

## Component Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│  CLI (bin/upgrader)                                                  │
│  Symfony Console Application                                         │
│                                                                      │
│  ┌──────────────┐  ┌───────────────┐  ┌──────────┐  ┌───────────┐  │
│  │  RunCommand  │  │AnalyseCommand │  │Dashboard │  │  Version  │  │
│  │  (+ resume)  │  │  (dry-run)    │  │ Command  │  │ Command   │  │
│  └──────┬───────┘  └───────────────┘  └──────────┘  └───────────┘  │
│         │ InputValidator  TokenRedactor                              │
└─────────┼───────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Orchestrator Layer                                                  │
│                                                                      │
│  UpgradeOrchestrator                                                 │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │  HopPlanner ──► HopSequence (ordered list of Hop VOs)       │    │
│  │                                                             │    │
│  │  foreach Hop:                                               │    │
│  │    WorkspaceManager.createWorkspace()                       │    │
│  │    DockerRunner.run(hop, workspace, output, streamer)       │    │
│  │         │                                                   │    │
│  │         │  stdout (JSON-ND lines)                          │    │
│  │         ▼                                                   │    │
│  │    EventStreamer                                            │    │
│  │    ┌──────────┬──────────────────┬──────────────────┐      │    │
│  │    │          │                  │                  │      │    │
│  │    ▼          ▼                  ▼                  ▼      │    │
│  │ Terminal  AuditLog          EventBus          Checkpoint   │    │
│  │ Renderer  Writer            (SSE push)        Manager      │    │
│  │                               │                            │    │
│  │                               ▼                            │    │
│  │                     ReactDashboardServer                    │    │
│  │                     (port 8765, SSE)                        │    │
│  └─────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Docker Container (per hop)                                          │
│  --network=none  USER upgrader (UID 1000)  /repo RW  /output RW     │
│                                                                      │
│  entrypoint.sh                                                       │
│  ├── Detector   (detect Laravel version, PHP version)               │
│  ├── Rector     (subprocess — never require'd)                      │
│  │   └── rector.l8-to-l9.php (custom rules + rector-laravel)        │
│  ├── VerificationPipeline                                            │
│  │   ├── php -l  (syntax check all changed files)                   │
│  │   ├── composer validate                                           │
│  │   ├── PHPStan level 3 baseline delta                              │
│  │   └── [opt] php artisan route:list (--with-artisan-verify)       │
│  ├── ReportGenerator  (HTML + JSON + MD)                             │
│  └── EventEmitter     (JSON-ND → stdout, one object per line)       │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Data Flow

```
1. Repository Fetch
   RepositoryFetcherFactory resolves fetcher by repo string prefix:
   • github:org/repo  → GitHubRepositoryFetcher (git clone via token)
   • gitlab:org/repo  → GitLabRepositoryFetcher
   • /local/path      → LocalRepositoryFetcher (noop — use path directly)
   Result: FetchResult { localPath, commitSha }

2. Workspace Creation
   WorkspaceManager.createWorkspace(repoPath, targetVersion)
   • SHA-256 content-addressed temp dir under /tmp/upgrader/{hash}
   • Created with 0700 permissions
   • Advisory flock acquired on repoPath (prevents concurrent upgrades)
   • Full directory copy of repo into workspace

3. Hop Execution (one per version increment)
   DockerRunner.buildCommand(hop, workspacePath, outputPath)
   • mounts workspace as /repo RW, output dir as /output RW
   • --network=none, --env UPGRADER_HOP_FROM/TO
   • stdout piped to EventStreamer line-by-line

4. Event Streaming
   Each JSON-ND line from container stdout → EventParser.parseLine()
   → typed event VO dispatched to all EventConsumerInterface instances:
   • TerminalRenderer  → progress bars, file change list
   • AuditLogWriter    → appends sanitized JSON to audit.log.json
   • EventBus          → pushes SSE to ReactDashboardServer clients
   • CheckpointManager → writes checkpoint.json on checkpoint_written events

5. Verification (inside container)
   VerificationPipeline runs after Rector transforms:
   • php -l on all changed PHP files
   • composer validate --no-check-all
   • PHPStan baseline delta (regressions fail the hop)
   • [opt] php artisan route:list

6. Report Generation (inside container, written to /output)
   • report.html  — Diff2Html, bundled CSS/JS, no external deps
   • report.json  — structured JSON with per-file metadata
   • manual-review.md — human-review list

7. Write-Back (host, only on full success)
   WorkspaceManager.writeBack(workspacePath, originalRepoPath)
   Then: WorkspaceManager.cleanup(workspacePath) → releaseLock
```

---

## Key Design Decisions

### 1. ReactPHP for the Dashboard (not PHP built-in server)

PHP's built-in server is single-threaded. Serving multiple SSE connections while Rector runs in a subprocess would deadlock — the server thread would block on the subprocess, and SSE clients would time out. ReactPHP's event loop multiplexes I/O concurrently in a single thread without blocking. See: `src/Dashboard/ReactDashboardServer.php`.

### 2. Rector as a Subprocess (not `require`'d)

Rector has complex internal state that accumulates across files, and its version may conflict with the host tool's dependencies. Running Rector as a subprocess (`symfony/process`) gives:
- **Memory isolation** — container-level OOM does not crash the host
- **Version independence** — the container can pin any Rector version
- **Reproducibility** — the same PHP + Rector version runs regardless of host PHP

### 3. Content-Addressed Workspaces

Workspace directories are named by `sha256(repoPath + targetVersion + microtime)`. This means:
- Multiple concurrent upgrade runs on different repos never collide
- Advisory `flock()` on the repo path prevents two runs upgrading the same repo simultaneously (`ConcurrentUpgradeException`)
- Cleanup is safe — only the upgrader process that created a workspace cleans it up

### 4. JSON-ND Protocol for Container → Host Communication

Containers write one JSON object per line to stdout. This is the simplest reliable streaming protocol for subprocess communication:
- No framing complexity (newline-delimited)
- Easily parsed by `EventParser` without buffering
- Partial lines are held in a buffer and flushed only when `\n` is seen
- stderr is collected separately and never mixed with event stream

### 5. Opt-In Artisan Verification

Running `php artisan` requires a working `.env`, database connection, and potentially queue workers. Enterprise CI environments rarely have these available in a network-isolated container. Static verification (PHPStan + `php -l`) is always safe; artisan verification is behind `--with-artisan-verify` for environments that can provide it.

### 6. Atomic Checkpoint Writes

`TransformCheckpoint.write()` always writes to `checkpoint.json.tmp` first, then `rename()`. On POSIX filesystems, `rename` is atomic. This guarantees checkpoints are never partially written — a crash mid-write leaves the old checkpoint intact, so `--resume` always sees a consistent state.

---

## File Layout

```
laravel-upgrader/
├── bin/
│   └── upgrader              # Symfony Console entry point
├── src/
│   ├── Commands/             # CLI command classes + InputValidator + TokenRedactor
│   ├── Dashboard/            # ReactDashboardServer (ReactPHP SSE) + EventBus
│   ├── Orchestrator/
│   │   ├── Events/           # EventParser + 15 typed event VOs + EventCatalogue
│   │   ├── State/            # TransformCheckpoint, WorkspaceReconciler, FileHasher
│   │   ├── DockerRunner.php  # Builds + runs docker commands, streams stdout
│   │   ├── EventStreamer.php # Fan-out dispatcher to EventConsumerInterface list
│   │   ├── HopPlanner.php    # Resolves version pair → HopSequence
│   │   ├── UpgradeOrchestrator.php
│   │   └── ...               # Hop, HopSequence, AuditLogWriter, TerminalRenderer
│   ├── Repository/           # GitHub / GitLab / Local fetchers
│   └── Workspace/            # WorkspaceManager, DiffGenerator, ApplyResult
├── src-container/            # Code that runs INSIDE Docker containers
│   ├── BreakingChangeRegistry.php
│   ├── EventEmitter.php      # JSON-ND stdout emitter
│   ├── Composer/             # Dependency version patching
│   ├── Config/               # Laravel config file migrators
│   ├── Detector/             # Laravel / PHP version detection
│   ├── Lumen/                # Lumen migration helpers
│   ├── Rector/               # Custom Rector rules for L8→L9
│   ├── Report/               # HTML / JSON / MD report generators
│   └── Verification/         # VerificationPipeline (PHPStan, php -l, etc.)
├── docker/
│   ├── hop-8-to-9/           # Dockerfile + entrypoint.sh for L8→L9 hop
│   └── lumen-migrator/       # Dockerfile + entrypoint.sh for Lumen migration
├── rector-configs/
│   └── rector.l8-to-l9.php   # Rector config used inside hop-8-to-9 container
├── assets/
│   ├── diff2html.min.css     # Bundled — no CDN dependency
│   └── diff2html.min.js
├── tests/
│   ├── Unit/                 # PHPUnit unit tests
│   ├── Integration/          # PHPUnit integration tests (require Docker)
│   └── Fixtures/             # Sample Laravel apps for test runs
└── docker-bake.hcl           # BuildKit bake file — builds all images in parallel
```

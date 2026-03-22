# P1-10: Orchestrator, HopPlanner & DockerRunner

**Phase:** 1 — MVP  
**Priority:** Critical  
**Estimated Effort:** 5-6 days  
**Dependencies:** P1-01 (Project Scaffold), P1-02 (Repository Fetcher), P1-08 (Workspace Manager), P1-09 (Docker Image)  
**Blocks:** P1-11 (Event Streaming), P1-19 (CLI Commands)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Docker CLI invocation from PHP via `symfony/process`
- Process stdout streaming and line-by-line parsing
- Understanding of the hop-based upgrade model (version N → N+1)
- Symfony Console application lifecycle
- Non-blocking I/O concepts for feeding events to ReactPHP dashboard

---

## Objective

Implement the host-side orchestration layer: `UpgradeOrchestrator.php`, `HopPlanner.php`, `DockerRunner.php`, and `EventStreamer.php`. These coordinate the entire upgrade pipeline — planning hops, launching Docker containers, streaming events, and managing the upgrade lifecycle.

---

## Context from PRD & TRD

### UpgradeOrchestrator (TRD §3.1 — TRD-ORCH-001, TRD-ORCH-002)

- `run()` executes hops in sequence from `HopPlanner`
- Each hop receives verified workspace output of previous hop
- If any hop's verification fails → halt immediately, no subsequent hops run
- Write-back to original repo ONLY after ALL hops complete with `$passed === true`

### HopPlanner — Phase 1 (TRD §3.2 — TRD-ORCH-003)

```php
final class HopPlanner
{
    public function plan(string $from, string $to): HopSequence;
}

final class HopSequence
{
    /** @param Hop[] $hops */
    public function __construct(public readonly array $hops) {}
}

final readonly class Hop
{
    public function __construct(
        public string $dockerImage,     // e.g. 'upgrader:hop-8-to-9'
        public string $fromVersion,     // '8'
        public string $toVersion,       // '9'
        public string $type,            // 'laravel' | 'php'
        public ?string $phpBase,        // '8.0'
    ) {}
}
```

- Phase 1: validates `$from === '8'` and `$to === '9'`
- Throws `InvalidHopException` for unsupported versions or `$from >= $to`

### DockerRunner (TRD §3.3 — TRD-ORCH-004, TRD-ORCH-005)

Docker command signature:

```bash
docker run --rm \
  --network=none \
  -v {workspacePath}:/repo:rw \
  -v {outputPath}:/output:rw \
  --env UPGRADER_HOP_FROM={from} \
  --env UPGRADER_HOP_TO={to} \
  {dockerImage}
```

- Read container stdout line by line
- Pass each line to `EventStreamer::dispatch()`
- Capture stderr → append to `audit.log.json` under `"stderr_lines"`
- Non-zero exit code = hop failure

### EventStreamer (TRD §13.2 — TRD-EVENTS-001, TRD-EVENTS-002)

Fan out each parsed event to THREE consumers simultaneously:
1. `ReactDashboardServer::broadcast()` — SSE
2. `TerminalRenderer::render()` — CLI output
3. `AuditLogWriter::append()` — `audit.log.json`

Non-JSON lines from stdout → discard + log warning. Failure in one consumer MUST NOT prevent delivery to others.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `UpgradeOrchestrator.php` | `src/Orchestrator/` | Main orchestration loop |
| `HopPlanner.php` | `src/Orchestrator/` | Version validation and hop sequence |
| `DockerRunner.php` | `src/Orchestrator/` | Spawns and monitors Docker containers |
| `EventStreamer.php` | `src/Orchestrator/` | Fans out JSON-ND events to consumers |
| `Hop.php` | `src/Orchestrator/` | Value object for a single hop |
| `HopSequence.php` | `src/Orchestrator/` | Value object for ordered hop list |
| `InvalidHopException.php` | `src/Orchestrator/` | Exception for invalid version combos |
| `TerminalRenderer.php` | `src/Orchestrator/` | CLI output rendering from events |
| `AuditLogWriter.php` | `src/Orchestrator/` | JSON-ND append to audit.log.json |

---

## Acceptance Criteria

- [ ] `HopPlanner::plan('8', '9')` returns a single-hop sequence for Phase 1
- [ ] `HopPlanner::plan()` throws `InvalidHopException` for unsupported versions
- [ ] `DockerRunner` invokes `docker run --rm --network=none` with correct mounts
- [ ] Container stdout read line by line and dispatched to EventStreamer
- [ ] Container stderr captured and appended to audit log
- [ ] Non-zero container exit treated as hop failure
- [ ] `EventStreamer` fans events to 3 consumers; failure in one does not block others
- [ ] Non-JSON stdout lines discarded with warning
- [ ] `UpgradeOrchestrator` halts on verification failure
- [ ] Original repo unmodified until all hops pass verification
- [ ] All value objects are `final readonly class`

---

## Implementation Notes

- `DockerRunner` uses `symfony/process` with real-time stdout callback
- `EventStreamer` should use an observer/listener pattern for extensibility
- `TerminalRenderer` can use Symfony Console's `OutputInterface`
- `AuditLogWriter` appends enriched events (adding `run_id`, `host_version`, `repo_sha`)
- In Phase 2, `HopPlanner` will be extended for multi-hop (L8→L13)
- In Phase 3, `HopPlanner` adds PHP dimension (2D planning)
- Keep interfaces clean for Phase 2/3 extension

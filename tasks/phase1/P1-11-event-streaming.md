# P1-11: Event Streaming & JSON-ND Protocol

**Phase:** 1 — MVP  
**Priority:** Must Have  
**Estimated Effort:** 3-4 days  
**Dependencies:** P1-01 (Project Scaffold), P1-10 (Orchestrator — EventStreamer interface)  
**Blocks:** P1-17 (Dashboard — consumes events via SSE), P1-18 (Report Generator — reads audit log)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- JSON-ND (Newline-Delimited JSON) streaming protocol
- Event-driven architecture patterns (observer/listener)
- SSE (Server-Sent Events) data format
- PHP stream handling and buffering
- Structured logging (JSON format, monotonic sequence numbers)

---

## Objective

Define and implement the complete JSON-ND event protocol used for all container→host communication, including the event catalogue, base schema, event emitters (container-side), and event consumers (host-side).

---

## Context from PRD & TRD

### Base Event Schema (TRD §13.1)

```typescript
interface BaseEvent {
    event: string;       // event type
    hop: string;         // e.g. "8_to_9"
    ts: number;          // Unix timestamp (seconds, float)
    seq: number;         // monotonically increasing per run
}
```

### Event Catalogue (TRD §13.2)

| Event Type | When Emitted | Required Fields |
|---|---|---|
| `pipeline_start` | Container starts | `total_files`, `php_files`, `config_files` |
| `stage_start` | Stage begins | `stage` (inventory/rector/composer/config/lumen/verify/report) |
| `stage_complete` | Stage completes | `stage`, `duration_seconds`, `issues_found` |
| `file_changed` | File transformed | `file` (relative), `rules` (array), `lines_added`, `lines_removed` |
| `checkpoint_written` | Checkpoint saved | `completed_rules_count`, `pending_rules_count` |
| `breaking_change_applied` | BC auto-fixed | `id`, `automated: true`, `file_count` |
| `manual_review_required` | BC can't auto-fix | `id`, `automated: false`, `reason`, `files` (array) |
| `dependency_blocker` | Package incompatible | `package`, `current_version`, `severity` |
| `verification_result` | Each verifier completes | `step`, `passed`, `issue_count`, `duration_seconds` |
| `phpstan_regression` | Error count increased | `before_count`, `after_count`, `new_errors` (array) |
| `hop_complete` | Container exits ok | `confidence`, `manual_review_count`, `files_changed` |
| `pipeline_error` | Unrecoverable error | `message`, `stage`, `recoverable: false` |
| `warning` | Non-fatal issue | `message`, `context` |

### Consumer Fan-Out (TRD-EVENTS-002)

`EventStreamer` dispatches each event to three consumers:
1. `ReactDashboardServer::broadcast()` — SSE
2. `TerminalRenderer::render()` — CLI output
3. `AuditLogWriter::append()` — `audit.log.json`

Failure in one consumer MUST NOT prevent delivery to others.

### Malformed Event Handling (TRD-EVENTS-001)

Non-JSON lines from container stdout → discard + log `"malformed_event"` warning to stderr.

### Audit Log Enrichment (TRD-REPORT-005)

Each event written to `audit.log.json` is enriched with:
- `run_id`: UUID v4 (generated at orchestrator start)
- `host_version`: upgrader tool semver
- `repo_sha`: commit SHA of cloned repo

MUST NOT contain: source code, file contents, auth tokens.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `EventEmitter.php` | `src-container/` | Container-side event emission to stdout |
| `BaseEvent.php` | `src/Orchestrator/Events/` | Base event value object |
| `EventCatalogue.php` | `src/Orchestrator/Events/` | Event type constants |
| `EventParser.php` | `src/Orchestrator/Events/` | Parse JSON-ND lines into event objects |
| `EventConsumerInterface.php` | `src/Orchestrator/Events/` | Interface for event consumers |

---

## Acceptance Criteria

- [ ] All event types from catalogue implemented as constants
- [ ] `EventEmitter` (container-side) outputs valid JSON-ND to stdout
- [ ] Sequence numbers are monotonically increasing per run
- [ ] `EventParser` correctly deserializes all event types
- [ ] Malformed lines logged as warnings and discarded (not exceptions)
- [ ] `AuditLogWriter` enriches events with `run_id`, `host_version`, `repo_sha`
- [ ] Audit log NEVER contains source code, file contents, or tokens
- [ ] Consumer failure isolation: one failing consumer doesn't block others
- [ ] Events use relative file paths (not absolute host paths) per TRD-SEC-003

---

## Implementation Notes

- Container-side `EventEmitter` is a simple class with `emit(array $data)` that JSON-encodes and echoes
- Host-side parsing happens in `EventStreamer` (P1-10) using `EventParser`
- The dashboard (P1-17) receives events already parsed — it doesn't parse JSON-ND itself
- Consider an `EventConsumerInterface` for clean fan-out architecture
- The `seq` field is per-run — reset to 0 on each container invocation

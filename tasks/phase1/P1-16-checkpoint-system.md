# P1-16: State & Checkpoint System

**Phase:** 1 — MVP  
**Priority:** Critical  
**Estimated Effort:** 4-5 days  
**Dependencies:** P1-01 (Project Scaffold), P1-08 (Workspace Manager — file hashing)  
**Blocks:** P1-10 (Orchestrator — resume capability), P1-19 (CLI — --resume flag)  

---

## Agent Persona

**Role:** PHP Orchestrator Engineer  
**Agent File:** `agents/php-orchestrator-engineer.agent.md`  
**Domain Knowledge Required:**
- Atomic file write patterns (write to tmp → rename)
- SHA-256 hashing of file contents
- JSON schema design for checkpoint state
- Resume/recovery patterns in long-running pipeline processes
- File modification detection via content hashing

---

## Objective

Implement `TransformCheckpoint.php` and `WorkspaceReconciler.php` in `src/Orchestrator/State/`. These provide checkpoint-based resume capability so interrupted upgrades can continue from where they left off, rather than re-applying already-completed transformations.

---

## Context from PRD & TRD

### TransformCheckpoint (TRD §4.1 — TRD-STATE-001, TRD-STATE-002, F-03)

Writes to `/workspace/.upgrader-state/checkpoint.json` after every Rector rule batch. Write MUST be atomic (write to `.tmp` → rename).

**Checkpoint Schema:**

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

SHA-256 hashes MUST be computed over file content bytes (not metadata). Format: `"sha256:{64-hex-chars}"`.

### WorkspaceReconciler (TRD §4.2 — TRD-STATE-003, TRD-STATE-004)

On `--resume`, `reconcile()` MUST:
1. Read `checkpoint.json`
2. Re-hash every file in `files_hashed`
3. Current hash === checkpoint hash → mark `already_transformed`, skip
4. Current hash differs → emit `WARNING`, prompt user confirmation
5. Return `ReconcileResult` with `$pendingRules` filtered

No checkpoint + `--resume` → throw `NoCheckpointException`:
`"No checkpoint found at {path}. Run without --resume to start a fresh upgrade."`

### PRD Requirements

| ID | Requirement |
|---|---|
| ST-01 | Checkpoint written after each rule batch |
| ST-02 | Records: completed rules, pending rules, SHA-256 per file, timestamp |
| ST-03 | Re-hashes on resume; skips already-transformed files |
| ST-04 | `--resume` resumes from last valid checkpoint |
| ST-05 | Externally modified files trigger warning before resume |

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `TransformCheckpoint.php` | `src/Orchestrator/State/` | Checkpoint write/read |
| `WorkspaceReconciler.php` | `src/Orchestrator/State/` | Resume reconciliation logic |
| `Checkpoint.php` | `src/Orchestrator/State/` | Checkpoint value object |
| `ReconcileResult.php` | `src/Orchestrator/State/` | Reconciliation result value object |
| `NoCheckpointException.php` | `src/Orchestrator/State/` | Exception for missing checkpoint |
| `FileHasher.php` | `src/Orchestrator/State/` | SHA-256 content hashing utility |

---

## Acceptance Criteria

- [ ] Checkpoint written to `.upgrader-state/checkpoint.json` after each rule batch
- [ ] Checkpoint write is atomic (tmp file → rename)
- [ ] Checkpoint contains: hop, completed/pending rules, file hashes, timestamp, version
- [ ] SHA-256 hashes computed from file content bytes (format: `sha256:{hex}`)
- [ ] `--resume` reads checkpoint and reconciles file state
- [ ] Files matching checkpoint hash → skipped (already transformed)
- [ ] Files with changed hash → WARNING event + user confirmation prompt
- [ ] No checkpoint + `--resume` → `NoCheckpointException` with clear message
- [ ] `schema_version` field included for future checkpoint format changes
- [ ] `host_version` field records upgrader tool version
- [ ] `can_resume: false` set when checkpoint is in an inconsistent state
- [ ] Checkpoint directory `.upgrader-state` is excluded from Rector processing

---

## Implementation Notes

- The checkpoint JSON is the single source of truth for resume
- Atomic write pattern: `file_put_contents($path . '.tmp', $json)` → `rename($path . '.tmp', $path)`
- File hashing should be efficient — consider reading files in chunks for large files
- The `ReconcileResult` tells the orchestrator which rules still need to run and which files to skip
- External modification warning should be clear about WHICH files changed
- In Phase 2, checkpoints span multiple hops (multi-hop chain resumability)

# Checkpoint & Event Log Structure

Reference for the `upgrade-pipeline-debug` skill. Documents the JSON schema for `TransformCheckpoint`, `ChainCheckpoint`, and the JSON-ND event log format.

---

## TransformCheckpoint (Single Hop)

Written to `{workspace}/checkpoint-{hop-name}.json` by `CheckpointManager` after each hop completes or fails.

```json
{
  "version": 1,
  "checkpoint_id": "cp_a1b2c3d4",
  "workspace_id": "ws_xyz789",
  "hop_name": "hop-9-to-10",
  "status": "completed",
  "started_at": "2025-01-15T10:30:00Z",
  "completed_at": "2025-01-15T10:35:22Z",
  "source_commit": "abc123def456",
  "php_version_used": "8.1",
  "rector_version": "1.2.3",
  "files_changed": [
    "app/Http/Controllers/UserController.php",
    "app/Models/User.php"
  ],
  "files_skipped": [],
  "rector_output": {
    "changed_files": 12,
    "errors": []
  },
  "phpstan_result": {
    "total_errors": 0,
    "error_delta": -3
  },
  "breaking_changes_applied": ["BC-001", "BC-004", "BC-007"],
  "manual_review_required": ["BC-002", "BC-005"],
  "error": null
}
```

### Field Reference

| Field | Type | Description |
|---|---|---|
| `version` | int | Schema version — always `1` for Phase 1 |
| `checkpoint_id` | string | Unique ID for this checkpoint, used in resume |
| `workspace_id` | string | ID of the upgrade workspace this belongs to |
| `hop_name` | string | The hop that was (attempted to be) applied |
| `status` | string | `pending` / `in_progress` / `completed` / `failed` / `skipped` |
| `started_at` | ISO 8601 | When the hop container was started |
| `completed_at` | ISO 8601 | When the hop container exited (null if still running) |
| `source_commit` | string | Git SHA of the workspace state before this hop |
| `php_version_used` | string | PHP version in the hop container |
| `rector_version` | string | Rector version used |
| `files_changed` | string[] | Relative paths of files modified by Rector |
| `files_skipped` | string[] | Files Rector skipped (vendor, or explicit skips) |
| `rector_output` | object | Raw summary from Rector's JSON output format |
| `phpstan_result` | object | PHPStan summary: total errors before and delta |
| `breaking_changes_applied` | string[] | IDs from `breaking-changes.json` that were auto-fixed |
| `manual_review_required` | string[] | IDs from `breaking-changes.json` needing manual fix |
| `error` | object\|null | Error details if `status == "failed"` |

### Error Object (when status == "failed")

```json
{
  "stage": "rector",
  "exit_code": 1,
  "message": "PhpParser\\Error: Syntax error at line 42 in /workspace/app/Foo.php",
  "file": "app/Foo.php",
  "line": 42
}
```

### Status Values

| Value | Meaning | Can Resume? |
|---|---|---|
| `pending` | Not yet started | Yes — will run normally |
| `in_progress` | Container running (or crashed without writing final status) | Yes — will re-run the hop |
| `completed` | Hop finished successfully | Yes — will skip this hop |
| `failed` | Hop exited with non-zero | Yes — will re-run the hop |
| `skipped` | Hop was skipped (already at target version) | Yes — will skip again |

To manually force a hop to retry, set its status to `pending`:

```bash
jq '.status = "pending" | .error = null' checkpoint-hop-9-to-10.json > /tmp/cp.json \
    && mv /tmp/cp.json checkpoint-hop-9-to-10.json
```

---

## ChainCheckpoint (Full Chain)

Written to `{workspace}/checkpoint.json` by `ChainRunner`. Wraps all individual hop checkpoints.

```json
{
  "version": 1,
  "chain_id": "chain_abc123",
  "workspace_id": "ws_xyz789",
  "chain_status": "in_progress",
  "hops": ["hop-8-to-9", "hop-9-to-10", "hop-10-to-11"],
  "current_hop": "hop-9-to-10",
  "hop_statuses": {
    "hop-8-to-9": "completed",
    "hop-9-to-10": "failed",
    "hop-10-to-11": "pending"
  },
  "started_at": "2025-01-15T10:00:00Z",
  "updated_at": "2025-01-15T10:35:22Z",
  "resume_from": null,
  "options": {
    "dry_run": false,
    "stop_on_failure": true,
    "php_version_override": null
  }
}
```

### Field Reference

| Field | Type | Description |
|---|---|---|
| `chain_id` | string | Unique ID for this chain execution |
| `workspace_id` | string | Workspace this chain is operating on |
| `chain_status` | string | `pending` / `in_progress` / `completed` / `failed` / `partial` |
| `hops` | string[] | Ordered list of hops in the chain |
| `current_hop` | string\|null | Hop currently being executed |
| `hop_statuses` | object | Map of hop name → individual status |
| `resume_from` | string\|null | If set, `ChainRunner` will skip hops before this hop name |
| `options.stop_on_failure` | bool | If true, chain halts on first failed hop (default: true) |

### Chain Status Values

| Value | Meaning |
|---|---|
| `pending` | Not yet started |
| `in_progress` | Currently running |
| `completed` | All hops finished successfully |
| `failed` | One or more hops failed and `stop_on_failure` was true |
| `partial` | Some hops completed, some failed, and `stop_on_failure` was false |

---

## JSON-ND Event Log Format

Each hop container emits one JSON object per line (JSON-ND / NDJSON) to stdout. The orchestrator captures this and writes it to `{workspace}/hop-{name}.jsonnd`.

### Event Envelope

Every event follows this envelope:

```json
{
  "type": "event.name",
  "hop": "hop-9-to-10",
  "timestamp": "2025-01-15T10:30:05Z",
  "data": { }
}
```

### Event Type Catalog

| Event Type | When Emitted | `data` fields |
|---|---|---|
| `hop.started` | First line from container | `{"workspace": "/workspace"}` |
| `rector.started` | Before Rector subprocess | `{}` |
| `rector.completed` | After Rector exits (success) | `{"changed_files": N, "errors": [...]}` |
| `php_lint.started` | Before `php -l` sweep | `{}` |
| `php_lint.completed` | After lint sweep success | `{"files_checked": N}` |
| `phpstan.started` | Before PHPStan subprocess | `{}` |
| `phpstan.completed` | After PHPStan exits (success) | `{"total_errors": N, "error_delta": N}` |
| `composer.started` | Before Composer subprocess | `{}` |
| `composer.completed` | After Composer exits (success) | `{"packages_updated": N}` |
| `hop.warning` | Non-fatal warning (e.g., beta PHP) | `{"message": "..."}` |
| `hop.failed` | On any fatal error | `{"stage": "rector", "exit_code": 1, "message": "..."}` |
| `hop.completed` | Last line from successful container | `{"status": "success"}` |

### Reading the Log

```bash
# Pretty-print all events with timestamps
cat workspace/{id}/hop-9-to-10.jsonnd | jq -r '"\(.timestamp) [\(.type)] \(.data | tostring)"'

# Find just the failure event
jq 'select(.type == "hop.failed")' workspace/{id}/hop-9-to-10.jsonnd

# Extract Rector error list (if Rector exited non-zero and partial output was emitted)
jq 'select(.type == "rector.completed") | .data.errors[]?' workspace/{id}/hop-9-to-10.jsonnd

# Count changed files across all hops
grep '"type":"rector.completed"' workspace/{id}/*.jsonnd \
    | jq -s '[.[].data.changed_files] | add'
```

---

## File Layout Reference

```
workspace/{workspace-id}/
├── checkpoint.json                 # ChainCheckpoint (top-level resume state)
├── checkpoint-hop-8-to-9.json      # TransformCheckpoint per hop
├── checkpoint-hop-9-to-10.json
├── hop-8-to-9.jsonnd               # JSON-ND event log per hop
├── hop-9-to-10.jsonnd
├── phpstan-baseline-before.json    # PHPStan error counts before hop
├── phpstan-baseline-after.json     # PHPStan error counts after hop
├── report.html                     # Final HTML upgrade report (when complete)
└── src/                            # The upgraded application source
```

---

## Manual Checkpoint Recovery

### Force a Specific Hop to Re-run

```bash
# Edit chain checkpoint to set hop status back to pending
jq '.hop_statuses["hop-9-to-10"] = "pending" | .current_hop = "hop-9-to-10"' \
    workspace/{id}/checkpoint.json > /tmp/cp.json && mv /tmp/cp.json workspace/{id}/checkpoint.json

# Resume
upgrader run --resume --workspace-id={id}
```

### Skip a Failing Hop (Mark as Completed)

Use only if you have manually applied the hop's changes and want the chain to continue:

```bash
jq '.hop_statuses["hop-9-to-10"] = "completed"' \
    workspace/{id}/checkpoint.json > /tmp/cp.json && mv /tmp/cp.json workspace/{id}/checkpoint.json

upgrader run --resume --workspace-id={id}
```

### Reset the Entire Chain

```bash
# WARNING: This will re-run all hops from the beginning
# Make sure you have a git snapshot of the workspace first
git -C workspace/{id}/src stash

jq '.chain_status = "pending" | .current_hop = null | .hop_statuses = (.hops | map({key: ., value: "pending"}) | from_entries)' \
    workspace/{id}/checkpoint.json > /tmp/cp.json && mv /tmp/cp.json workspace/{id}/checkpoint.json

upgrader run --workspace-id={id}
```

---
name: upgrade-pipeline-debug
description: 'Debug a failed Laravel Enterprise Upgrader pipeline run. Use when: an upgrade run stopped mid-hop, the orchestrator exited with an error, a hop container returned non-zero, JSON-ND log shows a failed event, checkpoint resume is not working, or you need to re-run a single hop in isolation. Covers checkpoint inspection, JSON-ND log analysis, isolated hop re-run, and common failure pattern diagnosis.'
argument-hint: 'Failure description or workspace path (e.g. "hop-9-to-10 failed with PHPStan errors" or "/tmp/workspace-abc")'
---

# Upgrade Pipeline Debug Workflow

## When to Use

- `upgrader run` exited with a non-zero code
- A hop container emitted a `hop.failed` JSON-ND event
- The orchestrator crashed mid-chain (`ChainRunner` exception)
- Checkpoint resume (`--resume`) is failing or skipping incorrectly
- A Rector transformation produced syntactically invalid PHP
- PHPStan reports new errors after a hop (false positives vs real regressions)
- You need to re-run just one hop without re-running the full chain

---

## Procedure

### Step 1 — Locate the Checkpoint File

The `TransformCheckpoint` file is written to the workspace by `CheckpointManager`:

```bash
# Default location inside the upgrade workspace
ls -la /tmp/upgrade-workspace-*/checkpoint.json

# Or find by the run ID shown in the error output
find /tmp -name "checkpoint.json" -newer /tmp/marker 2>/dev/null

# If using the CLI with a named workspace
cat workspace/{workspace-id}/checkpoint.json
```

See [references/checkpoint-structure.md](./references/checkpoint-structure.md) for the full JSON schema.

---

### Step 2 — Read the JSON-ND Event Log

The event log (stdout from each hop container) is captured by the orchestrator to `{workspace}/hop-{name}.jsonnd`:

```bash
# Show all events for the failed hop
cat workspace/{workspace-id}/hop-9-to-10.jsonnd | while read line; do
    echo "$line" | jq '{type: .type, stage: .data.stage, exit: .data.exit_code}'
done

# Find the failure event
grep '"type":"hop.failed"' workspace/{workspace-id}/*.jsonnd

# Show last 20 events for context
tail -20 workspace/{workspace-id}/hop-9-to-10.jsonnd | jq .

# Find all non-success events across all hops
grep -h '"type":".*failed\|.*error"' workspace/{workspace-id}/*.jsonnd | jq .
```

Key event types to look for:

| Event | Meaning |
|---|---|
| `hop.started` | Container started successfully |
| `rector.started` / `rector.completed` | Rector ran |
| `phpstan.started` / `phpstan.completed` | PHPStan ran |
| `hop.failed` | Fatal failure — check `data.stage` and `data.exit_code` |
| `hop.completed` | Success |

---

### Step 3 — Identify the Failure Stage

From `hop.failed.data.stage`:

| Stage | Likely Cause | Go To |
|---|---|---|
| `rector` | Parse error in source PHP, or Rector rule exception | [Common Failures #1–3](./references/common-failures.md) |
| `phpstan` | New type errors introduced by Rector, or level too strict | [Common Failures #4–5](./references/common-failures.md) |
| `php_lint` | Rector produced syntactically invalid PHP | [Common Failures #6](./references/common-failures.md) |
| `composer` | Dependency resolution failed | [Common Failures #7–8](./references/common-failures.md) |
| `verification` | Post-hop `php artisan` command failed | [Common Failures #9](./references/common-failures.md) |

---

### Step 4 — Re-run the Failed Hop in Isolation

Always reproduce the failure in isolation before attempting a fix:

```bash
# Run just the failed hop container against the current workspace state
docker run --rm \
    --network=none \
    -v /path/to/workspace:/workspace \
    upgrader/hop-9-to-10:latest

# Capture and pretty-print the output
docker run --rm \
    --network=none \
    -v /path/to/workspace:/workspace \
    upgrader/hop-9-to-10:latest | jq -r 'select(.type) | "\(.type): \(.data // "")"'

# Run with a debug shell to inspect the container environment
docker run --rm -it \
    --network=none \
    -v /path/to/workspace:/workspace \
    --entrypoint /bin/sh \
    upgrader/hop-9-to-10:latest
```

Inside the debug shell, run Rector manually to see the full error:

```bash
# Run Rector with verbose output
/upgrader/vendor/bin/rector process \
    --config=/upgrader/rector-configs/rector.hop-9-to-10.php \
    --dry-run \
    --debug \
    /workspace/app/Http/Controllers/SomeController.php
```

---

### Step 5 — Apply Fix and Resume

**If the fix is in a Rector rule:**

1. Fix the rule in `src-container/Rector/Rules/`
2. Rebuild the Docker image: `docker build -t upgrader/hop-9-to-10:local ./docker/hop-9-to-10/`
3. Re-run the hop in isolation to verify the fix
4. Resume from checkpoint:

```bash
upgrader run --resume --workspace-id={id} --from-hop=hop-9-to-10
```

**If the fix is in the source code (manual change):**

1. Apply the manual code change in the workspace
2. Update the checkpoint to mark the hop as retryable:
   - See [references/checkpoint-structure.md](./references/checkpoint-structure.md) for how to edit `hop_statuses`
3. Resume: `upgrader run --resume --workspace-id={id}`

**If the failure is a false positive (PHPStan):**

1. Add a PHPStan baseline or suppress annotation in config
2. See [Common Failures #5](./references/common-failures.md) for the exact approach
3. Rebuild container and resume

---

### Step 6 — Verify Full Chain After Fix

After fixing the isolated hop, run the full chain from that hop forward:

```bash
upgrader run \
    --resume \
    --workspace-id={id} \
    --from-hop={failed-hop} \
    --dry-run    # Remove --dry-run once you're confident
```

---

## Quick Diagnostic Commands

```bash
# Show checkpoint status at a glance
cat workspace/{id}/checkpoint.json | jq '{chain: .chain_id, current_hop: .current_hop, hop_statuses: .hop_statuses}'

# Find Rector parse errors in the log
grep '"type":"rector.completed"' workspace/{id}/*.jsonnd \
    | jq 'select(.data.errors != null) | .data.errors[]'

# Find PHPStan errors introduced by the hop (compare before/after)
diff <(cat workspace/{id}/phpstan-before.json | jq -r '.files|keys[]') \
     <(cat workspace/{id}/phpstan-after.json  | jq -r '.files|keys[]')

# Check if workspace is in a clean state (no half-applied changes)
git -C workspace/{id}/src diff --stat
```

---

## Quality Checklist (after fixing)

- [ ] Hop runs in isolation without errors
- [ ] JSON-ND output contains only `hop.completed` at the end (no `hop.failed`)
- [ ] PHPStan report shows no new errors vs pre-hop baseline
- [ ] `php -l` passes on all modified files
- [ ] Checkpoint file updated correctly for clean resume
- [ ] Full chain resumes and completes after the fixed hop

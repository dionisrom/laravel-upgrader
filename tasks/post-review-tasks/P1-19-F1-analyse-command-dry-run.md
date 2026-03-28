# P1-19-F1: AnalyseCommand Does Not Implement Dry-Run

**Severity:** Critical  
**Source:** P1-19 review  
**Requirement:** PRD §10.1, Acceptance Criteria "runs dry-run mode (no transforms applied)"

## Problem

`AnalyseCommand` constructs the same `UpgradeOrchestrator` with the same `DockerRunner` and calls `$orchestrator->run()` identically to `RunCommand`. No dry-run flag or transform suppression exists — it will run Docker hops and modify code.

## Fix

1. Add a `dryRun` parameter to `UpgradeOrchestrator::run()` (or a separate `analyse()` method) that skips Docker execution and only emits detection/planning events.
2. Alternatively, pass a no-op `DockerRunner` or a `DryRunDockerRunner` that emits events without executing containers.
3. Add `AnalyseCommandTest` that verifies no Docker hops execute.

## Acceptance

- `upgrader analyse` must not invoke any Docker containers.
- `upgrader analyse` must emit hop-planning and detection events only.
- Test proves no Docker execution occurs.

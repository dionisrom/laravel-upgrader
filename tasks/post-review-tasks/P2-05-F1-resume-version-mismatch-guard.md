# P2-05-F1: Resume Version Mismatch Guard

**Severity:** HIGH  
**Source:** P2-05 post-review  
**Requirement:** TRD-P2MULTI-002, checkpoint idempotency  

## Problem

`ChainRunner::run()` does not validate that a restored checkpoint's `sourceVersion`/`targetVersion` match the requested `$fromVersion`/`$toVersion`. A user can accidentally resume a stale checkpoint from a different version range, corrupting the chain state.

## Fix

After restoring the checkpoint in the resume branch, compare `$checkpoint->sourceVersion` against `$fromVersion` and `$checkpoint->targetVersion` against `$toVersion`. If they differ, throw `OrchestratorException` with a clear message telling the user the checkpoint doesn't match the requested plan.

## Test

Add a test in `ChainRunnerTest` that pre-populates a checkpoint for 8→11, then calls `run()` with from=8, to=13, resume=true, and asserts `OrchestratorException` is thrown with a message mentioning the version mismatch.

## Files

- `src/Orchestrator/ChainRunner.php` — add guard after `readCheckpoint()`
- `tests/Unit/Orchestrator/ChainRunnerTest.php` — add version mismatch test

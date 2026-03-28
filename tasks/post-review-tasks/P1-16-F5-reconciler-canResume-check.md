# P1-16-F5: WorkspaceReconciler Ignores canResume Flag

**Severity:** MEDIUM  
**Task:** P1-16 — State & Checkpoint System  
**Requirement:** TRD-STATE-002, Acceptance criteria #11  

## Problem

`reconcile()` does not check `$checkpoint->canResume`. A checkpoint marked `canResume: false` should be rejected, but the reconciler processes it normally.

## Fix

At the start of `reconcile()`, after the null check, add a check: if `$checkpoint->canResume === false`, throw a descriptive exception (or a new `CheckpointNotResumableException`). Add a test covering this case.

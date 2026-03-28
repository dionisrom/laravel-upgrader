# P1-16-F1: isCompleted() Semantic Mismatch

**Severity:** HIGH  
**Task:** P1-16 — State & Checkpoint System  
**Requirement:** ST-04, TRD-STATE-001  

## Problem

`TransformCheckpoint::isCompleted()` returns `true` when a checkpoint exists with `canResume: true`. A checkpoint with pending rules means the hop is *in progress*, not *completed*. The orchestrator would skip the hop instead of resuming it.

`markCompleted()` deletes the checkpoint, so after true completion `isCompleted()` returns `false`.

## Fix

`isCompleted()` should return `true` only when a matching checkpoint exists with **no pending rules** (i.e., `pendingRules` is empty). A checkpoint with pending rules means "resumable, not completed."

Update the test `testIsCompletedReturnsTrueForMatchingHop` to write a checkpoint with empty `pendingRules`, and add a test for a checkpoint with pending rules returning `false`.

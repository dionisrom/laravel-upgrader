# P2-05-F4: Resume Mismatch Test Coverage

**Severity:** MEDIUM  
**Source:** P2-05 post-review  
**Requirement:** Task AC "--resume restarts from last incomplete hop"  

## Problem

No test verifies behavior when a checkpoint's version range doesn't match the requested plan. This is the regression coverage for F1.

## Fix

Covered by the test added in F1. This task is a tracking placeholder — mark complete when F1's test is written and passing.

## Files

- `tests/Unit/Orchestrator/ChainRunnerTest.php`

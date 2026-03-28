# P1-08-F1: Add test for ConcurrentUpgradeException

**Severity:** HIGH  
**Source:** P1-08 post-review  
**Requirement:** TRD-REPO-003, Acceptance Criteria: "Advisory flock(LOCK_EX | LOCK_NB) prevents concurrent runs" and "ConcurrentUpgradeException thrown with clear message"

## Problem

No test exercises the advisory lock contention path. The `ConcurrentUpgradeException` is never asserted in any test.

## Fix

Add a test that:
1. Creates a workspace (acquires lock)
2. Attempts to create a second workspace for the same repo path without releasing the first lock
3. Asserts `ConcurrentUpgradeException` is thrown with the expected message

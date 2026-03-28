# P1-16-F2: No can_resume: false Code Path

**Severity:** MEDIUM  
**Task:** P1-16 — State & Checkpoint System  
**Requirement:** Acceptance criteria #11  

## Problem

`write()` always sets `canResume: true`. No API exists to write a checkpoint with `canResume: false` for inconsistent states (e.g., mid-batch failure).

## Fix

Add an optional `bool $canResume = true` parameter to `write()`. Add a test that writes `canResume: false` and verifies it round-trips correctly.

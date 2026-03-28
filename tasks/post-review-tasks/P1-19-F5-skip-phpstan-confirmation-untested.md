# P1-19-F5: --skip-phpstan Confirmation Flow Untested

**Severity:** Medium  
**Source:** P1-19 review  
**Requirement:** TRD-CLI-003 — --skip-phpstan MUST require typing confirmation

## Problem

The interactive confirmation path in RunCommand (lines 84-95) is never tested. If the confirmation logic regresses, no test catches it.

## Fix

1. Add a test in `RunCommandTest` that verifies `--skip-phpstan` without `--no-interaction` requires the exact confirmation string.
2. Add a test verifying that `--skip-phpstan` with `--no-interaction` skips the prompt.

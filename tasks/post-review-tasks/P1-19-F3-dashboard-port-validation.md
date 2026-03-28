# P1-19-F3: DashboardCommand Port Not Validated

**Severity:** Medium  
**Source:** P1-19 review  
**Requirement:** TRD-CLI-001 (all inputs validated), exit code 2 for config error

## Problem

`is_numeric()` accepts floats, negative numbers, hex. No range check. Invalid port silently falls back to 8765.

## Fix

1. Validate port is an integer in range 1–65535.
2. Return `Command::INVALID` (exit 2) with a clear error on invalid port.
3. Add `DashboardCommandTest` covering invalid port cases.

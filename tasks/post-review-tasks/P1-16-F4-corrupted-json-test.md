# P1-16-F4: Missing Corrupted Checkpoint JSON Test

**Severity:** MEDIUM  
**Task:** P1-16 — State & Checkpoint System  
**Requirement:** ST-04 robustness  

## Problem

`read()` uses `JSON_THROW_ON_ERROR` but no test verifies that a malformed checkpoint file throws `\JsonException`.

## Fix

Add a test that writes garbage to the checkpoint file path and asserts `\JsonException` is thrown on `read()`.

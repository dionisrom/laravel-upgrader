# P1-08-F4: Make event emitter injectable for testability

**Severity:** MEDIUM  
**Source:** P1-08 post-review  
**Requirement:** TRD-RECTOR-007 — JSON-ND events are a first-class contract

## Problem

`emitEvent()` uses bare `echo`, making JSON-ND event output untestable without `ob_start()`. Most tests ignore event output entirely.

## Fix

Accept an optional `callable(array): void` event emitter in the constructor (defaulting to `echo json_encode(...)`). Update tests to inject a capturing emitter and assert event contents for key scenarios (file_changed, pipeline_error).

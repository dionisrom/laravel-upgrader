# P1-11-F1: EventEmitter `ts` field uses milliseconds instead of seconds

**Severity:** HIGH  
**Source:** P1-11 Event Streaming review  
**Requirement:** TRD §13.1 — `ts: number; // Unix timestamp (seconds, float)`

## Problem

`EventEmitter::emit()` sets `ts` as `(int) (microtime(true) * 1000)` which produces milliseconds as an integer. The TRD specifies seconds as a float.

## Fix

Change `EventEmitter.php` line 32 from:
```php
'ts' => (int) (microtime(true) * 1000),
```
to:
```php
'ts' => microtime(true),
```

## Test Update

`EventEmitterTest::testEmitIncludesAllBaseFields` should assert `ts` is a float in the seconds range (e.g., `> 1_000_000_000 && < 10_000_000_000`).

## Files

- `src-container/EventEmitter.php`
- `tests/Unit/Orchestrator/Events/EventEmitterTest.php`

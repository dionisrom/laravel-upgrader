# P1-14-F4: MiddlewareMigrator result discards alias→class mapping

**Parent Task:** P1-14  
**Severity:** Medium  
**Requirement:** LM-05  

## Problem

Line ~76 passes `array_keys($collector->routeMiddleware)` to `MiddlewareMigrationResult::success()`, discarding the class values. The result stores only alias names, making it impossible to audit which class each alias maps to.

## Fix

Either change the result DTO to accept the full alias→class map, or store both in a structured format.

## Files

- `src-container/Lumen/MiddlewareMigrator.php`
- `src-container/Lumen/MiddlewareMigrationResult.php`

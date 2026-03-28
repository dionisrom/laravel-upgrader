# P2-05-F2: Partial Report on Chain Abort

**Severity:** HIGH  
**Source:** P2-05 post-review  
**Requirement:** PRD §5 unified report, Task AC "abort with clear error context"  

## Problem

When a hop fails, `ChainRunner::run()` throws immediately without writing any report artifacts for the hops that completed successfully before the failure. This loses diagnostic data.

## Fix

Wrap the hop loop in a try/catch. In the catch block, if `$checkpoint->completedHops` is non-empty, call `reportWriter()->write()` to produce a partial report before re-throwing the exception. Store the partial report paths in the exception or return them via a structured exception.

## Test

Add a test in `ChainRunnerTest` where hop 1 succeeds and hop 2 fails. Assert that the chain-report.json and chain-report.html files exist in the output directory despite the exception.

## Files

- `src/Orchestrator/ChainRunner.php` — wrap loop, write partial report on abort
- `tests/Unit/Orchestrator/ChainRunnerTest.php` — add partial report on failure test

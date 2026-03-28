# P2-05-F3: Incremental Report Building (TRD-P2MULTI-003)

**Severity:** MEDIUM  
**Source:** P2-05 post-review  
**Requirement:** TRD-P2MULTI-003  

## Problem

TRD-P2MULTI-003 requires: "The unified report MUST be built incrementally. Each hop's ReportBuilder MUST append to a shared `report-context.json` in `/output/`." The current implementation builds the full report in one pass at the end of the chain.

## Fix

After each successful hop (right after checkpoint persistence), write/append a `report-context.json` file in the chain output directory containing the accumulated hop results. The final report generation can still produce the HTML, but the JSON context must be incremental.

This also naturally resolves F2 since the partial data is already on disk if the chain aborts.

## Test

Add a test that runs a 2-hop chain and asserts that `report-context.json` exists in the chain output directory after the first hop completes (before the second hop runs). This can be verified by making the second hop fail and checking the file exists.

## Files

- `src/Orchestrator/ChainRunner.php` — write report-context.json after each hop
- `tests/Unit/Orchestrator/ChainRunnerTest.php` — add incremental report context test

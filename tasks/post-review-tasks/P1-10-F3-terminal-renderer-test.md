# P1-10-F3: Add TerminalRendererTest

**Severity:** HIGH  
**Finding:** TerminalRenderer has zero test coverage.  
**Violated:** TRD-EVENTS-001 fan-out to TerminalRenderer

## Required Tests

1. pipeline_start renders repo name
2. pipeline_complete with passed=true renders success
3. pipeline_complete with passed=false renders failure
4. stage_start/stage_complete render stage names
5. stage_error renders error message
6. warning event renders warning
7. stderr event renders each line
8. hop_skipped event renders hop key
9. Unknown event renders JSON fallback

## Implementation Notes

- Use Symfony `BufferedOutput` to capture rendered output
- Test file: `tests/Unit/Orchestrator/TerminalRendererTest.php`

# P1-18-F2: Per-file confidence scores not rendered in reports

**Severity:** HIGH  
**Requirement IDs:** TRD-REPORT-003, RP-03  
**Files:** `src-container/Report/ConfidenceScorer.php`, `src-container/Report/Formatters/HtmlFormatter.php`, `tests/Unit/Report/ConfidenceScorerTest.php`

## Problem

`ConfidenceScorer::fileScore()` exists but is never called by any formatter. Per-file confidence (High/Medium/Low) is never shown in the HTML or JSON reports. The method also uses a flat 40/100 binary instead of a graduated score.

## Required Fix

1. Integrate `fileScore()` into `HtmlFormatter::buildDiffs()` to show per-file confidence badge
2. Include per-file scores in `JsonFormatter` output
3. Add tests for `fileScore()` covering files with manual review, syntax errors, and clean files

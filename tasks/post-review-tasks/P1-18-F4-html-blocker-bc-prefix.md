# P1-18-F4: HtmlFormatter missing BC- prefix in blocker classification

**Severity:** MEDIUM  
**Requirement IDs:** Consistency across formatters  
**Files:** `src-container/Report/Formatters/HtmlFormatter.php`, `tests/Unit/Report/HtmlFormatterTest.php`

## Problem

`HtmlFormatter::buildManualReview()` classifies blockers by checking for `BLOCKER` in id or `incompatible` in reason, but misses `str_starts_with($item['id'], 'BC-')` which `JsonFormatter` and `MarkdownFormatter` both check. BC-prefixed items render as REVIEW instead of BLOCKER in the HTML report.

## Required Fix

1. Add `str_starts_with($item['id'], 'BC-')` to `$isBlocker` condition in `buildManualReview()`
2. Add test with a `BC-001` item asserting it gets the blocker class

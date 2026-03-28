# P1-18-F3: MarkdownFormatter missing code snippets in manual review entries

**Severity:** MEDIUM  
**Requirement IDs:** TRD-REPORT-004  
**Files:** `src-container/Report/ReportData.php`, `src-container/Report/Formatters/MarkdownFormatter.php`, `tests/Unit/Report/MarkdownFormatterTest.php`

## Problem

Each manual review entry must include a "code snippet showing problematic pattern" per TRD-REPORT-004. The `manualReviewItems` array type doesn't include a `snippet` field, and `MarkdownFormatter::renderItem()` doesn't output one.

## Required Fix

1. Add optional `snippet` field to `manualReviewItems` type in `ReportData`
2. Render snippet as fenced code block in `MarkdownFormatter::renderItem()`
3. Add test verifying snippets appear in output

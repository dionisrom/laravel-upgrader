# P1-18-F5: MarkdownFormatter should receive ConfidenceScorer via DI

**Severity:** MEDIUM  
**Requirement IDs:** Architectural consistency  
**Files:** `src-container/Report/Formatters/MarkdownFormatter.php`, `src-container/Report/ReportBuilder.php`

## Problem

`MarkdownFormatter` instantiates `new ConfidenceScorer()` internally while `HtmlFormatter` and `JsonFormatter` receive it via constructor injection.

## Required Fix

1. Change `MarkdownFormatter` constructor to accept `ConfidenceScorer` as parameter
2. Update `ReportBuilder` to pass the shared `$this->scorer` instance

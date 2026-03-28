# P2-08-F4: Fix PdfExporter Chrome file:// URI on Windows

**Severity:** MEDIUM  
**Source:** P2-08 review finding F4  
**Requirement:** DV-06 — PDF export must work reliably

## Problem

`PdfExporter::exportWithChrome()` constructs the URI as `"file://{$htmlPath}"`. On Windows, `$htmlPath` = `C:\Users\foo\report.html`, producing `file://C:\Users\foo\report.html`. Chrome requires `file:///C:/Users/foo/report.html`.

## Fix

Normalize the path: replace backslashes with forward slashes, prepend `file:///` (three slashes for absolute local path).

## Acceptance Criteria

- [ ] `exportWithChrome()` produces a valid `file:///` URI on both Windows and Unix
- [ ] Unit test asserts the constructed command array contains a valid file URI (mock or inspect)
- [ ] Existing wkhtmltopdf path (which accepts filesystem paths directly) is not affected

## Files to Modify

- `src/Report/PdfExporter.php`
- `tests/Unit/Report/PdfExporterTest.php`

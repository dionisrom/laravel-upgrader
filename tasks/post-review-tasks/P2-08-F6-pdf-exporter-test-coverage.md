# P2-08-F6: Improve PdfExporterTest Coverage

**Severity:** LOW  
**Source:** P2-08 review finding F6  
**Requirement:** DV-06 — Test quality for PDF export

## Problem

Most `PdfExporterTest` methods are trivial capability checks (`assertIsBool`, `assertInstanceOf`). The Chrome fallback path, argument construction, and file URI formatting are untested.

## Acceptance Criteria

- [ ] Test verifies the constructed command array matches expected arguments (via a Process spy or subclass)
- [ ] Test verifies Chrome is used as fallback when wkhtmltopdf is absent
- [ ] Test verifies the file URI is correctly formatted (covers F4 fix)
- [ ] Test for RuntimeException when the tool process exits non-zero

## Files to Modify

- `tests/Unit/Report/PdfExporterTest.php`
- Possibly `src/Report/PdfExporter.php` (make `commandExists` / `runProcess` injectable for testability)

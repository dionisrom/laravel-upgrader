# P2-08-F1: Add Unit Tests for Sub-Renderers

**Severity:** HIGH  
**Source:** P2-08 review finding F1  
**Requirement:** Task AC — all renderers must be testable and tested  

## Problem

`HopSectionRenderer`, `FileTreeRenderer`, and `AnnotationRenderer` have no dedicated unit tests. The generator test uses an in-memory Twig template that bypasses the real renderer output structure.

## Acceptance Criteria

- [ ] `HopSectionRendererTest` covers: empty diffs, single file diff, manual-review badge, sign-off checkbox presence, HTML escaping of file paths and diff content, multiple rules rendered as annotations
- [ ] `FileTreeRendererTest` covers: empty file list, flat files, nested directories, special characters in file names, duplicate paths, change-type icons, collapsible `<details>` structure
- [ ] `AnnotationRendererTest` covers: empty rules, single rule, multiple rules, FQCN shortening, HTML escaping of rule names
- [ ] All tests are requirement-driven, not mirroring current implementation

## Files to Create

- `tests/Unit/Report/Renderer/HopSectionRendererTest.php`
- `tests/Unit/Report/Renderer/FileTreeRendererTest.php`
- `tests/Unit/Report/Renderer/AnnotationRendererTest.php`

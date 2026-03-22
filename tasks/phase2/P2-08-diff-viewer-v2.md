# P2-08: HTML Diff Viewer v2

**Phase:** 2  
**Priority:** Should Have  
**Estimated Effort:** 8-10 days  
**Dependencies:** P1-18 (Report Generator v1), P2-05 (Multi-Hop Orchestration)  
**Blocks:** P2-09 (Phase 2 Hardening)  

---

## Agent Persona

**Role:** Report & Documentation Engineer  
**Agent File:** `agents/report-documentation-engineer.agent.md`  
**Domain Knowledge Required:**
- HTML/CSS/JS for rich interactive diff viewer (single-file SPA, no build tools)
- Multi-hop report aggregation — per-hop tabs/sections
- File tree navigation with search and filter
- PDF generation from HTML (wkhtmltopdf or Chromium headless)
- Annotation and sign-off workflow concepts

---

## Objective

Extend the Phase 1 HTML diff report into a full interactive diff viewer with file tree navigation, per-hop sections, filtering by change type, inline annotations, team sign-off workflow, and PDF export.

---

## Context from PRD & TRD

### Diff Viewer v2 Features (PRD §8)

1. **File tree sidebar**: Collapsible tree showing all changed files with change-type icons
2. **Hop navigation**: Tabs or accordion for each hop in a multi-hop chain
3. **Filters**: Filter by change type (automated, manual-review, warning), by file extension, by directory
4. **Annotations**: Inline comments explaining why each change was made (from Rector rule metadata)
5. **Sign-off workflow**: Checkboxes per file or per-hop for team review tracking
6. **PDF export**: Generate printable PDF from the HTML report

### Report Data Structure

Extends the Phase 1 `UpgradeReport` with:
- Per-hop sections from `ChainEventAggregator` (P2-05)
- Rule metadata annotations (rule name, description, confidence level)
- File categorization (auto-fixed, needs-review, manual-only)

### Single-File HTML Constraint

The v2 viewer must remain a **single self-contained HTML file** (inline CSS/JS). No external dependencies, no build tools. This ensures the report can be opened in any browser, emailed, or stored as an artifact.

---

## Files to Create

| File | Location | Purpose |
|---|---|---|
| `DiffViewerV2Generator.php` | `src/Report/` | Generates v2 HTML report |
| `HopSectionRenderer.php` | `src/Report/Renderer/` | Renders per-hop sections |
| `FileTreeRenderer.php` | `src/Report/Renderer/` | File tree sidebar HTML |
| `AnnotationRenderer.php` | `src/Report/Renderer/` | Inline annotation rendering |
| `PdfExporter.php` | `src/Report/` | HTML→PDF conversion |
| `diff-viewer-v2.html.twig` | `templates/report/` | Main report template |
| `diff-viewer-v2.css` | `templates/report/assets/` | Inlined CSS |
| `diff-viewer-v2.js` | `templates/report/assets/` | Inlined JS (tree, filter, signoff) |
| `DiffViewerV2GeneratorTest.php` | `tests/Unit/Report/` | Generator tests |
| `PdfExporterTest.php` | `tests/Unit/Report/` | PDF export tests |

---

## Acceptance Criteria

- [ ] File tree sidebar shows all changed files with collapsible directories
- [ ] Change-type icons (green=auto, yellow=review, red=manual) in file tree
- [ ] Hop tabs/sections for multi-hop chains with per-hop summaries
- [ ] Filter controls: by change type, file extension, directory path
- [ ] Inline annotations show Rector rule name and description per change
- [ ] Sign-off checkboxes persist state in localStorage
- [ ] PDF export produces readable document (via wkhtmltopdf or headless Chrome)
- [ ] Report remains a single self-contained HTML file
- [ ] Works in Chrome, Firefox, Safari, Edge
- [ ] Report loads within 2 seconds for 500+ file upgrades

---

## Implementation Notes

- Build on Phase 1's report template — extend, don't rewrite
- JS should be vanilla (no React/Vue) to keep the single-file constraint
- File tree: use `<details>/<summary>` for native collapsible behavior
- Sign-off state: `localStorage` keyed by report ID (chain UUID)
- PDF: prefer wkhtmltopdf (lighter) but support headless Chrome as fallback
- Consider: syntax highlighting for diffs (highlight.js can be inlined, ~40KB minified)

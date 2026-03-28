# P2-08-F2: Add Confidence-Level and Hop-Number Filters

**Severity:** HIGH  
**Source:** P2-08 review finding F2  
**Requirement:** PRD DV-02 (Must Have): "Filter by: status (auto/manual/error), confidence level, hop number"

## Problem

The filter bar only implements change-type, file extension, and directory path. There is no confidence-level filter and no hop-number filter.

## Acceptance Criteria

- [ ] File diff blocks carry a `data-confidence` attribute (e.g., high/medium/low) sourced from Rector rule metadata
- [ ] Filter bar includes a confidence-level dropdown/button group
- [ ] Filter bar includes a hop-number filter (when "All Hops" view is active)
- [ ] JS `applyFilters()` respects both new filter dimensions
- [ ] Sidebar file entries also filtered by these dimensions
- [ ] Unit tests cover the new filter logic

## Notes

This depends on rule metadata carrying a confidence level, which may require upstream data model changes in `HopReport` or the `hopFileDiffs` structure.

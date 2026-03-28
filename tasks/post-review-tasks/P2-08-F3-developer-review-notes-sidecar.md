# P2-08-F3: Implement Developer Review Notes with JSON Sidecar

**Severity:** MEDIUM  
**Source:** P2-08 review finding F3  
**Requirement:** PRD DV-04 (Should Have): "Per-diff annotation: developer adds review notes (saved to JSON sidecar)"

## Problem

`AnnotationRenderer` only shows Rector rule badges. There is no UI for adding developer review notes, and no JSON sidecar export/import.

## Acceptance Criteria

- [ ] Each file diff block has a "Add note" button that reveals a textarea
- [ ] Notes are saved to localStorage initially and can be exported to a JSON sidecar file
- [ ] Existing sidecar can be imported to pre-populate review notes
- [ ] Notes are visible alongside Rector rule annotations
- [ ] Unit test covers sidecar JSON structure

## Notes

The JSON sidecar file should be keyed by chain ID and file path. Defer full Blob/download API if impractical in a single-file HTML; localStorage persistence plus a "Download notes" button is acceptable.

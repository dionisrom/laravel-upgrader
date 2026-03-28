# Post-Review: P1-06 — Event names diverge from TRD

**Source:** P1-06 validation review
**Severity:** MEDIUM
**Requirement:** TRD-RECTOR-002
**Status:** Fixed

## Finding

`RectorRunner` emitted `rector.started`, `rector.failed`, `rector.completed` but TRD-RECTOR-002 specifies `rector_error` for the failure event. Dot-delimited names are inconsistent with the underscore convention used elsewhere in JSON-ND events (e.g. `manual_review_required`).

## Fix Applied

Renamed to `rector_started`, `rector_error`, `rector_completed`.

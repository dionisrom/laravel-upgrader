# Post-Review: P1-06 — RectorRunner cwd set to workspace

**Source:** P1-06 validation review
**Severity:** HIGH
**Requirement:** TRD-RECTOR-001
**Status:** Fixed

## Finding

`RectorRunner` passed `cwd: $workspacePath` to the `Process` constructor. The `vendor/bin/rector` binary lives inside the container at `/upgrader/vendor/bin/rector`, not inside the user's workspace. With `cwd` set to the workspace, the process would fail to find the Rector binary.

## Fix Applied

Removed the `cwd` parameter so the process inherits the container's working directory where `vendor/bin/rector` exists.

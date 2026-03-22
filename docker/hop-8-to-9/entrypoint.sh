#!/usr/bin/env bash
# ============================================================
# Entrypoint: hop-8-to-9
# Laravel 8 → Laravel 9 transformation pipeline
#
# Emits JSON-ND (one JSON object per line) to stdout ONLY.
# All diagnostic/debug output goes to stderr.
# Exit codes: 0 = success, 1 = pipeline failure, 2 = config error
# ============================================================
set -euo pipefail

HOP="8-to-9"
WORKSPACE="${UPGRADER_WORKSPACE:-/workspace}"
PHP_BIN="${PHP_BIN:-php}"
RECTOR_BIN="/upgrader/vendor/bin/rector"
RECTOR_CONFIG="/upgrader/rector.php"
SRC="/upgrader/src"

# ─── Helpers ──────────────────────────────────────────────────────────────────

emit() {
    # Always print exactly one JSON line to stdout
    printf '%s\n' "$1"
}

ts() {
    date +%s
}

run_stage() {
    local stage="$1"
    local script="$2"
    shift 2
    local extra_args=("$@")

    local start_ts
    start_ts="$(ts)"
    local start_seconds=$SECONDS

    emit "{\"event\":\"stage_start\",\"stage\":\"${stage}\",\"hop\":\"${HOP}\",\"ts\":${start_ts}}"

    local exit_code=0
    "${PHP_BIN}" "${SRC}/${script}" "${WORKSPACE}" "${extra_args[@]+"${extra_args[@]}"}" >&2 2>&1 || exit_code=$?

    local end_ts
    end_ts="$(ts)"
    local duration_ms=$(( (SECONDS - start_seconds) * 1000 ))

    if [ "$exit_code" -eq 0 ]; then
        emit "{\"event\":\"stage_complete\",\"stage\":\"${stage}\",\"hop\":\"${HOP}\",\"ts\":${end_ts},\"duration_ms\":${duration_ms}}"
    else
        emit "{\"event\":\"stage_error\",\"stage\":\"${stage}\",\"hop\":\"${HOP}\",\"ts\":${end_ts},\"error\":\"Script exited with code ${exit_code}\"}"
        exit 1
    fi
}

# ─── Pre-flight checks ────────────────────────────────────────────────────────

if [ ! -d "${WORKSPACE}" ]; then
    emit "{\"event\":\"config_error\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"UPGRADER_WORKSPACE directory not found: ${WORKSPACE}\"}"
    exit 2
fi

if [ ! -f "${RECTOR_CONFIG}" ]; then
    emit "{\"event\":\"config_error\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"Rector config not found: ${RECTOR_CONFIG}\"}"
    exit 2
fi

if [ ! -f "${RECTOR_BIN}" ]; then
    emit "{\"event\":\"config_error\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"Rector binary not found: ${RECTOR_BIN}\"}"
    exit 2
fi

# ─── Pipeline start ───────────────────────────────────────────────────────────

emit "{\"event\":\"pipeline_start\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"workspace\":\"${WORKSPACE}\"}"

# ─── Stage 1: InventoryScanner ────────────────────────────────────────────────

run_stage "InventoryScanner" "Detector/InventoryScanner.php"

# ─── Stage 2: BreakingChangeRegistry ──────────────────────────────────────────

run_stage "BreakingChangeRegistry" "BreakingChangeRegistry.php" \
    "--breaking-changes=/upgrader/docs/breaking-changes.json"

# ─── Stage 3: RectorRunner ────────────────────────────────────────────────────
# Rector is always a subprocess. Never require'd directly.

RECTOR_STAGE_START="$(ts)"
RECTOR_STAGE_SECONDS=$SECONDS

emit "{\"event\":\"stage_start\",\"stage\":\"RectorRunner\",\"hop\":\"${HOP}\",\"ts\":${RECTOR_STAGE_START}}"

RECTOR_EXIT=0
"${RECTOR_BIN}" process \
    --config="${RECTOR_CONFIG}" \
    --output-format=json \
    --no-interaction \
    "${WORKSPACE}" >&2 || RECTOR_EXIT=$?

RECTOR_DURATION=$(( (SECONDS - RECTOR_STAGE_SECONDS) * 1000 ))

if [ "$RECTOR_EXIT" -eq 0 ]; then
    emit "{\"event\":\"stage_complete\",\"stage\":\"RectorRunner\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"duration_ms\":${RECTOR_DURATION}}"
else
    emit "{\"event\":\"stage_error\",\"stage\":\"RectorRunner\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"Rector exited with code ${RECTOR_EXIT}\"}"
    exit 1
fi

# ─── Stage 4: WorkspaceManager ────────────────────────────────────────────────

run_stage "WorkspaceManager" "Workspace/WorkspaceManager.php"

# ─── Stage 5: TransformCheckpoint ─────────────────────────────────────────────

run_stage "TransformCheckpoint" "Orchestrator/TransformCheckpoint.php"

# ─── Stage 6: DependencyUpgrader ──────────────────────────────────────────────

run_stage "DependencyUpgrader" "Composer/DependencyUpgrader.php" \
    "--compatibility=/upgrader/docs/package-compatibility.json"

# ─── Stage 7: ConfigMigrator ──────────────────────────────────────────────────

run_stage "ConfigMigrator" "Config/ConfigMigrator.php"

# ─── Stage 8: VerificationPipeline ────────────────────────────────────────────

run_stage "VerificationPipeline" "Verification/VerificationPipeline.php"

# ─── Stage 9: ReportBuilder ───────────────────────────────────────────────────

run_stage "ReportBuilder" "Report/ReportBuilder.php" \
    "--assets=/upgrader/assets"

# ─── Pipeline complete ────────────────────────────────────────────────────────

emit "{\"event\":\"pipeline_complete\",\"hop\":\"${HOP}\",\"ts\":$(ts)}"
exit 0

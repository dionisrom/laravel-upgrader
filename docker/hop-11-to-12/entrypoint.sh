#!/usr/bin/env bash
# ============================================================
# Entrypoint: hop-11-to-12
# Laravel 11 → Laravel 12 transformation pipeline
#
# Emits JSON-ND (one JSON object per line) to stdout ONLY.
# All diagnostic/debug output goes to stderr.
# Exit codes: 0 = success, 1 = pipeline failure, 2 = config error
# ============================================================
set -euo pipefail

HOP="11-to-12"
WORKSPACE="${UPGRADER_WORKSPACE:-/workspace}"
REPO_LABEL="${UPGRADER_REPO_LABEL:-}"
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

read_cgroup_memory_value() {
    local path="$1"

    if [ ! -r "$path" ]; then
        printf 'null'
        return
    fi

    local value
    value="$(tr -d '\n' < "$path")"

    if [ "$value" = "max" ] || [ -z "$value" ]; then
        printf 'null'
        return
    fi

    printf '%s' "$value"
}

emit_container_resource_usage() {
    local peak_bytes
    peak_bytes="$(read_cgroup_memory_value /sys/fs/cgroup/memory.peak)"

    if [ "$peak_bytes" = "null" ]; then
        peak_bytes="$(read_cgroup_memory_value /sys/fs/cgroup/memory.max_usage_in_bytes)"
    fi

    local limit_bytes
    limit_bytes="$(read_cgroup_memory_value /sys/fs/cgroup/memory.max)"

    if [ "$limit_bytes" = "null" ]; then
        limit_bytes="$(read_cgroup_memory_value /sys/fs/cgroup/memory.limit_in_bytes)"
    fi

    emit "{\"event\":\"container_resource_usage\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"memory_peak_bytes\":${peak_bytes},\"memory_limit_bytes\":${limit_bytes},\"source\":\"cgroup\"}"
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

# Mark the workspace as a safe directory for git — the bind-mounted volume is
# typically owned by a different UID (host user) than the container user.
git config --global --add safe.directory "${WORKSPACE}" 2>/dev/null || true

if [ ! -f "${RECTOR_CONFIG}" ]; then
    emit "{\"event\":\"config_error\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"Rector config not found: ${RECTOR_CONFIG}\"}"
    exit 2
fi

if [ ! -f "${RECTOR_BIN}" ]; then
    emit "{\"event\":\"config_error\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"Rector binary not found: ${RECTOR_BIN}\"}"
    exit 2
fi

# ─── PHP 8.2 minimum guard ────────────────────────────────────────────────────

PHP_VERSION=$("${PHP_BIN}" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
if [ "$(printf '%s\n' "8.2" "${PHP_VERSION}" | sort -V | head -n1)" != "8.2" ]; then
    emit "{\"event\":\"config_error\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"PHP 8.2+ required for Laravel 12. Found PHP ${PHP_VERSION}\"}"
    exit 2
fi

# ─── Pipeline start ───────────────────────────────────────────────────────────

emit "{\"event\":\"pipeline_start\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"workspace\":\"${WORKSPACE}\",\"repo\":\"${REPO_LABEL}\"}"

# ─── Stage 1: InventoryScanner ────────────────────────────────────────────────

run_stage "InventoryScanner" "Detector/InventoryScanner.php"

# ─── Stage 2: BreakingChangeRegistry ──────────────────────────────────────────

run_stage "BreakingChangeRegistry" "BreakingChangeRegistry.php" \
    "--breaking-changes=/upgrader/docs/breaking-changes.json"

# ─── Stage 3: PackageRuleActivator ───────────────────────────────────────────
# Package rules are now activated inside the Rector config at config load
# time. The Rector config reads composer.lock and merges package-specific rules
# automatically — no separate stage needed.

# ─── Stage 4: RectorRunner ────────────────────────────────────────────────────
# Rector is always a subprocess. Never require'd directly.

RECTOR_STAGE_START="$(ts)"
RECTOR_STAGE_SECONDS=$SECONDS

emit "{\"event\":\"stage_start\",\"stage\":\"RectorRunner\",\"hop\":\"${HOP}\",\"ts\":${RECTOR_STAGE_START}}"

RECTOR_JSON_FILE="$(mktemp)"
RECTOR_EXIT=0
"${RECTOR_BIN}" process \
    --config="${RECTOR_CONFIG}" \
    --output-format=json \
    "${WORKSPACE}" > "${RECTOR_JSON_FILE}" || RECTOR_EXIT=$?

# Forward Rector JSON output to stderr for diagnostics
cat "${RECTOR_JSON_FILE}" >&2

RECTOR_DURATION=$(( (SECONDS - RECTOR_STAGE_SECONDS) * 1000 ))

# Parse error count from Rector JSON — Rector returns non-zero when changes
# are applied, which is normal. Only treat non-zero errors as failure.
RECTOR_ERRORS=$(grep -m1 -o '"errors"[[:space:]]*:[[:space:]]*[0-9]*' "${RECTOR_JSON_FILE}" | grep -o '[0-9]*')
rm -f "${RECTOR_JSON_FILE}"

if [ "${RECTOR_ERRORS:-0}" -eq 0 ]; then
    emit "{\"event\":\"stage_complete\",\"stage\":\"RectorRunner\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"duration_ms\":${RECTOR_DURATION}}"
else
    emit "{\"event\":\"stage_error\",\"stage\":\"RectorRunner\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"Rector reported ${RECTOR_ERRORS:-unknown} errors (exit code ${RECTOR_EXIT})\"}"
    exit 1
fi

# ─── Stage 5: DependencyUpgrader ──────────────────────────────────────────────

if [ "${UPGRADER_SKIP_DEPENDENCY_UPGRADER:-0}" = "1" ]; then
    emit "{\"event\":\"stage_start\",\"stage\":\"DependencyUpgrader\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"mode\":\"pre_staged\"}"
    emit "{\"event\":\"stage_complete\",\"stage\":\"DependencyUpgrader\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"duration_ms\":0,\"mode\":\"pre_staged\"}"
else
    run_stage "DependencyUpgrader" "Composer/DependencyUpgrader.php" \
        "--framework-target=^12.0" \
        "--compatibility=/upgrader/docs/package-compatibility.json"
fi

# ─── Stage 6: ConfigMigrator ──────────────────────────────────────────────────

run_stage "ConfigMigrator" "Config/ConfigMigrator.php"

# ─── Stage 7: VerificationPipeline ────────────────────────────────────────────

run_stage "VerificationPipeline" "Verification/VerificationPipeline.php"

# ─── Stage 8: ReportBuilder ───────────────────────────────────────────────────

run_stage "ReportBuilder" "Report/ReportBuilder.php" \
    "--assets=/upgrader/assets"

# ─── Pipeline complete ────────────────────────────────────────────────────────

emit_container_resource_usage
emit "{\"event\":\"pipeline_complete\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"passed\":true}"
exit 0

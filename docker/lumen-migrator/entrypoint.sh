#!/usr/bin/env bash
# ============================================================
# Entrypoint: lumen-migrator
# Lumen → Laravel migration pipeline
#
# Emits JSON-ND (one JSON object per line) to stdout ONLY.
# All diagnostic/debug output goes to stderr.
# Exit codes: 0 = success, 1 = pipeline failure, 2 = config error
# ============================================================
set -euo pipefail

HOP="lumen-migrator"
WORKSPACE="${UPGRADER_WORKSPACE:-/workspace}"
PHP_BIN="${PHP_BIN:-php}"
SRC="/upgrader/src"

# ─── Helpers ──────────────────────────────────────────────────────────────────

emit() {
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

# ─── Pipeline start ───────────────────────────────────────────────────────────

emit "{\"event\":\"pipeline_start\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"workspace\":\"${WORKSPACE}\"}"

# ─── Stage 1: LumenDetector ───────────────────────────────────────────────────

run_stage "LumenDetector" "Lumen/LumenDetector.php"

# ─── Stage 2: FacadeBootstrapMigrator ─────────────────────────────────────────

run_stage "FacadeBootstrapMigrator" "Lumen/FacadeBootstrapMigrator.php"

# ─── Stage 3: ExceptionHandlerMigrator ────────────────────────────────────────

run_stage "ExceptionHandlerMigrator" "Lumen/ExceptionHandlerMigrator.php"

# ─── Stage 4: ConfigMigrator (Lumen inline configs) ──────────────────────────

run_stage "ConfigMigrator" "Config/ConfigMigrator.php" "--lumen-mode"

# ─── Stage 5: VerificationPipeline ────────────────────────────────────────────

run_stage "VerificationPipeline" "Verification/VerificationPipeline.php"

# ─── Stage 6: ReportBuilder ───────────────────────────────────────────────────

run_stage "ReportBuilder" "Report/ReportBuilder.php" \
    "--assets=/upgrader/assets"

# ─── Pipeline complete ────────────────────────────────────────────────────────

emit_container_resource_usage
emit "{\"event\":\"pipeline_complete\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"passed\":true}"
exit 0

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
REPO_LABEL="${UPGRADER_REPO_LABEL:-}"
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

# ─── Pre-flight checks ────────────────────────────────────────────────────────

if [ ! -d "${WORKSPACE}" ]; then
    emit "{\"event\":\"config_error\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"UPGRADER_WORKSPACE directory not found: ${WORKSPACE}\"}"
    exit 2
fi

# Mark the workspace as a safe directory for git — the bind-mounted volume is
# typically owned by a different UID (host user) than the container user.
git config --global --add safe.directory "${WORKSPACE}" 2>/dev/null || true

# ─── Pipeline start ───────────────────────────────────────────────────────────

emit "{\"event\":\"pipeline_start\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"workspace\":\"${WORKSPACE}\",\"repo\":\"${REPO_LABEL}\"}"

if ! "${PHP_BIN}" "${SRC}/Lumen/LumenMigrationPipeline.php" "${WORKSPACE}" >&2 2>&1; then
    emit "{\"event\":\"stage_error\",\"stage\":\"LumenMigrationPipeline\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"error\":\"Pipeline runner exited with a non-zero status\"}"
    exit 1
fi

# ─── Pipeline complete ────────────────────────────────────────────────────────

emit_container_resource_usage
emit "{\"event\":\"pipeline_complete\",\"hop\":\"${HOP}\",\"ts\":$(ts),\"passed\":true}"
exit 0

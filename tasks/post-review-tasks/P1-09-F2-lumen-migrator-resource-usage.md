# P1-09-F2: Add Container Resource Usage Reporting to Lumen-Migrator

**Severity:** MEDIUM  
**Source:** P1-09 Docker Image review  

## Problem

The lumen-migrator entrypoint.sh does not emit `container_resource_usage` event before `pipeline_complete`, unlike hop-8-to-9 and other hop containers.

## Required

1. Add the `read_cgroup_memory_value` and `emit_container_resource_usage` helper functions to the lumen-migrator entrypoint
2. Call `emit_container_resource_usage` before the final `pipeline_complete` event

## Acceptance

- Lumen-migrator entrypoint emits `container_resource_usage` JSON-ND event

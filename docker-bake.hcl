# ============================================================
# docker-bake.hcl — Multi-platform build configuration
# Usage:
#   docker buildx bake                        # build all targets
#   docker buildx bake hop-8-to-9             # build single target
#   docker buildx bake --push                 # build and push all
# ============================================================

variable "REGISTRY" {
  default = "upgrader"
}

variable "HOP_8_TO_9_VERSION" {
  default = "1.0.0"
}

variable "LUMEN_MIGRATOR_VERSION" {
  default = "1.0.0"
}

# ─── Common settings ──────────────────────────────────────────────────────────

group "default" {
  targets = ["hop-8-to-9", "lumen-migrator"]
}

# ─── hop-8-to-9 ───────────────────────────────────────────────────────────────

target "hop-8-to-9" {
  context    = "."
  dockerfile = "docker/hop-8-to-9/Dockerfile"
  platforms  = ["linux/amd64", "linux/arm64"]
  tags = [
    "${REGISTRY}/hop-8-to-9:${HOP_8_TO_9_VERSION}",
    "${REGISTRY}/hop-8-to-9:latest",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: hop-8-to-9"
    "org.opencontainers.image.description" = "Laravel 8 to 9 upgrade pipeline container"
    "org.opencontainers.image.version"     = HOP_8_TO_9_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  cache-from = ["type=registry,ref=${REGISTRY}/hop-8-to-9:buildcache"]
  cache-to   = ["type=registry,ref=${REGISTRY}/hop-8-to-9:buildcache,mode=max"]
}

# ─── lumen-migrator ───────────────────────────────────────────────────────────

target "lumen-migrator" {
  context    = "."
  dockerfile = "docker/lumen-migrator/Dockerfile"
  platforms  = ["linux/amd64", "linux/arm64"]
  tags = [
    "${REGISTRY}/lumen-migrator:${LUMEN_MIGRATOR_VERSION}",
    "${REGISTRY}/lumen-migrator:latest",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: lumen-migrator"
    "org.opencontainers.image.description" = "Lumen to Laravel migration pipeline container"
    "org.opencontainers.image.version"     = LUMEN_MIGRATOR_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  cache-from = ["type=registry,ref=${REGISTRY}/lumen-migrator:buildcache"]
  cache-to   = ["type=registry,ref=${REGISTRY}/lumen-migrator:buildcache,mode=max"]
}

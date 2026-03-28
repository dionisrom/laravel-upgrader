# ============================================================
# docker-bake.hcl — Build configuration for hop containers
# Usage:
#   docker buildx bake                        # build all targets (local)
#   docker buildx bake hop-8-to-9             # build single target
#   docker buildx bake --push                 # build and push all (CI)
# ============================================================

variable "REGISTRY" {
  default = "upgrader"
}

variable "HOP_8_TO_9_VERSION" {
  default = "1.0.0"
}

variable "HOP_9_TO_10_VERSION" {
  default = "1.0.0"
}

variable "HOP_10_TO_11_VERSION" {
  default = "1.0.0"
}

variable "HOP_11_TO_12_VERSION" {
  default = "1.0.0"
}

variable "HOP_12_TO_13_VERSION" {
  default = "1.0.0"
}

variable "LUMEN_MIGRATOR_VERSION" {
  default = "1.0.0"
}

# ─── Common settings ──────────────────────────────────────────────────────────

group "default" {
  targets = [
    "hop-8-to-9",
    "hop-8-to-9-php82",
    "hop-8-to-9-php83",
    "hop-9-to-10",
    "hop-9-to-10-php82",
    "hop-9-to-10-php83",
    "hop-10-to-11",
    "hop-10-to-11-php83",
    "hop-11-to-12",
    "hop-11-to-12-php83",
    "hop-12-to-13",
    "lumen-migrator",
    "lumen-migrator-php83",
  ]
}

# ─── hop-8-to-9 ───────────────────────────────────────────────────────────────

target "hop-8-to-9" {
  context    = "."
  dockerfile = "docker/hop-8-to-9/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.1-cli-alpine"
  }
  tags = [
    "${REGISTRY}/hop-8-to-9:${HOP_8_TO_9_VERSION}",
    "${REGISTRY}/hop-8-to-9:latest",
    "${REGISTRY}/hop-8-to-9:php8.1",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: hop-8-to-9"
    "org.opencontainers.image.description" = "Laravel 8 to 9 upgrade pipeline container"
    "org.opencontainers.image.version"     = HOP_8_TO_9_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  output = ["type=docker"]
}

target "hop-8-to-9-php82" {
  context    = "."
  dockerfile = "docker/hop-8-to-9/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.2-cli-alpine"
  }
  tags = ["${REGISTRY}/hop-8-to-9:php8.2"]
  output = ["type=docker"]
}

target "hop-8-to-9-php83" {
  context    = "."
  dockerfile = "docker/hop-8-to-9/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.3-cli-alpine"
  }
  tags = ["${REGISTRY}/hop-8-to-9:php8.3"]
  output = ["type=docker"]
}

# ─── hop-9-to-10 ──────────────────────────────────────────────────────────────

target "hop-9-to-10" {
  context    = "."
  dockerfile = "docker/hop-9-to-10/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.1-cli-alpine"
  }
  tags = [
    "${REGISTRY}/hop-9-to-10:${HOP_9_TO_10_VERSION}",
    "${REGISTRY}/hop-9-to-10:latest",
    "${REGISTRY}/hop-9-to-10:php8.1",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: hop-9-to-10"
    "org.opencontainers.image.description" = "Laravel 9 to 10 upgrade pipeline container"
    "org.opencontainers.image.version"     = HOP_9_TO_10_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  output = ["type=docker"]
}

target "hop-9-to-10-php82" {
  context    = "."
  dockerfile = "docker/hop-9-to-10/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.2-cli-alpine"
  }
  tags = ["${REGISTRY}/hop-9-to-10:php8.2"]
  output = ["type=docker"]
}

target "hop-9-to-10-php83" {
  context    = "."
  dockerfile = "docker/hop-9-to-10/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.3-cli-alpine"
  }
  tags = ["${REGISTRY}/hop-9-to-10:php8.3"]
  output = ["type=docker"]
}

# ─── hop-10-to-11 ─────────────────────────────────────────────────────────────

target "hop-10-to-11" {
  context    = "."
  dockerfile = "docker/hop-10-to-11/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.2-cli-alpine"
  }
  tags = [
    "${REGISTRY}/hop-10-to-11:${HOP_10_TO_11_VERSION}",
    "${REGISTRY}/hop-10-to-11:latest",
    "${REGISTRY}/hop-10-to-11:php8.2",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: hop-10-to-11"
    "org.opencontainers.image.description" = "Laravel 10 to 11 upgrade pipeline container (slim skeleton migration)"
    "org.opencontainers.image.version"     = HOP_10_TO_11_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  output = ["type=docker"]
}

target "hop-10-to-11-php83" {
  context    = "."
  dockerfile = "docker/hop-10-to-11/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.3-cli-alpine"
  }
  tags = ["${REGISTRY}/hop-10-to-11:php8.3"]
  output = ["type=docker"]
}

# ─── hop-11-to-12 ─────────────────────────────────────────────────────────────

target "hop-11-to-12" {
  context    = "."
  dockerfile = "docker/hop-11-to-12/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.2-cli-alpine"
  }
  tags = [
    "${REGISTRY}/hop-11-to-12:${HOP_11_TO_12_VERSION}",
    "${REGISTRY}/hop-11-to-12:latest",
    "${REGISTRY}/hop-11-to-12:php8.2",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: hop-11-to-12"
    "org.opencontainers.image.description" = "Laravel 11 to 12 upgrade pipeline container"
    "org.opencontainers.image.version"     = HOP_11_TO_12_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  output = ["type=docker"]
}

target "hop-11-to-12-php83" {
  context    = "."
  dockerfile = "docker/hop-11-to-12/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.3-cli-alpine"
  }
  tags = ["${REGISTRY}/hop-11-to-12:php8.3"]
  output = ["type=docker"]
}

# ─── hop-12-to-13 ─────────────────────────────────────────────────────────────

target "hop-12-to-13" {
  context    = "."
  dockerfile = "docker/hop-12-to-13/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.3-cli-alpine"
  }
  tags = [
    "${REGISTRY}/hop-12-to-13:${HOP_12_TO_13_VERSION}",
    "${REGISTRY}/hop-12-to-13:latest",
    "${REGISTRY}/hop-12-to-13:php8.3",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: hop-12-to-13"
    "org.opencontainers.image.description" = "Laravel 12 to 13 upgrade pipeline container (PHP 8.3 required)"
    "org.opencontainers.image.version"     = HOP_12_TO_13_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  output = ["type=docker"]
}

# ─── lumen-migrator ───────────────────────────────────────────────────────────

target "lumen-migrator" {
  context    = "."
  dockerfile = "docker/lumen-migrator/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.1-cli-alpine"
  }
  tags = [
    "${REGISTRY}/lumen-migrator:${LUMEN_MIGRATOR_VERSION}",
    "${REGISTRY}/lumen-migrator:latest",
    "${REGISTRY}/lumen-migrator:php8.1",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Laravel Upgrader: lumen-migrator"
    "org.opencontainers.image.description" = "Lumen to Laravel migration pipeline container"
    "org.opencontainers.image.version"     = LUMEN_MIGRATOR_VERSION
    "org.opencontainers.image.source"      = "https://github.com/your-org/laravel-upgrader"
  }
  output = ["type=docker"]
}

target "lumen-migrator-php83" {
  context    = "."
  dockerfile = "docker/lumen-migrator/Dockerfile"
  args = {
    PHP_BASE_IMAGE = "php:8.3-cli-alpine"
  }
  tags = ["${REGISTRY}/lumen-migrator:php8.3"]
  output = ["type=docker"]
}

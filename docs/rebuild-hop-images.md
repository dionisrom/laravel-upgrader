# Rebuild Hop Images Locally

Use this guide when hop container code changes and the local Docker images must be rebuilt before rerunning the live E2E suite.

These steps are written for local development on Windows with Docker Desktop and PowerShell. They avoid the `docker buildx bake --load` issues already seen locally:

- registry cache auth failures from `cache-to` and `cache-from`
- manifest-list errors when using `--load` with multi-platform targets
- Buildx instability when loading multiple bake targets at once

## Prerequisites

- Docker Desktop is running
- You are in the repository root
- The workspace path is `G:\dev\laravel-upgrader`

## 1. Open PowerShell in the repo root

```powershell
Set-Location G:\dev\laravel-upgrader
```

## 2. Ensure a usable Buildx builder exists

Create a dedicated local builder once:

```powershell
docker buildx create --name upgrader-builder --driver docker-container --use
```

If Docker reports that `upgrader-builder` already exists, do not run `create` again. Switch to the existing builder instead:

```powershell
docker buildx use upgrader-builder
docker buildx inspect --bootstrap
```

If it already exists, switch to it:

```powershell
docker buildx use upgrader-builder
```

Optional check:

```powershell
docker buildx inspect --bootstrap
```

If the existing builder is broken and you intentionally want a clean replacement:

```powershell
docker buildx rm upgrader-builder
docker buildx create --name upgrader-builder --driver docker-container --use
docker buildx inspect --bootstrap
```

## 3. Rebuild each hop image sequentially

Build each hop one at a time and load it directly into the local Docker daemon as `linux/amd64`.

```powershell
docker buildx build --builder upgrader-builder --platform linux/amd64 -t upgrader/hop-8-to-9:latest  -f docker/hop-8-to-9/Dockerfile  --load .
docker buildx build --builder upgrader-builder --platform linux/amd64 -t upgrader/hop-9-to-10:latest -f docker/hop-9-to-10/Dockerfile --load .
docker buildx build --builder upgrader-builder --platform linux/amd64 -t upgrader/hop-10-to-11:latest -f docker/hop-10-to-11/Dockerfile --load .
docker buildx build --builder upgrader-builder --platform linux/amd64 -t upgrader/hop-11-to-12:latest -f docker/hop-11-to-12/Dockerfile --load .
docker buildx build --builder upgrader-builder --platform linux/amd64 -t upgrader/hop-12-to-13:latest -f docker/hop-12-to-13/Dockerfile --load .
```

Notes:

- Do not use `docker buildx bake --load` for the local Windows path unless the bake file is overridden to remove registry cache settings and multi-platform output.
- Do not use multi-platform local `--load`; the local daemon expects a single platform image.

## 4. Verify the images are present

```powershell
docker image ls "upgrader/hop-*"
```

You should see these tags:

- `upgrader/hop-8-to-9:latest`
- `upgrader/hop-9-to-10:latest`
- `upgrader/hop-10-to-11:latest`
- `upgrader/hop-11-to-12:latest`
- `upgrader/hop-12-to-13:latest`

Optional spot check for one image:

```powershell
docker image inspect upgrader/hop-8-to-9:latest | Select-Object -First 1
```

## 5. Rerun the live fixture suite

```powershell
$env:DOCKER_AVAILABLE = '1'
$env:E2E_MAX_CONTAINER_MEMORY_MB = '512'
php vendor/bin/phpunit --configuration phpunit.xml.dist --colors=always tests/E2E/Fixtures
```

If you only want the smallest live reproduction first, run the minimal fixture:

```powershell
$env:DOCKER_AVAILABLE = '1'
$env:E2E_MAX_CONTAINER_MEMORY_MB = '512'
php vendor/bin/phpunit --configuration phpunit.xml.dist --colors=always tests/E2E/Fixtures/FixtureMinimalTest.php
```

## 6. Clear the environment variables after the run

```powershell
Remove-Item Env:\DOCKER_AVAILABLE -ErrorAction SilentlyContinue
Remove-Item Env:\E2E_MAX_CONTAINER_MEMORY_MB -ErrorAction SilentlyContinue
```

## Troubleshooting

### `Cache export is not supported for the docker driver`

You are probably using the default Buildx driver instead of the `docker-container` builder.

Run:

```powershell
docker buildx use upgrader-builder
docker buildx inspect --bootstrap
```

### `docker exporter does not currently support exporting manifest lists`

You are trying to `--load` a multi-platform build. Use:

- `--platform linux/amd64`
- one image at a time

### `push access denied` or `insufficient_scope`

That usually comes from `docker-bake.hcl` registry cache settings. For local rebuilds, use the direct `docker buildx build ... --load .` commands in this file instead of `docker buildx bake`.

### Build succeeds but PHPUnit still uses old behavior

Recheck that the rebuilt image tag is exactly `:latest` and matches the names returned by the hop planner.

```powershell
docker image ls "upgrader/hop-*"
```

The planner currently expects:

- `upgrader/hop-8-to-9`
- `upgrader/hop-9-to-10`
- `upgrader/hop-10-to-11`
- `upgrader/hop-11-to-12`
- `upgrader/hop-12-to-13`

## CI Reference

CI currently builds hop images from [.github/workflows/slow-e2e.yml](.github/workflows/slow-e2e.yml), but the local rebuild flow in this file is intentionally more conservative because it avoids local Windows Buildx and registry-cache issues.
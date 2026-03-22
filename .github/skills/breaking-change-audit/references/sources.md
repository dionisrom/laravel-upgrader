# Breaking Change Research Sources

Reference for the `breaking-change-audit` skill. Lists the canonical sources to consult when auditing a new hop.

---

## Laravel Version Hops

### Laravel 8 → 9 (`hop-8-to-9`)

| Source | URL |
|---|---|
| Official upgrade guide | https://laravel.com/docs/9.x/upgrade |
| Framework CHANGELOG | https://github.com/laravel/framework/blob/9.x/CHANGELOG.md |
| rector-laravel CHANGELOG | https://github.com/driftingly/rector-laravel/blob/main/CHANGELOG.md |
| rector-laravel L9 set | `vendor/driftingly/rector-laravel/src/Set/LaravelSetList.php` → `LARAVEL_90` |
| Community upgrade thread | https://laracasts.com/discuss/channels/laravel/laravel-9-upgrade-guide |

Key L8→L9 areas to audit:
- `illuminate/http` `Request::date()` return type change
- Route caching: `Symfony\Component\HttpFoundation` upgrades
- `Flysystem` v3 storage refactor
- PHP 8.0 minimum — deprecations from 7.x become errors

---

### Laravel 9 → 10 (`hop-9-to-10`)

| Source | URL |
|---|---|
| Official upgrade guide | https://laravel.com/docs/10.x/upgrade |
| Framework CHANGELOG | https://github.com/laravel/framework/blob/10.x/CHANGELOG.md |
| rector-laravel L10 set | `LaravelSetList::LARAVEL_100` |
| rector-laravel CHANGELOG | https://github.com/driftingly/rector-laravel/blob/main/CHANGELOG.md |

Key L9→L10 areas to audit:
- PHP 8.1 minimum (readonly properties, enums, fibers)
- Native return types on all model/collection methods
- `Str` and `Arr` method removals
- `Bus::dispatchNow()` → `Bus::dispatchSync()`
- Predis v2 / PhpRedis upgrade

---

### Laravel 10 → 11 (`hop-10-to-11`)

| Source | URL |
|---|---|
| Official upgrade guide | https://laravel.com/docs/11.x/upgrade |
| Framework CHANGELOG | https://github.com/laravel/framework/blob/11.x/CHANGELOG.md |
| Slim skeleton blog post | https://laravel-news.com/laravel-11 |
| rector-laravel L11 set | `LaravelSetList::LARAVEL_110` |

Key L10→L11 areas to audit:
- Slim application skeleton (single `bootstrap/app.php`)
- Middleware registration changes (global middleware list removed)
- HTTP kernel removal → `Application::withMiddleware()`
- Console kernel removal → `Application::withSchedule()`/`withCommands()`
- Service provider consolidation

---

### Laravel 11 → 12 (`hop-11-to-12`)

| Source | URL |
|---|---|
| Official upgrade guide | https://laravel.com/docs/12.x/upgrade |
| Framework CHANGELOG | https://github.com/laravel/framework/blob/12.x/CHANGELOG.md |
| rector-laravel L12 set | `LaravelSetList::LARAVEL_120` |

Key areas (consult official guide — L12 is relatively conservative):
- New Eloquent method signatures
- Queue contract changes
- PHP 8.2 minimum enforcement

---

### Laravel 12 → 13 (`hop-12-to-13`)

| Source | URL |
|---|---|
| Official upgrade guide | https://laravel.com/docs/13.x/upgrade (when published) |
| Framework `13.x` branch | https://github.com/laravel/framework/blob/13.x/CHANGELOG.md |
| rector-laravel L13 set | `LaravelSetList::LARAVEL_130` (when available) |

> Note: At time of writing this is a future hop. Monitor the 13.x branch actively.

---

## PHP Version Hops

### PHP 8.0 → 8.1 (`php-8.0-to-8.1`)

| Source | URL |
|---|---|
| Migration guide | https://www.php.net/manual/en/migration81.php |
| Accepted RFCs | https://wiki.php.net/rfc#php_81 |
| Rector rule set | `LevelSetList::UP_TO_PHP_81` |

Key areas:
- Intersection types
- Readonly properties
- Enums (new syntax)
- `never` return type
- `array_is_list()` added
- Deprecated: `FILTER_SANITIZE_STRING`, implicit float to int coercion

---

### PHP 8.1 → 8.2 (`php-8.1-to-8.2`)

| Source | URL |
|---|---|
| Migration guide | https://www.php.net/manual/en/migration82.php |
| Accepted RFCs | https://wiki.php.net/rfc#php_82 |
| Rector rule set | `LevelSetList::UP_TO_PHP_82` |

Key areas:
- Readonly classes
- Disjunctive Normal Form (DNF) types
- `null`, `true`, `false` as standalone types
- Deprecated: Dynamic properties (use `__get`/`__set` or `#[AllowDynamicProperties]`)
- Deprecated: functions in `utf8_encode`, `utf8_decode`

---

### PHP 8.2 → 8.3 (`php-8.2-to-8.3`)

| Source | URL |
|---|---|
| Migration guide | https://www.php.net/manual/en/migration83.php |
| Accepted RFCs | https://wiki.php.net/rfc#php_83 |
| Rector rule set | `LevelSetList::UP_TO_PHP_83` |

Key areas:
- Typed class constants
- `json_validate()` new function
- `#[Override]` attribute
- Dynamic class constant fetch
- Deprecated: `ReflectionProperty::setValue()` without object parameter

---

### PHP 8.3 → 8.4 (`php-8.3-to-8.4`)

| Source | URL |
|---|---|
| Migration guide | https://www.php.net/manual/en/migration84.php |
| Accepted RFCs | https://wiki.php.net/rfc#php_84 |
| Rector rule set | `LevelSetList::UP_TO_PHP_84` |

Key areas:
- Property hooks
- Asymmetric visibility (`public private(set)`)
- `#[Deprecated]` attribute
- `array_find()`, `array_find_key()` new functions
- `new` in initializers (promoted constructor properties)
- Deprecated: implicitly nullable parameter types → must be explicit `?Type`

---

### PHP 8.4 → 8.5 Beta (`php-8.4-to-8.5`)

| Source | URL |
|---|---|
| Migration guide | https://www.php.net/manual/en/migration85.php (beta) |
| RFCs in progress | https://wiki.php.net/rfc#php_85 |
| PHP internals news | https://phpinternals.news |

> **BETA WARNING:** This hop must emit a `hop.warning` JSON-ND event at startup noting that PHP 8.5 is pre-release and the hop is experimental.

---

## Package Hop Sources

### Spatie Packages

| Package | Migration guide |
|---|---|
| `spatie/laravel-permission` | https://spatie.be/docs/laravel-permission/changelog |
| `spatie/laravel-medialibrary` | https://spatie.be/docs/laravel-medialibrary/changelog |
| `spatie/laravel-query-builder` | https://github.com/spatie/laravel-query-builder/blob/main/CHANGELOG.md |

### Livewire

| Version | Migration guide |
|---|---|
| v2 → v3 | https://livewire.laravel.com/docs/upgrading |

### Laravel Sanctum

| Version | Migration guide |
|---|---|
| v2 → v3 | https://github.com/laravel/sanctum/blob/3.x/CHANGELOG.md |

### Filament

| Version | Migration guide |
|---|---|
| v2 → v3 | https://filamentphp.com/docs/3.x/panels/upgrade-guide |

---

## rector-laravel Rule Set Map

```
LaravelSetList::LARAVEL_90   → Laravel 8 → 9
LaravelSetList::LARAVEL_100  → Laravel 9 → 10
LaravelSetList::LARAVEL_110  → Laravel 10 → 11
LaravelSetList::LARAVEL_120  → Laravel 11 → 12
LaravelSetList::LARAVEL_130  → Laravel 12 → 13 (future)
```

To inspect a set's rules:
```bash
grep -r "class.*Rector" vendor/driftingly/rector-laravel/src/Rector/ \
    --include="*.php" -l | sort
```

To check if a specific transformation exists upstream:
```bash
# By method name
grep -r "dispatchNow\|getBus\|myMethod" \
    vendor/driftingly/rector-laravel/src/ -l

# By class name being renamed
grep -r "RouteServiceProvider\|Kernel" \
    vendor/driftingly/rector-laravel/src/ -l
```

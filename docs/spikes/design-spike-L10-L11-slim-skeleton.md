# Design Spike: L10→L11 Slim Skeleton Migration

**Spike:** P1-21 (Spike 1 of 2)  
**Author:** Laravel Migration Specialist  
**Date:** 2026-03-22  
**Status:** Complete — gates Phase 2 start  
**Blocks:** P2-02 (Slim Skeleton Migrator module)

---

## Executive Summary

Laravel 11 introduced a radically restructured application skeleton — commonly called the "slim skeleton." The two most impactful changes are:

1. **`app/Http/Kernel.php` is deleted.** All HTTP middleware configuration moves into a fluent `->withMiddleware()` callback in `bootstrap/app.php`.
2. **`app/Exceptions/Handler.php` is deleted.** All exception handling configuration moves into a fluent `->withExceptions()` callback in `bootstrap/app.php`.

The `bootstrap/app.php` file changes from a simple IoC container bootstrap script (Lumen-style) into the single authoritative application builder:

```php
// Laravel 10 bootstrap/app.php (minimal, unchanged from L5)
$app = new Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton(Kernel::class, App\Http\Kernel::class);
$app->singleton(ExceptionHandler::class, App\Exceptions\Handler::class);
return $app;

// Laravel 11 bootstrap/app.php (new slim skeleton)
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', ...)
    ->withMiddleware(function (Middleware $middleware) {
        // all HTTP kernel content lives here
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // all handler content lives here
    })
    ->create();
```

### Key Design Decision

> **Scaffold regeneration** is the correct approach — not Rector AST transforms.

Rector transforms the AST of *existing files*. The problem here is different:
- The **source files** (`Kernel.php`, `Handler.php`) are **read and then deleted**.
- The **target file** (`bootstrap/app.php`) **does not exist in the source** — it must be generated.
- There is no "rename this method" or "change this expression" — there is a complete structural rearrangement across file boundaries.

This is the same problem class as the Lumen→Laravel migration (P1-14), which already solved it with the scaffold generator + migrators pattern. The L10→L11 spike should produce a `SlimSkeleton` suite mirroring that architecture.

### Automation Confidence

| Migration Area | Automation Coverage | Notes |
|---|---|---|
| Standard `$middleware` array           | ~95% | Fully mechanical |
| Standard `$middlewareGroups` overrides | ~90% | Edge cases: complete replacement vs. append |
| `$middlewareAliases` / `$routeMiddleware` | ~95% | Simple key→value mapping |
| `$middlewarePriority` overrides        | ~85% | Must preserve ordering |
| Custom Kernel `handle()` / `terminate()` | 0–20% | Almost always bespoke; flag for review |
| Standard `$dontReport` array          | ~95% | Fully mechanical |
| Standard `$dontFlash` array           | ~95% | Fully mechanical |
| `report()` override (simple)          | ~70% | Translatable if no custom logic |
| `render()` override (simple custom types) | ~60% | Translatable for type dispatch |
| Sentry/Bugsnag integrations in Handler | 0–10% | Third-party coupling; always flag |
| Multi-exception instanceof chains     | ~40% | Parseable but logic-sensitive |
| Console Kernel schedule closures      | ~85% | Direct lift to routes/console.php |
| Service provider bootstrap/providers.php | ~95% | Mechanical extraction |

**Overall estimated automation:** ~75–80% of L10→L11 migrations can be fully automated for typical enterprise applications. The remaining 20–25% will generate manual-review items documented in the audit report.

---

## 1. Kernel.php Migration Analysis

### 1.1 Standard Patterns (Automatable)

#### `$middleware` — Global HTTP Middleware Stack

**Source (L10):**
```php
// app/Http/Kernel.php
protected $middleware = [
    \App\Http\Middleware\TrustProxies::class,
    \Illuminate\Http\Middleware\HandleCors::class,
    \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
    \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
    \App\Http\Middleware\TrimStrings::class,
    \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
];
```

**Target (L11) in `bootstrap/app.php`:**
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append([
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        // ...
    ]);
})
```

> **Note:** L11 ships its own default global middleware stack. Only *non-default, custom* entries need to be appended. `KernelMiddlewareMigrator` must diff against the L11 default list and only emit middleware that are net-new additions.

**L11 default global middleware (do not re-emit):**
- `\Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks`
- `\Illuminate\Http\Middleware\TrustHosts`
- `\Illuminate\Http\Middleware\TrustProxies` (now built-in)
- `\Illuminate\Http\Middleware\HandleCors`
- `\Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance`
- `\Illuminate\Http\Middleware\ValidatePostSize`
- `\Illuminate\Foundation\Http\Middleware\TrimStrings`
- `\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull`

---

#### `$middlewareGroups` — Web and API Groups

**Source (L10):**
```php
protected $middlewareGroups = [
    'web' => [
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        // Custom additions:
        \App\Http\Middleware\TeamContext::class,
    ],
    'api' => [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'throttle:api',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],
];
```

**Target (L11):**
```php
->withMiddleware(function (Middleware $middleware) {
    // Only custom additions need specifying:
    $middleware->web(append: [
        \App\Http\Middleware\TeamContext::class,
    ]);
    $middleware->api(prepend: [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    ]);
})
```

> **Decision rule:** If the group contents exactly match the L11 defaults, emit nothing. If the group has been extended, emit only the delta using `append:`/`prepend:`. If the group has been *replaced* (removed default items), emit `replace:` and flag for manual review.

---

#### `$middlewareAliases` / `$routeMiddleware` — Route Middleware Aliases

**Source (L10):**
```php
// L10 used $routeMiddleware; L9 used same
protected $middlewareAliases = [
    'auth'             => \App\Http\Middleware\Authenticate::class,
    'auth.basic'       => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
    'auth.session'     => \Illuminate\Session\Middleware\AuthenticateSession::class,
    'cache.headers'    => \Illuminate\Http\Middleware\SetCacheHeaders::class,
    'can'              => \Illuminate\Auth\Middleware\Authorize::class,
    'guest'            => \App\Http\Middleware\RedirectIfAuthenticated::class,
    'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
    'precognitive'     => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
    'signed'           => \App\Http\Middleware\ValidateSignature::class,
    'throttle'         => \Illuminate\Routing\Middleware\ThrottleRequests::class,
    'verified'         => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    // Custom:
    'tenant'           => \App\Http\Middleware\EnsureTenantIsActive::class,
    'plan'             => \App\Http\Middleware\CheckSubscriptionPlan::class,
];
```

**Target (L11):**
```php
->withMiddleware(function (Middleware $middleware) {
    // Only non-default aliases are needed — L11 ships the framework defaults
    $middleware->alias([
        'tenant' => \App\Http\Middleware\EnsureTenantIsActive::class,
        'plan'   => \App\Http\Middleware\CheckSubscriptionPlan::class,
    ]);
})
```

> **L11 default aliases** (exclude from output): `auth`, `auth.basic`, `auth.session`, `cache.headers`, `can`, `guest`, `password.confirm`, `precognitive`, `signed`, `throttle`, `verified`.

---

#### `$middlewarePriority` — Middleware Execution Order

**Source (L10):**
```php
protected $middlewarePriority = [
    \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    // ... (custom additions)
    \App\Http\Middleware\EnsureTenantIsActive::class,
];
```

**Target (L11):**
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->priority([
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\EnsureTenantIsActive::class,
    ]);
})
```

> Always emits the **full list** when overriding priority — partial override is not supported in the L11 API.

---

#### `TrustProxies` Configuration

In L10, `TrustProxies` was a custom middleware class with `$proxies` and `$headers` properties. In L11 it is built into the framework and configurable via:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: ['192.168.1.0/24', '10.0.0.0/8']);
    $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST);
})
```

**Migration:** Parse `app/Http/Middleware/TrustProxies.php`, extract `$proxies` and `$headers` property values, and emit the `trustProxies()` call.

---

### 1.2 Enterprise Non-Standard Patterns (Manual Review Required)

| Pattern | Detection Method | Review Item Severity | Notes |
|---|---|---|---|
| Custom `handle(bool $passable, Closure $next)` on Kernel | AST: method node named `handle` | `error` | Must be extracted to custom middleware class |
| Custom `terminate(Request $req, Response $res)` on Kernel | AST: method node `terminate` | `warning` | Extract to terminable middleware |
| Conditional middleware based on `app()->environment()` | AST: if-statement in `__construct` or `handle` | `warning` | Must be converted to conditional registration |
| Custom properties not in base Kernel | AST: property nodes not in `$knownProperties` | `warning` | May be used by custom `handle()` — inspect |
| Rate limiter in `configureRateLimiting()` | AST: method named `configureRateLimiting` | `info` | Should move to `AppServiceProvider::boot()` |
| TrustHosts `$hosts` property customisation | AST: property `$hosts` in TrustHosts.php | `info` | Configurable in L11 via `trustHosts()` |

**Rate limiter migration example:**

```php
// L10 app/Http/Kernel.php
protected function configureRateLimiting(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });
}
```

```php
// L11 app/Providers/AppServiceProvider.php
public function boot(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });
}
```

---

## 2. Handler.php Migration Analysis

### 2.1 Standard Patterns (Automatable)

#### `$dontReport` — Exception Classes Not Reported

**Source (L10):**
```php
// app/Exceptions/Handler.php
protected $dontReport = [
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Validation\ValidationException::class,
    \App\Exceptions\BusinessRuleException::class,
];
```

**Target (L11):**
```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->dontReport([
        \App\Exceptions\BusinessRuleException::class,
    ]);
})
```

> **L11 default dontReport list** (exclude from output): `AuthenticationException`, `AuthorizationException`, `HttpException`, `HttpResponseException`, `ModelNotFoundException`, `SuspiciousOperationException`, `TokenMismatchException`, `ValidationException`.

---

#### `$dontFlash` — Input Keys Not Flashed on Validation Error

**Source (L10):**
```php
protected $dontFlash = [
    'current_password',
    'password',
    'password_confirmation',
    // custom:
    'credit_card_number',
    'cvv',
];
```

**Target (L11):**
```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->dontFlash([
        'credit_card_number',
        'cvv',
    ]);
})
```

---

#### `report()` Override — Custom Reporting Logic

**Source (L10):**
```php
public function report(Throwable $e): void
{
    if ($this->shouldntReport($e)) {
        return;
    }

    if ($e instanceof QueryException) {
        Log::channel('db-errors')->error($e->getMessage(), ['sql' => $e->getSql()]);
        return;
    }

    parent::report($e);
}
```

**Target (L11):**
```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (QueryException $e) {
        Log::channel('db-errors')->error($e->getMessage(), ['sql' => $e->getSql()]);
    })->stop(); // stop() prevents parent escalation when you return false/use stop()
})
```

> **Automatable when:** the `report()` body is a sequence of `if ($e instanceof X) { ... return; }` branches. Each branch becomes a typed `$exceptions->report(function (X $e) {...})` closure.

---

#### `render()` Override — Custom HTTP Response Generation

**Source (L10):**
```php
public function render($request, Throwable $e): Response
{
    if ($e instanceof PaymentRequiredException) {
        return response()->json(['error' => 'payment_required'], 402);
    }

    if ($e instanceof MaintenanceModeException) {
        return response()->view('errors.maintenance', [], 503);
    }

    return parent::render($request, $e);
}
```

**Target (L11):**
```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (PaymentRequiredException $e, Request $request) {
        return response()->json(['error' => 'payment_required'], 402);
    });

    $exceptions->render(function (MaintenanceModeException $e, Request $request) {
        return response()->view('errors.maintenance', [], 503);
    });
})
```

> **Automatable when:** each branch is `if ($e instanceof X) { return ...; }` with no inter-branch dependencies.

---

#### `renderable()` Registrations (Already Fluent in L10)

```php
// L10 — may exist in Handler register() or report()
$this->renderable(function (NotFoundHttpException $e, $request) {
    if ($request->is('api/*')) {
        return response()->json(['message' => 'Not Found.'], 404);
    }
});
```

```php
// L11 — identical syntax, just moves into the builder:
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (NotFoundHttpException $e, Request $request) {
        if ($request->is('api/*')) {
            return response()->json(['message' => 'Not Found.'], 404);
        }
    });
})
```

---

### 2.2 Enterprise Non-Standard Patterns (Manual Review Required)

| Pattern | Detection Method | Severity | Notes |
|---|---|---|---|
| Sentry `\Sentry\Laravel\Integration::captureUnhandledException($e)` | AST: static call to Sentry | `warning` | Sentry L11 SDK registers itself via service provider — remove manual call |
| Bugsnag `$this->bugsnag->notifyException($e)` | AST: method call to `bugsnag` | `warning` | Use Bugsnag L11 auto-reporting instead |
| Custom JSON error format overriding `render()` globally | AST: no `instanceof` guard on render return | `error` | Review output format contract |
| Multi-exception `instanceof` chain with shared logic | AST: shared code block across branches | `warning` | Cannot split into separate closures without duplication |
| `parent::report()` call with custom logic surrounding it | AST: `parent::` call inside custom `report()` | `warning` | L11 `$exceptions->report()` closures are additive; `parent::` semantics differ |
| `shouldReport()` override | AST: method node named `shouldReport` | `error` | No direct equivalent; use `$exceptions->dontReport()` or custom middleware |
| Custom `context()` method providing extra log context | AST: method node named `context` | `info` | Move to a custom log channel or reporting closure |
| Custom `unauthenticated()` redirect logic | AST: method named `unauthenticated` | `warning` | Now handled by `AuthenticationException::redirectTo()` or via `render()` closure |

---

## 3. Console Kernel Migration

### 3.1 Standard Patterns (Automatable)

The `app/Console/Kernel.php` is also deleted in L11. Schedule definitions move to `routes/console.php`.

**Source (L10) — `app/Console/Kernel.php`:**
```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('emails:send')->dailyAt('08:00');
    $schedule->job(new ProcessPodcast)->everyFiveMinutes();
    $schedule->call(function () {
        DB::table('recent_users')->delete();
    })->daily();
}

protected $commands = [
    Commands\ImportUsers::class,
    Commands\GenerateReport::class,
];
```

**Target (L11) — `routes/console.php`:**
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('emails:send')->dailyAt('08:00');
Schedule::job(new ProcessPodcast)->everyFiveMinutes();
Schedule::call(function () {
    DB::table('recent_users')->delete();
})->daily();
```

> The custom `$commands` array moves to Artisan auto-discovery (via the `withCommands()` builder method or remains in a service provider). `ConsoleKernelMigrator` extracts the `schedule()` method body and the `$commands` entries.

**Target (L11) — `bootstrap/app.php`:**
```php
->withCommands([
    \App\Console\Commands\ImportUsers::class,
    \App\Console\Commands\GenerateReport::class,
])
```

---

### 3.2 Non-Standard Console Kernel Patterns (Manual Review)

| Pattern | Severity | Notes |
|---|---|---|
| Custom `schedule()` method calling `$schedule->call([$object, 'method'])` | `info` | Works in L11 — verify object is resolvable |
| Environment-conditional scheduling (`if (app()->isProduction())`) | `warning` | L11 supports `->environments(['production'])` on scheduled tasks — auto-fixable if simple |
| `commands()` override with dynamic discovery paths | `warning` | Must use `withCommands()` with explicit paths in L11 |
| `bootstrapWith()` override | `error` | Very rare; no equivalent — flag for manual review |

---

## 4. Service Provider Registration

### 4.1 `config/app.php` providers array — Backwards Compatible

The `config/app.php` `providers` array from L10 **still works** in L11 with no changes. This is intentionally backwards-compatible.

### 4.2 New Pattern — `bootstrap/providers.php`

Laravel 11 introduces a dedicated provider list file:

```php
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    // custom enterprise providers:
    App\Providers\TenancyServiceProvider::class,
    App\Providers\BillingServiceProvider::class,
];
```

**Migration:** `BootstrapProvidersMigrator` extracts the `providers` array from `config/app.php`, removes all first-party Laravel framework providers that auto-register in L11, and generates `bootstrap/providers.php`.

**Framework providers to remove (auto-register in L11):**
- `Illuminate\Auth\AuthServiceProvider`
- `Illuminate\Broadcasting\BroadcastServiceProvider`
- `Illuminate\Bus\BusServiceProvider`
- `Illuminate\Cache\CacheServiceProvider`
- `Illuminate\Foundation\Providers\ConsoleSupportServiceProvider`
- `Illuminate\Cookie\CookieServiceProvider`
- `Illuminate\Database\DatabaseServiceProvider`
- `Illuminate\Encryption\EncryptionServiceProvider`
- `Illuminate\Filesystem\FilesystemServiceProvider`
- `Illuminate\Foundation\Providers\FoundationServiceProvider`
- `Illuminate\Hashing\HashServiceProvider`
- `Illuminate\Log\LogServiceProvider`
- `Illuminate\Mail\MailServiceProvider`
- `Illuminate\Notifications\NotificationServiceProvider`
- `Illuminate\Pagination\PaginationServiceProvider`
- `Illuminate\Pipeline\PipelineServiceProvider`
- `Illuminate\Queue\QueueServiceProvider`
- `Illuminate\Redis\RedisServiceProvider`
- `Illuminate\Auth\Passwords\PasswordResetServiceProvider`
- `Illuminate\Session\SessionServiceProvider`
- `Illuminate\Translation\TranslationServiceProvider`
- `Illuminate\Validation\ValidationServiceProvider`
- `Illuminate\View\ViewServiceProvider`

> **Note:** Third-party package providers (Spatie, Telescope, Horizon, Sanctum, etc.) should be evaluated individually. Most are auto-discovered via `composer.json` `extra.laravel.providers` — they should be removed from the explicit list if auto-discoverable.

---

## 5. Other Laravel 11 Structural Changes

### 5.1 Config File Consolidation

Laravel 11 merged some rarely-used config files. A `ConfigDefaultsAuditor` should flag:

| Config Key Change | Action |
|---|---|
| `config/broadcasting.php` now includes Reverb driver | `info` — no action needed unless customised |
| `config/database.php` SQLite default changed | `info` — flag if `DB_CONNECTION` not set in `.env` |
| `config/queue.php` `sync` driver now default | `info` — flag if `QUEUE_CONNECTION` not set |
| `config/sanctum.php` — new `encrypt_cookies` option | `info` — add with default `false` |
| `config/auth.php` — `passwords.users.throttle` added | `info` — add with default `60` |

### 5.2 Migration Numbering Convention

L11 introduces date-based migration numbering:

```
# L10 convention
2023_01_15_000000_create_users_table.php

# L11 new convention (optional, non-breaking)
0001_01_01_000000_create_users_table.php  (base migrations)
```

> This is entirely optional. Existing timestamp-based migrations continue to work. No migration action needed; include as an `info`-severity note.

### 5.3 Vite Configuration

`vite.config.js` gains `ssr()` plugin support and the `postcss.config.js` format changes, but these are non-breaking — existing Vite configs work unchanged. Note as `info` only.

### 5.4 Route Files

L11 creates `routes/web.php` and `routes/api.php` but no `RouteServiceProvider` is generated by default. Route registration is inlined into `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

#### 5.4.1 Standard L10 Pattern — `RouteServiceProvider::boot()` and `map()`

A typical L10 `RouteServiceProvider` registers routes via the `boot()` method (which calls `$this->routes(...)` or `map()`):

```php
// L10 — app/Providers/RouteServiceProvider.php (standard)
class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
```

**L11 target — `bootstrap/app.php`:**

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

The `withRouting()` method accepts these named parameters: `web`, `api`, `commands`, `channels`, `health`, `apiPrefix` (default `'api'`), and a `then` closure for additional route files.

#### 5.4.2 `configureRateLimiting()` Migration Destination

In L10, rate limiters are typically defined inside `RouteServiceProvider::boot()` or a dedicated `configureRateLimiting()` method. In L11, these move to `AppServiceProvider::boot()`:

```php
// L11 — app/Providers/AppServiceProvider.php
public function boot(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });
}
```

The `RouteServiceProviderMigrator` must extract all `RateLimiter::for(...)` calls and relocate them to `AppServiceProvider::boot()` rather than `bootstrap/app.php`. This applies to both the standard `configureRateLimiting()` pattern and inline rate limiter definitions in `boot()`.

#### 5.4.3 Enterprise Non-Standard Patterns

| Pattern | Example | L11 Migration | Severity |
|---|---|---|---|
| Versioned API routes (`mapApiV1Routes`, `mapApiV2Routes`) | `Route::prefix('api/v1')->group(...)` per version | Use `then:` closure in `withRouting()` for additional groups | `warning` |
| Domain-based routing | `Route::domain('{tenant}.app.com')->group(...)` | Use `then:` closure; domain routes are not first-class `withRouting()` params | `warning` |
| Conditional route loading (feature flag / env) | `if (config('features.admin')) { Route::group(...) }` | Use `then:` closure; flag for manual review | `warning` |
| Route model binding customisations in `boot()` | `Route::model('user', User::class)` or `Route::bind('user', fn ...)` | Move to `AppServiceProvider::boot()` alongside rate limiters | `info` |
| Global route patterns | `Route::pattern('id', '[0-9]+')` | Move to `AppServiceProvider::boot()` | `info` |
| Custom API prefix override | `->prefix('api/v2')` instead of `'api'` | Map to `apiPrefix:` parameter in `withRouting()` | `info` |
| Glob-based / loop-based dynamic route loading | `foreach (glob(...) as $routeFile)` | Use `then:` closure; flag as `warning` — cannot be fully automated | `warning` |

#### 5.4.4 Migration Algorithm

1. Parse `RouteServiceProvider` AST via `nikic/php-parser`.
2. Extract `RateLimiter::for(...)` calls → queue for `AppServiceProvider::boot()`.
3. Extract `Route::model(...)`, `Route::bind(...)`, `Route::pattern(...)` calls → queue for `AppServiceProvider::boot()`.
4. Classify route group registrations:
   - Standard `web` group (middleware `web`, no prefix) → `web:` parameter.
   - Standard `api` group (middleware `api`, prefix `api`) → `api:` parameter + `apiPrefix:` if non-default.
   - Everything else → `then:` closure body.
5. Detect dynamic/loop-based loading → emit `warning` event, place in `then:` closure with `// TODO: manual review` comment.
6. Write `withRouting()` block. Write relocated bindings/rate-limiters to `AppServiceProvider`.

### 5.5 Health Check Endpoint

L11 ships with a `/up` health endpoint by default (see `health:` in `withRouting()`). If the application had a custom `/health` or `/ping` route, flag it as `info` — consider migrating.

---

## 6. Phase 2 Module List

The following table defines the concrete modules for `P2-02-slim-skeleton.md`. The architecture mirrors the Lumen migration suite (`src-container/Lumen/`) established in P1-14.

| Module | Class Name | Namespace | Pattern | Input | Output |
|---|---|---|---|---|---|
| HTTP Kernel middleware migration | `KernelMiddlewareMigrator` | `AppContainer\SlimSkeleton` | Read → diff → generate | `app/Http/Kernel.php` | `bootstrap/app.php` `withMiddleware()` block |
| Exception Handler migration | `ExceptionHandlerMigrator` | `AppContainer\SlimSkeleton` | Read → branch-extract → generate | `app/Exceptions/Handler.php` | `bootstrap/app.php` `withExceptions()` block |
| Console Kernel migration | `ConsoleKernelMigrator` | `AppContainer\SlimSkeleton` | Read → extract schedule → generate | `app/Console/Kernel.php` | `routes/console.php` + `withCommands()` |
| Bootstrap providers generator | `BootstrapProvidersMigrator` | `AppContainer\SlimSkeleton` | Extract from config → diff framework list | `config/app.php` providers | `bootstrap/providers.php` |
| Route service provider migration | `RouteServiceProviderMigrator` | `AppContainer\SlimSkeleton` | Read → extract routes → generate | `app/Providers/RouteServiceProvider.php` | `bootstrap/app.php` `withRouting()` block |
| Config defaults auditor | `ConfigDefaultsAuditor` | `AppContainer\SlimSkeleton` | Diff config keys vs L11 defaults | All `config/*.php` files | Manual review items (info/warning severity) |
| Slim skeleton scaffold writer | `SlimSkeletonScaffoldWriter` | `AppContainer\SlimSkeleton` | Assemble partial outputs → write | All migrator results | Final `bootstrap/app.php` file |
| Slim skeleton audit report | `SlimSkeletonAuditReport` | `AppContainer\SlimSkeleton` | Aggregate all migrator results | All migration results | JSON-ND `slim_skeleton_audit` event |

### Result Objects (one per migrator, mirrors Lumen suite):
- `KernelMigrationResult`
- `ExceptionHandlerMigrationResult`
- `ConsoleKernelMigrationResult`
- `ProvidersBootstrapResult`
- `RouteServiceProviderMigrationResult`
- `ConfigAuditResult`

### Shared Types:
- `SlimSkeletonManualReviewItem` — severity, file, line, message, suggestion
- `SlimSkeletonAuditResult` — all items grouped by category + summary

---

## 7. Approach Decision

### Why Not Rector?

Rector's transformation model is:
1. Parse source file into AST
2. Apply node visitors that mutate AST nodes
3. Write mutated AST back to **the same file**

This model does not fit the L10→L11 skeleton migration:

| Aspect | Rector Model | L10→L11 Reality |
|---|---|---|
| Transform target | Existing file, same path | Target file doesn't exist yet |
| Source file fate | Modified in-place | Source files **deleted** |
| Transformation type | Node-to-node substitution | Complete cross-file structural rearrangement |
| Code generation | Not Rector's strength | Requires PhpParser `Builder\*` AST generation |

### Why Scaffold Regeneration + Migrators?

The same architectural decision was made in P1-14 (Lumen migration):
- **Read phase:** Parse source files using php-parser AST visitors to extract structured data (middleware arrays, exception handlers, etc.) into typed PHP DTOs.
- **Transform phase:** Each migrator class takes the DTO and produces a partial code block (string or AST fragment) for the target file.
- **Write phase:** `SlimSkeletonScaffoldWriter` assembles all partial outputs into the final `bootstrap/app.php`, then deletes source files.
- **Audit phase:** `SlimSkeletonAuditReport` collects all `ManualReviewItem` instances, emits the JSON-ND `slim_skeleton_audit` event, and fails the hop if `error`-severity items exist.

### Comparison to Lumen Migration

| Aspect | Lumen→Laravel | L10→L11 Slim Skeleton |
|---|---|---|
| Source bootstrap | `bootstrap/app.php` (Lumen builder) | `app/Http/Kernel.php` + `app/Exceptions/Handler.php` |
| Target bootstrap | `app/Http/Kernel.php` (Laravel Kernel) | `bootstrap/app.php` (L11 builder) |
| Scaffold generated? | Yes — full L9 skeleton | Yes — new L11 `bootstrap/app.php` |
| Source files deleted? | Only Lumen-specific ones | Yes — Kernel.php, Handler.php, original bootstrap/app.php |
| Pattern | Extract → generate → audit | Extract → generate → audit (identical) |

---

## Decision

**Scaffold regeneration** with typed PHP migrator classes is the correct implementation approach for the L10→L11 slim skeleton migration. Rector is not appropriate here. The `SlimSkeleton` suite should be architecturally identical to the `Lumen` suite in `src-container/Lumen/` and should be located at `src-container/SlimSkeleton/`.

---

## Phase 2 Impact

Phase 2 task **P2-02** must build:

1. The `src-container/SlimSkeleton/` directory with the 8 modules listed in §6.
2. A `hop-10-to-11` Docker container that invokes the suite.
3. Fixtures directory `tests/Fixtures/SlimSkeleton/` with real-world Kernel.php and Handler.php examples (standard, Sentry-integrated, multi-tenant, multi-exception).
4. Unit tests for each migrator following the same fixture pattern as the Lumen suite.
5. Integration test: full hop run on a Laravel 10 skeleton produces a valid Laravel 11 `bootstrap/app.php`.

**Estimated Phase 2 effort for this scope:** 8–10 development days for a senior Laravel engineer familiar with the codebase.

---

## References

- [Laravel 11 Upgrade Guide](https://laravel.com/docs/11.x/upgrade)
- [Laravel 11 Release Notes](https://laravel.com/docs/11.x/releases)
- [Bootstrap/app.php documentation](https://laravel.com/docs/11.x/structure#the-bootstrap-directory)
- [Laravel 11 Slim Skeleton PR #49345](https://github.com/laravel/laravel/pull/6138)
- Internal: P1-14 Lumen Migration Suite (`src-container/Lumen/`)
- Internal: P2-02 Slim Skeleton Migrator task (to be created by Phase 2 planning)

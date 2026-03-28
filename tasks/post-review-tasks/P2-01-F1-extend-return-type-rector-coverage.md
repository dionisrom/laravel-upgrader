# P2-01-F1: Extend LaravelModelReturnTypeRector to cover ServiceProvider, Middleware, ExceptionHandler

**Severity:** HIGH  
**Source:** P2-01 review  
**Violated:** Task AC "Return type transformation handles high-volume framework class changes"; breaking-changes.json `l10_service_provider_boot_method_typed` claims `automated: true`

## Problem

`LaravelModelReturnTypeRector::CLASS_METHOD_RETURN_TYPES` only covers `Model` and `FormRequest`. ServiceProvider (`boot(): void`, `register(): void`), Middleware (`handle(): Response`), and ExceptionHandler (`render()`, `report()`, `register()`) are absent despite being high-volume return-type breaking changes in L10.

## Fix

Add the following parent classes and methods to `CLASS_METHOD_RETURN_TYPES`:

- `Illuminate\Support\ServiceProvider` / `ServiceProvider`: `boot` → `void`, `register` → `void`
- `Illuminate\Routing\Controller` / `Controller`: (verify if needed)
- `Illuminate\Foundation\Exceptions\Handler` / `ExceptionHandler`: `register` → `void`

Add corresponding unit tests.

## Files

- `src-container/Rector/Rules/L9ToL10/LaravelModelReturnTypeRector.php`
- `tests/Unit/Rector/Rules/L9ToL10/LaravelModelReturnTypeRectorTest.php`
- `tests/Unit/Rector/Rules/L9ToL10/Fixture/LaravelModelReturnType/` (new fixtures)

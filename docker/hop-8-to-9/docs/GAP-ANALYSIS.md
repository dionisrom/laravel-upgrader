# L8→L9 Rector Rule Gap Analysis

**Date:** 2026-03-25  
**Hop:** hop-8-to-9  
**Source:** `docker/hop-8-to-9/docs/breaking-changes.json`

## Upstream `driftingly/rector-laravel` Coverage (LaravelSetList::LARAVEL_90)

These breaking changes are handled by existing rector-laravel rules — no custom rules needed:

| Breaking Change ID | Upstream Rule |
|---|---|
| `l9_model_dates_removed` | `RectorLaravel\Rector\Class_\UnifyModelDatesWithCastsRector` |
| `l9_route_namespace_option_removed` | `RectorLaravel\Rector\StaticCall\RouteActionCallableRector` |
| `l9_accessor_mutator_simplified` | `RectorLaravel\Rector\ClassMethod\MigrateToSimplifiedAttributeRector` |

## Custom Rules (AppContainer\Rector\Rules\L8ToL9)

These breaking changes required custom rules not covered by rector-laravel:

| Breaking Change ID | Custom Rule |
|---|---|
| `l9_throttle_rate_limiter_api` | `HttpKernelMiddlewareRector` |
| `l9_model_unguard_deprecated` | `ModelUnguardRector` |
| `l9_password_rule_methods_renamed` | `PasswordRuleRector` |
| `l9_rule_where_not_renamed` | `WhereNotToWhereNotInRector` |

## Manual Review Only (rector_rule: null)

These breaking changes cannot be safely auto-transformed and are flagged for manual review:

| Breaking Change ID | Severity | Reason |
|---|---|---|
| `l9_castable_interface_change` | high | Signature change requires context |
| `l9_model_new_collection_signature` | medium | Custom override detection |
| `l9_authenticate_with_basic_auth_constructor` | medium | Constructor dependency change |
| `l9_gate_after_false_ignored` | high | Behavioral change, not syntactic |
| `l9_kernel_bootstrappers_removed` | high | Property-to-method migration |
| `l9_schedule_expression_passes_protected` | low | Visibility change |
| `l9_connection_interface_select_signature` | high | Interface signature change |
| `l9_grammar_compile_insert_get_id` | medium | Grammar subclass signature |
| `l9_pdo_integer_binding_removed` | medium | Behavioral change |
| `l9_sqlite_minimum_version` | high | Environment requirement |
| `l9_request_route_return_type` | medium | Return type narrowing |
| `l9_controller_call_action_signature` | medium | Signature change |
| `l9_symfony_http_foundation_6` | high | Vendor upgrade behavioral |
| `l9_php_8_minimum` | blocker | Environment requirement |
| `l9_flysystem_v3` | high | Package API change |
| `l9_session_handler_write_return_type` | medium | Interface signature |
| `l9_carbon_v2_microseconds` | medium | Behavioral change |
| `l9_symfony_mailer_replaces_swiftmailer` | high | Full package replacement |
| `l9_string_slugify_ascii_removal` | low | Behavioral change |
| `l9_cast_interface_return_type` | medium | Interface signature |
| `l9_http_client_throw_exception` | medium | API addition |
| `l9_queue_job_return_type` | low | Return type enforcement |

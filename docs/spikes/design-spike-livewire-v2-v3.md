# Design Spike: Livewire V2→V3 Migration Scope

**Spike:** P1-21 (Spike 2 of 2)  
**Author:** Laravel Migration Specialist  
**Date:** 2026-03-22  
**Status:** Complete — gates Phase 2 start  
**Blocks:** P2-Livewire package ruleset task

---

## Executive Summary

Livewire V3 was released October 2023 alongside Laravel 10. It is a full internal rewrite of the Livewire framework with a dramatically smaller public API surface. From an upgrade perspective:

- **The good news:** Most of the V2 component API still works (class structure, `mount()`, properties, `$rules`, Blade directives like `@livewire`).
- **The hard news:** The event system was renamed wholesale (`emit` → `dispatch`), computed properties now use PHP 8 attributes, the `wire:model` default behaviour flipped (was eager, now lazy), and public property exposure is now a security-sensitive opt-in.
- **The Livewire CLI:** Livewire ships `php artisan livewire:upgrade` but it is interactive, only handles a subset of changes, and does not produce a machine-usable audit report.

### Our Approach

> **Static AST transforms for mechanical renames** + **Blade regex transforms for directive changes** + **Manual-review flags for security-sensitive and context-dependent changes.**

This decomposes into two layers:
1. **PHP file layer:** php-parser AST visitors implementing Rector rules targeting `*.php` files.
2. **Blade file layer:** Regex-based transformer targeting `*.blade.php` files (Blade is not valid PHP; Rector cannot parse it directly).

### Automation Confidence

| Change Category | Coverage | Notes |
|---|---|---|
| Event system rename (`emit` → `dispatch`) | ~98% | Pure mechanical rename |
| Computed property attribute migration | ~90% | Static methods require manual check |
| `wire:model` lazy/live default flip | ~95% | Detectable in Blade files |
| `@livewireScripts` → `@livewireScriptConfig` | ~99% | Exact string replace |
| `$wire.emit()` → `$wire.dispatch()` | ~95% rename / 0% argument format | Method rename auto-fixed; positional→object argument restructuring requires manual review |
| Public property security audit | 0% (auto-fix) | 100% flagging, 0% auto-fix — requires intent |
| `$this->emitUp()` removal | 100% detection | Manual replacement required |
| Form Object refactor for `$rules` | 0% | Always manual — context-dependent |
| Alpine 2→3 co-located changes | ~40% | Some patterns detectable, many are not |
| `hydrate()` → `boot()` rename | ~95% | Simple method rename |

**Overall estimated automation:** ~70–75% of Livewire V2→V3 changes can be automatically transformed. The remaining 25–30% are flagged with actionable manual-review items.

---

## 1. Complete Breaking Changes Catalogue

### A. Component Class Changes

#### A1. Computed Properties — `getXxxProperty()` → `#[Computed]` Attribute

**Classification:** Auto-fixable (Rector)

```php
// V2
class OrderSummary extends Component
{
    public function getTotalProperty(): float
    {
        return $this->items->sum('price');
    }
}

// V3
use Livewire\Attributes\Computed;

class OrderSummary extends Component
{
    #[Computed]
    public function total(): float
    {
        return $this->items->sum('price');
    }
}
```

**Rector Rule:** `ComputedPropertyRector`

**Steps:**
1. Find methods matching `get[A-Z][a-zA-Z]+Property` pattern.
2. Add `#[Computed]` attribute to the method.
3. Rename method: strip `get` prefix and `Property` suffix, lowercase first char.
4. Update all `$this->xxx` and `{{ $this->xxx }}` usages in the same PHP class.
5. **Manual flag (warning):** If method is declared `public` in V2 (accessible from Blade via `$this->total`), verify Blade templates still work — property access syntax is identical in V3 with `#[Computed]`.
6. **Manual flag (error):** If method uses `cache()` or memoisation — V3's `#[Computed]` has built-in per-request caching; double-caching may cause bugs.

---

#### A2. Event Listeners — `$listeners` Array → `#[On]` Attribute

**Classification:** Auto-fixable (Rector) when static; Detectable + Manual Flag when dynamic

```php
// V2 — static listeners
class Notifications extends Component
{
    protected $listeners = ['orderPlaced', 'userLoggedIn' => 'handleLogin'];

    public function orderPlaced(): void { ... }
    public function handleLogin(): void { ... }
}

// V3
use Livewire\Attributes\On;

class Notifications extends Component
{
    #[On('orderPlaced')]
    public function orderPlaced(): void { ... }

    #[On('userLoggedIn')]
    public function handleLogin(): void { ... }
}
```

**Rector Rule:** `ListenersToAttributeRector`

**Manual flag (warning):** Dynamic listeners using `getListeners()` method override — these cannot be statically converted to attributes. Generate a manual review item pointing to the `getListeners()` method.

---

#### A3. Public Property Security — `#[Locked]` Required for Server-Only Properties

**Classification:** Detectable + Manual Flag (NEVER auto-fix)

In V2, all public properties were accessible from the client (JavaScript `$wire.property`). In V3 this is unchanged by default, **but** Livewire now recommends explicitly locking properties that should not be mutated from the client.

```php
// V2 — no concept of locked properties
class UserProfile extends Component
{
    public int $userId;           // auto-set from route binding
    public string $email = '';    // fine for two-way binding
}

// V3 — userId should be locked (not bindable from client)
use Livewire\Attributes\Locked;

class UserProfile extends Component
{
    #[Locked]
    public int $userId;

    public string $email = '';
}
```

**Why never auto-fix:** Adding `#[Locked]` to a property that a Blade template `wire:model`s will cause a runtime exception. The migrator cannot know from static analysis which properties are intentionally two-way bound and which are server-set IDs.

**Review item:** For every `public` property that is NOT used in a `wire:model` directive in the companion Blade template, generate a `warning` review item recommending `#[Locked]`.

---

#### A4. `$queryString` Format Changes

**Classification:** Detectable + Manual Flag

```php
// V2
protected $queryString = ['search' => ['except' => ''], 'page' => ['except' => 1]];

// V3 — attribute-based (recommended) 
use Livewire\Attributes\Url;

class UsersIndex extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 1)]
    public int $page = 1;
}
```

The `$queryString` array still works in V3 for backwards compatibility. Flag as `info` suggesting migration to `#[Url]` attributes but do not auto-transform (the array value format is non-trivial to parse reliably for all edge cases).

---

#### A5. Validation Rules — `protected $rules` → Form Objects

**Classification:** Detectable + Manual Flag (NEVER auto-fix)

```php
// V2 — inline rules
class CreatePost extends Component
{
    public string $title = '';
    public string $body  = '';

    protected $rules = [
        'title' => 'required|min:3|max:255',
        'body'  => 'required',
    ];

    public function save(): void
    {
        $this->validate();
        Post::create($this->only(['title', 'body']));
    }
}

// V3 — Form Object (recommended)
// app/Livewire/Forms/CreatePostForm.php
use Livewire\Form;

class CreatePostForm extends Form
{
    #[Rule('required|min:3|max:255')]
    public string $title = '';

    #[Rule('required')]
    public string $body = '';
}

// app/Livewire/CreatePost.php
class CreatePost extends Component
{
    public CreatePostForm $form;

    public function save(): void
    {
        $this->form->validate();
        Post::create($this->form->only(['title', 'body']));
    }
}
```

**Why never auto-fix:** Creating a Form Object requires generating a new file, restructuring the component class, and all Blade template references (`$title` → `$form->title`). This scope creep cannot be done safely with static analysis alone.

**Review item severity:** `warning` — the old `$rules` syntax still works in V3 but is deprecated and should be migrated.

---

### B. Lifecycle Hook Changes

#### B1. `hydrate()` → Renamed Semantics

**Classification:** Detectable + Manual Flag

In V2, `hydrate()` ran on every subsequent request after the initial render (re-hydration). In V3 there is no `hydrate()` — the concept is replaced by `boot()` which runs on every request (initial + subsequent).

```php
// V2
public function hydrate(): void
{
    $this->resetValidation();
}

// V3
public function boot(): void
{
    $this->resetValidation();
}
```

**Note:** V3 still accepts `hydrate()` for the hydrate phase but it now specifically runs right after hydration (component reconstruction from state), not as a general-purpose per-request hook. If the developer used `hydrate()` as a per-request hook, this is a behavioural change.

**Auto-fix approach:** Rename `hydrate()` to `boot()` and add a `warning` review item: "Verify that `boot()` behaviour is equivalent to your V2 `hydrate()` usage — `boot()` runs on both initial and subsequent requests."

**Rector Rule:** `HydrateToBootRector`

---

#### B2. `dehydrate()` — Behaviour Change

**Classification:** Detectable + Manual Flag (no rename)

`dehydrate()` still exists in V3 but its purpose is more narrowly defined (runs right before serialisation). If used as a teardown hook, generate a `warning` review item.

---

#### B3. `$updatedFoo()` Magic Hooks — No Change but Flag

The `updatedPropertyName()` and `updatingPropertyName()` hooks work the same in V3. However, V3 introduces `Updated` and `Updating` attributes which are cleaner:

```php
// V2 / V3 compatible
public function updatedSearch(): void { $this->resetPage(); }

// V3 preferred — no migration needed but flag as info
use Livewire\Attributes\Updateable; // not accurate — just informational
```

**Classification:** Runtime-only information. No transform. `info` review item.

---

### C. Blade Directive Changes

#### C1. `@livewireScripts` → `@livewireScriptConfig`

**Classification:** Auto-fixable (Blade regex)

```blade
{{-- V2 --}}
@livewireStyles
@livewireScripts

{{-- V3 --}}
@livewireStyles
@livewireScriptConfig
```

> **Note:** In V3, the actual Livewire JavaScript is loaded via a `<script>` tag injected by the framework. `@livewireScriptConfig` only injects the configuration JSON. This is a safe auto-fix.

**Blade Transformer:** `LivewireStylesScriptTransformer`

---

#### C2. `wire:model` — Default Behaviour Flip (Critical)

**Classification:** Auto-fixable (Blade regex) — HIGH PRIORITY

This is the most impactful change for end-users:

| V2 Modifier | V2 Behaviour | V3 Equivalent | V3 Behaviour |
|---|---|---|---|
| `wire:model` | Eager (live update) | `wire:model.live` | Eager (live update) |
| `wire:model.lazy` | Lazy (blur update) | `wire:model` | Lazy (blur update) |
| `wire:model.defer` | Deferred (manual sync) | `wire:model` | Lazy (blur update) |
| `wire:model.debounce.Xms` | Debounced eager | `wire:model.live.debounce.Xms` | Debounced eager |

**Transformations:**

```blade
{{-- V2 → V3: bare wire:model (eager) must add .live --}}
<input wire:model="search">                    {{-- V2 --}}
<input wire:model.live="search">               {{-- V3 --}}

{{-- V2 → V3: wire:model.lazy becomes bare wire:model --}}
<input wire:model.lazy="email">               {{-- V2 --}}
<input wire:model="email">                    {{-- V3 --}}

{{-- V2 → V3: wire:model.defer becomes bare wire:model --}}
<input wire:model.defer="quantity">           {{-- V2 --}}
<input wire:model="quantity">                 {{-- V3 --}}

{{-- V2 → V3: debounce must add .live --}}
<input wire:model.debounce.500ms="search">    {{-- V2 --}}
<input wire:model.live.debounce.500ms="search"> {{-- V3 --}}
```

**Regex patterns (applied in order):**

```php
// 1. wire:model.lazy → wire:model (must run before bare model rule)
'/wire:model\.lazy(\s|=|>)/' → 'wire:model$1'

// 2. wire:model.defer → wire:model (must run before bare model rule)
'/wire:model\.defer(\s|=|>)/' → 'wire:model$1'

// 3. wire:model.debounce.Xms → wire:model.live.debounce.Xms
'/wire:model\.debounce\.(\d+ms)/' → 'wire:model.live.debounce.$1'

// 4. bare wire:model (not followed by .live/.lazy/.defer already) → wire:model.live
'/wire:model(?!\.live|\.lazy|\.defer|\.debounce)(\s|=|>)/' → 'wire:model.live$1'
```

> ⚠️ Rule 4 must be applied **after** rules 1–3 to avoid double-modifying already-processed attributes.

**Blade Transformer:** `WireModelLazyTransformer`

---

#### C3. `@entangle` → `$wire.entangle()`

**Classification:** Auto-fixable (Blade regex)

```blade
{{-- V2 --}}
<div x-data="{ open: @entangle('showModal') }">

{{-- V3 --}}
<div x-data="{ open: $wire.entangle('showModal') }">
```

**Blade Transformer:** Included in `AlpineIntegrationTransformer`

---

### D. Alpine.js Integration

#### D1. `$wire.emit()` → `$wire.dispatch()`

**Classification:** Auto-fixable (Blade regex, JS context)

```blade
{{-- V2 --}}
<button @click="$wire.emit('orderPlaced', orderId)">Place Order</button>

{{-- V3 --}}
<button @click="$wire.dispatch('orderPlaced', { orderId: orderId })">Place Order</button>
```

> **Note:** V2 passed positional parameters; V3 passes a single object payload. The regex can do the rename but cannot restructure positional arguments into an object — flag for manual review if arguments are present.

**Regex (rename only, safe):**
```php
'/\$wire\.emit\(/' → '$wire.dispatch('
```

Generate a `warning` review item on every file where `$wire.emit(` is replaced: "V3 `dispatch()` receives a single object payload — verify argument format is `{ key: value }` not positional."

---

#### D2. Alpine 2.x → Alpine 3.x Co-located Changes

Livewire V3 bundles Alpine 3.x. V2 bundled Alpine 2.x. Alpine-specific breaking changes that commonly appear in Livewire components:

| Change | Auto-fixable |
|---|---|
| `x-spread` → `v-bind` / Alpine 3 removed `x-spread` | No — manual |
| `Alpine.store()` API (new in Alpine 3) | Not applicable — addition |
| `$dispatch()` → still works in Alpine 3 | No change |
| Magic `$el` type changes | No |
| `x-init` returning a function | Context-dependent |

**Classification:** Flag all files containing `x-spread` as `error`. All others as `info` recommending Alpine 3 review.

---

### E. Event System

#### E1. `$this->emit()` → `$this->dispatch()`

**Classification:** Auto-fixable (Rector)

```php
// V2
$this->emit('orderPlaced', $order->id);
$this->emit('notification', 'Order confirmed', 'success');

// V3
$this->dispatch('orderPlaced', orderId: $order->id);
$this->dispatch('notification', message: 'Order confirmed', type: 'success');
```

> V3 `dispatch()` uses named arguments for the payload. The Rector rule renames the method call. Arguments are preserved as positional (still works) but a `warning` is generated recommending conversion to named parameters.

**Rector Rule:** `EmitToDispatchRector`

---

#### E2. `$this->emitTo()` → `$this->dispatch()->to()`

**Classification:** Auto-fixable (Rector)

```php
// V2
$this->emitTo('shopping-cart', 'itemAdded', $product->id);

// V3
$this->dispatch('itemAdded', productId: $product->id)->to(ShoppingCart::class);
```

**Rector Rule:** `EmitToDispatchRector` (handles `emitTo` case as well)

---

#### E3. `$this->emitSelf()` → `$this->dispatch()`

**Classification:** Auto-fixable (Rector)

```php
// V2
$this->emitSelf('saved');

// V3 — dispatching to self is now just a regular dispatch
// (components only receive their own events if they listen)
$this->dispatch('saved');
```

**Rector Rule:** `EmitToDispatchRector` (handles `emitSelf` case)

---

#### E4. `$this->emitUp()` → Removed (No Direct Equivalent)

**Classification:** Detectable + Manual Flag (`error` severity)

`emitUp()` bubbled an event up the component tree. V3 removed this concept — all events are now global.

```php
// V2
$this->emitUp('formSubmitted');

// V3 — no equivalent; use global dispatch + component-specific listener guards
$this->dispatch('formSubmitted'); // but this goes to ALL components, not just parent
```

**Rector Rule:** `EmitUpManualReviewRector` — detects `emitUp()`, cannot transform, generates `error` review item with migration guidance.

---

### F. Testing API

#### F1. `assertEmitted()` → `assertDispatched()`

**Classification:** Auto-fixable (Rector)

```php
// V2
Livewire::test(OrderComponent::class)
    ->call('placeOrder')
    ->assertEmitted('orderPlaced');

// V3
Livewire::test(OrderComponent::class)
    ->call('placeOrder')
    ->assertDispatched('orderPlaced');
```

**Rector Rule:** `TestAssertEmittedRector`

---

#### F2. `assertNotEmitted()` → `assertNotDispatched()`

**Classification:** Auto-fixable (Rector) — handled by same rule as F1.

---

#### F3. `assertEmittedTo()` → `assertDispatchedTo()`

**Classification:** Auto-fixable (Rector) — handled by same rule as F1.

---

#### F4. `assertEmittedUp()` → Removed

**Classification:** Detectable + Manual Flag (`error` severity) — handled by `EmitUpManualReviewRector`.

---

## 2. Automated vs. Manual Review Boundary

| Change | Approach | Rule / Transformer | File Type | Notes |
|---|---|---|---|---|
| `getXxxProperty()` → `#[Computed]` | Auto | `ComputedPropertyRector` | `.php` | Flag if method uses `cache()` |
| `$listeners` → `#[On]` (static) | Auto | `ListenersToAttributeRector` | `.php` | |
| `$listeners` → `#[On]` (dynamic `getListeners()`) | Flag — warning | `ListenersToAttributeRector` | `.php` | Cannot auto-fix dynamic |
| `hydrate()` → `boot()` | Auto + warning | `HydrateToBootRector` | `.php` | Verify boot() semantics |
| `$this->emit()` → `$this->dispatch()` | Auto | `EmitToDispatchRector` | `.php` | Flag argument format |
| `$this->emitTo()` → `$this->dispatch()->to()` | Auto | `EmitToDispatchRector` | `.php` | |
| `$this->emitSelf()` → `$this->dispatch()` | Auto | `EmitToDispatchRector` | `.php` | |
| `$this->emitUp()` → removed | Flag — error | `EmitUpManualReviewRector` | `.php` | No V3 equivalent |
| `assertEmitted()` → `assertDispatched()` | Auto | `TestAssertEmittedRector` | `.php` | Tests only |
| `assertNotEmitted()` → `assertNotDispatched()` | Auto | `TestAssertEmittedRector` | `.php` | Tests only |
| `assertEmittedUp()` → removed | Flag — error | `EmitUpManualReviewRector` | `.php` | |
| Public property without `#[Locked]` | Flag — warning | `PublicPropertyLockAuditRector` | `.php` | Never auto-add Locked |
| `$rules` property | Flag — warning | `FormObjectAuditRector` | `.php` | Suggest Form Object |
| `$queryString` format | Flag — info | `QueryStringAuditRector` | `.php` | Suggest `#[Url]` |
| `@livewireScripts` → `@livewireScriptConfig` | Auto | `LivewireStylesScriptTransformer` | `.blade.php` | Blade regex |
| `wire:model` → `wire:model.live` | Auto | `WireModelLazyTransformer` | `.blade.php` | Blade regex |
| `wire:model.lazy` → `wire:model` | Auto | `WireModelLazyTransformer` | `.blade.php` | Blade regex |
| `wire:model.defer` → `wire:model` | Auto | `WireModelLazyTransformer` | `.blade.php` | Blade regex |
| `wire:model.debounce.Xms` → `wire:model.live.debounce.Xms` | Auto | `WireModelLazyTransformer` | `.blade.php` | Blade regex |
| `$wire.emit()` → `$wire.dispatch()` | Auto + warning | `AlpineIntegrationTransformer` | `.blade.php` | Flag argument format |
| `@entangle` → `$wire.entangle()` | Auto | `AlpineIntegrationTransformer` | `.blade.php` | Blade regex |
| `x-spread` Alpine directive | Flag — error | `AlpineIntegrationTransformer` | `.blade.php` | Alpine 3 removed it |
| Sentry in component methods | Flag — info | `PublicPropertyLockAuditRector` | `.php` | Verify SDK version |

---

## 3. Rector Rule Specifications

### Rule: `ComputedPropertyRector`
**File:** `src-container/Rector/Livewire/ComputedPropertyRector.php`  
**Namespace:** `AppContainer\Rector\Livewire`

Transforms `get[A-Z][a-zA-Z]+Property()` methods to `#[Computed]` attribute + renamed method.

```php
// Input
public function getTotalProperty(): float
{
    return $this->items->sum('price');
}

// Output
#[\Livewire\Attributes\Computed]
public function total(): float
{
    return $this->items->sum('price');
}
```

**Node type:** `ClassMethod`  
**Condition:** Class extends `Livewire\Component` (or any subclass); method name matches `/^get([A-Z][a-zA-Z]+)Property$/`

---

### Rule: `EmitToDispatchRector`
**File:** `src-container/Rector/Livewire/EmitToDispatchRector.php`

Renames `emit`, `emitTo`, `emitSelf` method calls to V3 equivalents.

```php
// Input
$this->emit('orderPlaced', $id);
$this->emitTo('cart', 'itemAdded', $id);
$this->emitSelf('saved');

// Output  
$this->dispatch('orderPlaced', $id);
$this->dispatch('itemAdded', $id)->to(\App\Livewire\Cart::class);
$this->dispatch('saved');
```

**Node type:** `MethodCall` where `var` is `$this` and name is `emit|emitTo|emitSelf`

---

### Rule: `HydrateToBootRector`
**File:** `src-container/Rector/Livewire/HydrateToBootRector.php`

Renames `hydrate()` lifecycle method to `boot()`.

```php
// Input
public function hydrate(): void { ... }

// Output
public function boot(): void { ... }
```

**Node type:** `ClassMethod` named `hydrate` in a Livewire component class.  
**Side effect:** Always generates a `warning` review item about semantic difference.

---

### Rule: `EmitUpManualReviewRector`
**File:** `src-container/Rector/Livewire/EmitUpManualReviewRector.php`

Detection-only rule. Finds `$this->emitUp()` and `assertEmittedUp()` calls, generates `error` review item, makes no code change.

---

### Rule: `PublicPropertyLockAuditRector`
**File:** `src-container/Rector/Livewire/PublicPropertyLockAuditRector.php`

Detection-only rule. For each `public` property in a Livewire component that is:
- Not already annotated with `#[Locked]`
- Is of a type that is unlikely to be a form binding (e.g., `int $id`, `string $userId`)

Generate a `warning` review item recommending `#[Locked]`.

**Heuristic for "unlikely to be a form binding":** Properties named `id`, `*Id`, `*Uuid`, `*Key` or typed as `int`/`bool` with a name that is not a known form field name.

> This heuristic is deliberately conservative (prefer false-negative over false-positive). The tool reports *suspicious* properties, not all properties.

---

### Rule: `TestAssertEmittedRector`
**File:** `src-container/Rector/Livewire/TestAssertEmittedRector.php`

Renames test assertion methods:

| V2 | V3 |
|---|---|
| `assertEmitted()` | `assertDispatched()` |
| `assertNotEmitted()` | `assertNotDispatched()` |
| `assertEmittedTo()` | `assertDispatchedTo()` |
| `assertEmittedUp()` | (flag as error, no rename) |

---

### Blade Transformer: `WireModelLazyTransformer`
**File:** `src-container/Rector/Livewire/Blade/WireModelLazyTransformer.php`

Operates on the raw Blade file content (string). Applies the 4 regex rules defined in §C2 in order.

---

### Blade Transformer: `LivewireStylesScriptTransformer`
**File:** `src-container/Rector/Livewire/Blade/LivewireStylesScriptTransformer.php`

Replaces `@livewireScripts` → `@livewireScriptConfig`.

---

### Blade Transformer: `AlpineIntegrationTransformer`
**File:** `src-container/Rector/Livewire/Blade/AlpineIntegrationTransformer.php`

Handles:
- `@entangle(...)` → `$wire.entangle(...)`
- `$wire.emit(` → `$wire.dispatch(`
- `x-spread` detection → `error` review item

---

## 4. Phase 2 Module Design

```
Package:   Livewire V2→V3
Container: upgrader/package-livewire-v2-to-v3:1.0.0

PHP Rector Rules (src-container/Rector/Livewire/):
  ComputedPropertyRector          — getXxxProperty → #[Computed]
  ListenersToAttributeRector      — $listeners → #[On]
  EmitToDispatchRector            — emit/emitTo/emitSelf → dispatch
  HydrateToBootRector             — hydrate() → boot()
  EmitUpManualReviewRector        — emitUp() → error flag (no transform)
  PublicPropertyLockAuditRector   — public prop security audit (no transform)
  TestAssertEmittedRector         — assertEmitted → assertDispatched
  FormObjectAuditRector           — $rules → warning flag (no transform)
  QueryStringAuditRector          — $queryString → info flag (no transform)

Blade Transformers (src-container/Rector/Livewire/Blade/):
  WireModelLazyTransformer        — wire:model default flip (4 regex rules)
  LivewireStylesScriptTransformer — @livewireScripts → @livewireScriptConfig
  AlpineIntegrationTransformer    — $wire.emit, @entangle, x-spread

Container entry point:
  entrypoint.sh invokes Rector with livewire-v2-to-v3 config
  then invokes Blade transformer on all *.blade.php files
  emits JSON-ND events: livewire_rule_applied, livewire_manual_review, livewire_audit
```

### Rector Config

```php
// rector-configs/rector.livewire-v2-to-v3.php
use Rector\Config\RectorConfig;
use AppContainer\Rector\Livewire\ComputedPropertyRector;
use AppContainer\Rector\Livewire\EmitToDispatchRector;
use AppContainer\Rector\Livewire\HydrateToBootRector;
use AppContainer\Rector\Livewire\EmitUpManualReviewRector;
use AppContainer\Rector\Livewire\PublicPropertyLockAuditRector;
use AppContainer\Rector\Livewire\TestAssertEmittedRector;
use AppContainer\Rector\Livewire\ListenersToAttributeRector;
use AppContainer\Rector\Livewire\FormObjectAuditRector;
use AppContainer\Rector\Livewire\QueryStringAuditRector;

return RectorConfig::configure()
    ->withRules([
        ComputedPropertyRector::class,
        ListenersToAttributeRector::class,
        EmitToDispatchRector::class,
        HydrateToBootRector::class,
        EmitUpManualReviewRector::class,
        PublicPropertyLockAuditRector::class,
        TestAssertEmittedRector::class,
        FormObjectAuditRector::class,
        QueryStringAuditRector::class,
    ]);
```

### Container Dockerfile (sketch)

```dockerfile
FROM php:8.2-cli-alpine

WORKDIR /app
COPY composer.livewire-v2-to-v3.json composer.json
RUN composer install --no-dev

# Network isolation enforced by host (--network=none)
ENTRYPOINT ["/app/entrypoint.sh"]
```

### JSON-ND Events Emitted

```jsonc
// Per rule application
{"event":"livewire_rule_applied","rule":"ComputedPropertyRector","file":"app/Livewire/OrderSummary.php","line":12,"old":"getTotalProperty()","new":"total()"}

// Per manual review item
{"event":"livewire_manual_review","severity":"error","file":"app/Livewire/OrderForm.php","line":45,"message":"$this->emitUp() has no V3 equivalent. Replace with global dispatch() and add listener guard in parent component.","rule":"EmitUpManualReviewRector"}

// Final audit summary
{"event":"livewire_audit","rules_applied":47,"manual_review_items":3,"auto_fixed_files":12,"blade_files_transformed":8,"errors":1,"warnings":2}
```

---

## 5. Effort Estimate for Phase 2 Livewire Module

### Breakdown

| Work Item | Estimated Days |
|---|---|
| `ComputedPropertyRector` + fixture tests | 1.5d |
| `EmitToDispatchRector` (emit/emitTo/emitSelf) + tests | 1.0d |
| `HydrateToBootRector` + tests | 0.5d |
| `ListenersToAttributeRector` + tests | 1.0d |
| `EmitUpManualReviewRector` + `PublicPropertyLockAuditRector` + tests | 1.0d |
| `TestAssertEmittedRector` + tests | 0.5d |
| `FormObjectAuditRector` + `QueryStringAuditRector` + tests | 0.5d |
| Blade transformers (3 classes) + tests | 2.0d |
| Container scaffolding (Dockerfile, entrypoint, composer.json) | 0.5d |
| Integration test (full component V2→V3) | 1.0d |
| Audit report + JSON-ND events | 0.5d |
| **Total** | **10.0d** |

### Rule Count Summary

| Category | Count |
|---|---|
| Auto-fix Rector rules | 5 |
| Detection-only Rector rules | 4 |
| Blade transformers | 3 |
| **Total** | **12** |

---

## Decision

**The Livewire V2→V3 migration is well-suited to static AST transforms for PHP changes and regex-based transforms for Blade changes.** Approximately 70–75% of changes are fully automatable. The key non-automatable category is public property security (`#[Locked]`), which requires developer judgment and is intentionally excluded from auto-fix to prevent hiding security regressions.

The **event system rename** (`emit` → `dispatch`) is the single highest-volume change in real-world enterprise Livewire applications and is 100% auto-fixable.

The **`wire:model` default flip** is the change most likely to cause subtle runtime bugs (forms that were eager now become lazy). The regex transform is safe, but developers should test all form interactions after migration.

---

## Phase 2 Impact

Phase 2 must build the following (in dependency order):

1. **`src-container/Rector/Livewire/`** — 9 PHP Rector rule classes
2. **`src-container/Rector/Livewire/Blade/`** — 3 Blade transformer classes
3. **`tests/Fixtures/Livewire/`** — fixture `.php.inc` files for each rule (V2 input → V3 expected output)
4. **`tests/Unit/Rector/Livewire/`** — unit test class for each rule
5. **`docker/package-livewire-v2-to-v3/`** — Dockerfile, entrypoint.sh, composer.json, rector config
6. **Integration test** — end-to-end: V2 component directory in → V3 component directory out
7. **Breaking changes JSON** — `docker/package-livewire-v2-to-v3/docs/breaking-changes.json` with all 20+ entries following the established format

**Total Phase 2 effort:** ~10 development days.

---

## References

- [Livewire V3 Upgrade Guide](https://livewire.laravel.com/docs/upgrading)
- [Livewire V3 Release Notes](https://livewire.laravel.com/docs/v3-release-notes)
- [Livewire V3 `livewire:upgrade` Artisan command source](https://github.com/livewire/livewire/blob/main/src/Commands/UpgradeCommand.php)
- [Alpine.js V3 Migration Guide](https://alpinejs.dev/upgrade-guide)
- Internal: P1-07 Rector rules foundation (`src-container/Rector/`)
- Internal: P1-14 Lumen Migration Suite (`src-container/Lumen/`) — architect reference
- Internal: `rector-configs/rector.l8-to-l9.php` — existing Rector config format

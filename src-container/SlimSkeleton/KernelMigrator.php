<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Error as ParserError;

/**
 * Migrates `app/Http/Kernel.php` from L10 to the L11 `bootstrap/app.php`
 * `->withMiddleware()` callback (Design spike §1, TRD-P2SLIM-001).
 *
 * Extracts:
 *   - $middleware          → appended global middleware (diff against L11 defaults)
 *   - $middlewareGroups    → per-group deltas (delta against L11 defaults)
 *   - $middlewareAliases   → non-default aliases
 *   - $middlewarePriority  → full list if overridden
 *   - TrustProxies.php     → trustProxies() parameters
 *
 * Files with non-standard logic (custom handle/terminate methods, unknown properties)
 * are backed up as `.laravel-backup` and flagged as manual review items.
 */
final class KernelMigrator
{
    /**
     * L11 default global middleware — never re-emit these.
     * @var string[]
     */
    private const L11_DEFAULT_GLOBAL = [
        'Illuminate\\Foundation\\Http\\Middleware\\InvokeDeferredCallbacks',
        'Illuminate\\Http\\Middleware\\TrustHosts',
        'Illuminate\\Http\\Middleware\\TrustProxies',
        'Illuminate\\Http\\Middleware\\HandleCors',
        'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestsDuringMaintenance',
        'Illuminate\\Http\\Middleware\\ValidatePostSize',
        'Illuminate\\Foundation\\Http\\Middleware\\TrimStrings',
        'Illuminate\\Foundation\\Http\\Middleware\\ConvertEmptyStringsToNull',
        // short-form equivalents (without leading backslash)
        '\\Illuminate\\Foundation\\Http\\Middleware\\InvokeDeferredCallbacks',
        '\\Illuminate\\Http\\Middleware\\TrustHosts',
        '\\Illuminate\\Http\\Middleware\\TrustProxies',
        '\\Illuminate\\Http\\Middleware\\HandleCors',
        '\\Illuminate\\Foundation\\Http\\Middleware\\PreventRequestsDuringMaintenance',
        '\\Illuminate\\Http\\Middleware\\ValidatePostSize',
        '\\Illuminate\\Foundation\\Http\\Middleware\\TrimStrings',
        '\\Illuminate\\Foundation\\Http\\Middleware\\ConvertEmptyStringsToNull',
    ];

    /**
     * L11 default web group entries — strip these from web delta.
     * @var string[]
     */
    private const L11_DEFAULT_WEB = [
        'Illuminate\\Cookie\\Middleware\\EncryptCookies',
        'Illuminate\\Cookie\\Middleware\\AddQueuedCookiesToResponse',
        'Illuminate\\Session\\Middleware\\StartSession',
        'Illuminate\\View\\Middleware\\ShareErrorsFromSession',
        'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken',
        'Illuminate\\Routing\\Middleware\\SubstituteBindings',
        'App\\Http\\Middleware\\EncryptCookies',
        'App\\Http\\Middleware\\VerifyCsrfToken',
        '\\App\\Http\\Middleware\\EncryptCookies',
        '\\App\\Http\\Middleware\\VerifyCsrfToken',
    ];

    /**
     * L11 default api group entries.
     * @var string[]
     */
    private const L11_DEFAULT_API = [
        'Illuminate\\Routing\\Middleware\\SubstituteBindings',
    ];

    /**
     * L11 default middleware aliases — never re-emit these.
     * @var string[]
     */
    private const L11_DEFAULT_ALIASES = [
        'auth',
        'auth.basic',
        'auth.session',
        'cache.headers',
        'can',
        'guest',
        'password.confirm',
        'precognitive',
        'signed',
        'throttle',
        'verified',
    ];

    /**
     * Known property names in the base Kernel class.
     * Custom properties beyond these warrant manual review.
     * @var string[]
     */
    public const KNOWN_KERNEL_PROPERTIES = [
        'middleware',
        'middlewareGroups',
        'middlewareAliases',
        'routeMiddleware',
        'middlewarePriority',
    ];

    public function migrate(string $workspacePath): KernelMigrationResult
    {
        $kernelFile = $workspacePath . '/app/Http/Kernel.php';

        if (!file_exists($kernelFile)) {
            return KernelMigrationResult::noKernelFile();
        }

        $code = file_get_contents($kernelFile);
        if ($code === false) {
            return KernelMigrationResult::failure("Cannot read {$kernelFile}");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast    = $parser->parse($code);
        } catch (ParserError $e) {
            return KernelMigrationResult::failure("Parse error in {$kernelFile}: {$e->getMessage()}");
        }

        if ($ast === null) {
            return KernelMigrationResult::failure("Empty AST from {$kernelFile}");
        }

        $visitor   = new KernelVisitor();
        $traverser = new NodeTraverser();
        // NameResolver is required: earlier hops run Rector with withImportNames(),
        // which converts FQCNs to short imports. Without NameResolver, toString()
        // on a Node\Name returns just the short name (e.g. "HandleCors" instead of
        // "Illuminate\Http\Middleware\HandleCors"), producing invalid bootstrap/app.php.
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $manualReviewItems = [];
        $backupFiles       = [];

        // Detect non-standard logic requiring backup
        $needsBackup = $visitor->hasCustomHandle
            || $visitor->hasCustomTerminate
            || $visitor->hasUnknownProperties;

        if ($needsBackup) {
            $backupFile = $kernelFile . '.laravel-backup';
            if (!file_exists($backupFile)) {
                copy($kernelFile, $backupFile);
            }
            $backupFiles[] = $backupFile;
        }

        if ($visitor->hasCustomHandle) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::kernel(
                $kernelFile,
                0,
                'Custom handle() method found in Kernel.php — must be extracted to a custom middleware class.',
                'error',
                'Create a new middleware class and register it via $middleware->prepend() in bootstrap/app.php.'
            );
        }

        if ($visitor->hasCustomTerminate) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::kernel(
                $kernelFile,
                0,
                'Custom terminate() method found in Kernel.php — must be extracted to a terminable middleware.',
                'warning',
                'Implement TerminableMiddleware interface on a new middleware class.'
            );
        }

        if ($visitor->hasConfigureRateLimiting) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::kernel(
                $kernelFile,
                0,
                'configureRateLimiting() found in Kernel.php — should move to AppServiceProvider::boot().',
                'info',
                'Extract rate limiter definitions to App\\Providers\\AppServiceProvider::boot().'
            );
        }

        foreach ($visitor->unknownProperties as $prop) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::kernel(
                $kernelFile,
                0,
                "Non-standard Kernel property \${$prop} cannot be automatically migrated.",
                'warning',
                'Review whether this property is still needed and move to the appropriate L11 location.'
            );
        }

        // Diff global middleware against L11 defaults
        $appendedGlobal = array_values(array_filter(
            $visitor->globalMiddleware,
            fn(string $m) => !$this->isL11DefaultGlobal($m)
        ));

        // Diff middleware group entries against L11 defaults
        $groupDeltas = [];
        foreach ($visitor->middlewareGroups as $group => $entries) {
            $defaults = match ($group) {
                'web' => self::L11_DEFAULT_WEB,
                'api' => self::L11_DEFAULT_API,
                default => [],
            };
            $delta = array_values(array_filter(
                $entries,
                fn(string $e) => !$this->isInDefaultList($e, $defaults)
            ));
            if ($delta !== []) {
                $groupDeltas[$group] = $delta;
            }
        }

        // Diff middleware aliases against L11 defaults
        $customAliases = array_filter(
            $visitor->middlewareAliases,
            fn(string $key) => !in_array($key, self::L11_DEFAULT_ALIASES, true),
            ARRAY_FILTER_USE_KEY
        );

        // TrustProxies
        $trustProxiesAt      = null;
        $trustProxiesHeaders = null;
        $trustProxiesFile    = $workspacePath . '/app/Http/Middleware/TrustProxies.php';
        if (file_exists($trustProxiesFile)) {
            [$trustProxiesAt, $trustProxiesHeaders] = $this->parseTrustProxies($trustProxiesFile);
        }

        $this->emitEvent('slim_kernel_migrated', [
            'workspace'                => $workspacePath,
            'appended_global_count'    => count($appendedGlobal),
            'group_delta_count'        => count($groupDeltas),
            'alias_count'              => count($customAliases),
            'priority_overridden'      => $visitor->middlewarePriority !== [],
            'needs_backup'             => $needsBackup,
            'manual_review_count'      => count($manualReviewItems),
        ]);

        return KernelMigrationResult::success(
            appendedGlobalMiddleware: $appendedGlobal,
            middlewareGroupDeltas: $groupDeltas,
            middlewareAliases: $customAliases,
            middlewarePriority: $visitor->middlewarePriority,
            trustProxiesAt: $trustProxiesAt,
            trustProxiesHeaders: $trustProxiesHeaders,
            manualReviewItems: $manualReviewItems,
            backupFiles: $backupFiles,
        );
    }

    private function isL11DefaultGlobal(string $fqcn): bool
    {
        $normalised = ltrim($fqcn, '\\');
        foreach (self::L11_DEFAULT_GLOBAL as $default) {
            if (ltrim($default, '\\') === $normalised) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $defaults
     */
    private function isInDefaultList(string $fqcn, array $defaults): bool
    {
        $normalised = ltrim($fqcn, '\\');
        foreach ($defaults as $default) {
            if (ltrim($default, '\\') === $normalised) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{string|null, int|null}
     */
    private function parseTrustProxies(string $file): array
    {
        $code = file_get_contents($file);
        if ($code === false) {
            return [null, null];
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast    = $parser->parse($code);
        } catch (ParserError) {
            return [null, null];
        }

        if ($ast === null) {
            return [null, null];
        }

        $visitor   = new TrustProxiesVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return [$visitor->proxies, $visitor->headers];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $event, array $data): void
    {
        $payload = array_merge(['event' => $event], $data);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

/**
 * @internal
 */
final class KernelVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $globalMiddleware = [];

    /** @var array<string, string[]> */
    public array $middlewareGroups = [];

    /** @var array<string, string> */
    public array $middlewareAliases = [];

    /** @var string[] */
    public array $middlewarePriority = [];

    public bool $hasCustomHandle           = false;
    public bool $hasCustomTerminate        = false;
    public bool $hasConfigureRateLimiting  = false;
    public bool $hasUnknownProperties      = false;

    /** @var string[] */
    public array $unknownProperties = [];

    public function leaveNode(Node $node): null
    {
        if (!$node instanceof Class_) {
            return null;
        }

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    if (!in_array($name, KernelMigrator::KNOWN_KERNEL_PROPERTIES, true)) {
                        $this->hasUnknownProperties = true;
                        $this->unknownProperties[]  = $name;
                        continue;
                    }
                    $this->extractPropertyValue($name, $prop->default);
                }
            }

            if ($stmt instanceof ClassMethod) {
                $methodName = $stmt->name->toString();
                if ($methodName === 'handle') {
                    $this->hasCustomHandle = true;
                }
                if ($methodName === 'terminate') {
                    $this->hasCustomTerminate = true;
                }
                if ($methodName === 'configureRateLimiting') {
                    $this->hasConfigureRateLimiting = true;
                }
            }
        }

        return null;
    }

    private function extractPropertyValue(string $name, Node|null $default): void
    {
        if (!$default instanceof Array_) {
            return;
        }

        $strings = $this->extractArrayStrings($default);

        match ($name) {
            'middleware'                     => $this->globalMiddleware    = $strings,
            'middlewareGroups'               => $this->middlewareGroups    = $this->extractGroupMap($default),
            'middlewareAliases', 'routeMiddleware' => $this->middlewareAliases = $this->extractAliasMap($default),
            'middlewarePriority'             => $this->middlewarePriority  = $strings,
            default                          => null,
        };
    }

    /**
     * @return string[]
     */
    private function extractArrayStrings(Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }
            $val = $this->resolveClassConst($item->value);
            if ($val !== null) {
                $result[] = $val;
            }
        }
        return $result;
    }

    /**
     * @return array<string, string[]>
     */
    private function extractGroupMap(Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem || $item->key === null) {
                continue;
            }
            $key = $this->resolveString($item->key);
            if ($key === null || !$item->value instanceof Array_) {
                continue;
            }
            $result[$key] = $this->extractArrayStrings($item->value);
        }
        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function extractAliasMap(Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem || $item->key === null) {
                continue;
            }
            $key = $this->resolveString($item->key);
            $val = $this->resolveClassConst($item->value) ?? $this->resolveString($item->value);
            if ($key !== null && $val !== null) {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    private function resolveClassConst(Node $node): string|null
    {
        if ($node instanceof Node\Expr\ClassConstFetch) {
            $class = $node->class;
            if ($class instanceof Node\Name) {
                // After NameResolver, the resolved FQCN is stored as the 'resolvedName'
                // attribute. Use it if available; fall back to the literal node text.
                $resolved = $class->getAttribute('resolvedName');
                return $resolved instanceof Node\Name\FullyQualified
                    ? $resolved->toString()
                    : $class->toString();
            }
        }
        return null;
    }

    private function resolveString(Node $node): string|null
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        return null;
    }
}

/**
 * @internal
 * Extracts $proxies and $headers from a TrustProxies middleware class.
 */
final class TrustProxiesVisitor extends NodeVisitorAbstract
{
    public string|null $proxies = null;
    public int|null $headers    = null;

    public function enterNode(Node $node): null
    {
        if (!$node instanceof Property) {
            return null;
        }

        foreach ($node->props as $prop) {
            $name = $prop->name->toString();
            if ($name === 'proxies' && $prop->default !== null) {
                $this->proxies = $this->extractProxies($prop->default);
            }
            if ($name === 'headers' && $prop->default !== null) {
                $this->headers = $this->extractHeaders($prop->default);
            }
        }

        return null;
    }

    private function extractProxies(Node $node): string|null
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Array_) {
            $items = [];
            foreach ($node->items as $item) {
                if ($item instanceof ArrayItem && $item->value instanceof Node\Scalar\String_) {
                    $items[] = $item->value->value;
                }
            }
            return $items !== [] ? implode(',', $items) : null;
        }
        return null;
    }

    private function extractHeaders(Node $node): int|null
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }
        return null;
    }
}

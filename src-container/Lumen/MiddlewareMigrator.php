<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Arg;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Error as ParserError;

/**
 * Migrates Lumen middleware registrations to Laravel's `app/Http/Kernel.php`.
 *
 * Lumen patterns (bootstrap/app.php):
 *   $app->middleware([App\Http\Middleware\ExampleMiddleware::class]);
 *   $app->routeMiddleware(['auth' => App\Http\Middleware\Authenticate::class]);
 *
 * Laravel 9 target:
 *   $middleware[]      → $middleware array in App\Http\Kernel
 *   $routeMiddleware[] → $routeMiddleware array in App\Http\Kernel (or $middlewareAliases in L10+)
 *
 * Emits `lumen_middleware_migrated` JSON-ND event.
 */
final class MiddlewareMigrator
{
    public function migrate(string $workspacePath, string $targetPath): MiddlewareMigrationResult
    {
        $bootstrapFile = $workspacePath . '/bootstrap/app.php';
        if (!file_exists($bootstrapFile)) {
            return MiddlewareMigrationResult::success([], []);
        }

        $code = file_get_contents($bootstrapFile);
        if ($code === false) {
            return MiddlewareMigrationResult::failure("Cannot read bootstrap/app.php");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast = $parser->parse($code);
        } catch (ParserError $e) {
            return MiddlewareMigrationResult::failure("Parse error: {$e->getMessage()}");
        }

        if ($ast === null) {
            return MiddlewareMigrationResult::success([], []);
        }

        $collector = new MiddlewareCallCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        $manualReview = [];

        if ($collector->globalMiddleware !== [] || $collector->routeMiddleware !== []) {
            $kernelFile = $targetPath . '/app/Http/Kernel.php';
            if (file_exists($kernelFile)) {
                $this->patchKernel($kernelFile, $collector->globalMiddleware, $collector->routeMiddleware);
            } else {
                $manualReview[] = LumenManualReviewItem::middleware(
                    $bootstrapFile,
                    0,
                    "app/Http/Kernel.php not found in scaffold — middleware could not be auto-registered.",
                    "Manually add middleware to app/Http/Kernel.php.",
                );
            }
        }

        $this->emitManualReviewEvents($manualReview + $collector->manualReviewItems);

        $this->emitEvent('lumen_middleware_migrated', [
            'global_count' => count($collector->globalMiddleware),
            'route_count'  => count($collector->routeMiddleware),
        ]);

        return MiddlewareMigrationResult::success(
            $collector->globalMiddleware,
            array_keys($collector->routeMiddleware),
            array_merge($manualReview, $collector->manualReviewItems),
        );
    }

    /**
     * @param string[] $global
     * @param array<string, string> $route  alias → class map
     */
    private function patchKernel(string $kernelFile, array $global, array $route): void
    {
        $content = (string) file_get_contents($kernelFile);

        // Append global middleware
        if ($global !== []) {
            $entries = implode("\n", array_map(
                fn(string $cls) => "        {$cls},",
                $global
            ));
            $marker = 'protected $middleware = [';
            if (str_contains($content, $marker)) {
                $replacement = $marker . "\n        // Migrated from Lumen bootstrap/app.php\n{$entries}";
                $content = str_replace($marker, $replacement, $content);
            }
        }

        // Append route middleware
        if ($route !== []) {
            $entries = implode("\n", array_map(
                fn(string $alias, string $cls) => "        '{$alias}' => {$cls},",
                array_keys($route),
                $route,
            ));
            // Laravel 9 uses $routeMiddleware
            $marker = 'protected $routeMiddleware = [';
            if (str_contains($content, $marker)) {
                $replacement = $marker . "\n        // Migrated from Lumen bootstrap/app.php\n{$entries}";
                $content = str_replace($marker, $replacement, $content);
            }
        }

        file_put_contents($kernelFile, $content);
    }

    /**
     * @param LumenManualReviewItem[] $items
     */
    private function emitManualReviewEvents(array $items): void
    {
        foreach ($items as $item) {
            $this->emitEvent('lumen_manual_review', [
                'category'    => $item->category,
                'file'        => $item->file,
                'line'        => $item->line,
                'description' => $item->description,
                'severity'    => $item->severity,
                'suggestion'  => $item->suggestion,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $event, array $data): void
    {
        echo json_encode(['event' => $event, 'ts' => time()] + $data) . "\n";
    }
}

/**
 * @internal
 * Collects $app->middleware() and $app->routeMiddleware() calls from the AST.
 */
final class MiddlewareCallCollector extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $globalMiddleware = [];

    /** @var array<string, string> alias → class */
    public array $routeMiddleware = [];

    /** @var LumenManualReviewItem[] */
    public array $manualReviewItems = [];

    public function leaveNode(Node $node): null
    {
        if (!($node instanceof MethodCall)) {
            return null;
        }
        if (!($node->var instanceof Variable) || $node->var->name !== 'app') {
            return null;
        }
        if (!($node->name instanceof Node\Identifier)) {
            return null;
        }

        $method = $node->name->name;

        if ($method === 'middleware') {
            $this->collectGlobal($node);
        } elseif ($method === 'routeMiddleware') {
            $this->collectRoute($node);
        }

        return null;
    }

    private function collectGlobal(MethodCall $node): void
    {
        if (count($node->args) === 0) {
            return;
        }

        $arg = $node->args[0];
        if (!($arg instanceof Arg)) {
            return;
        }

        $value = $arg->value;
        if (!($value instanceof Node\Expr\Array_)) {
            $this->manualReviewItems[] = LumenManualReviewItem::middleware(
                '',
                $node->getStartLine(),
                '$app->middleware() called with non-array argument — cannot auto-migrate.',
                'Manually add middleware to app/Http/Kernel.php $middleware array.',
            );
            return;
        }

        foreach ($value->items as $item) {
            if (!($item instanceof Node\ArrayItem)) {
                continue;
            }
            $class = $this->extractClassString($item->value);
            if ($class !== null) {
                $this->globalMiddleware[] = $class;
            }
        }
    }

    private function collectRoute(MethodCall $node): void
    {
        if (count($node->args) === 0) {
            return;
        }

        $arg = $node->args[0];
        if (!($arg instanceof Arg)) {
            return;
        }

        $value = $arg->value;
        if (!($value instanceof Node\Expr\Array_)) {
            $this->manualReviewItems[] = LumenManualReviewItem::middleware(
                '',
                $node->getStartLine(),
                '$app->routeMiddleware() called with non-array argument — cannot auto-migrate.',
                'Manually add route middleware to app/Http/Kernel.php $routeMiddleware array.',
            );
            return;
        }

        foreach ($value->items as $item) {
            if (!($item instanceof Node\ArrayItem)) {
                continue;
            }
            $alias = null;
            if ($item->key instanceof Node\Scalar\String_) {
                $alias = $item->key->value;
            }
            $class = $this->extractClassString($item->value);
            if ($alias !== null && $class !== null) {
                $this->routeMiddleware[$alias] = $class;
            }
        }
    }

    private function extractClassString(Node\Expr $value): string|null
    {
        // SomeClass::class
        if ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name) {
            return '\\' . ltrim($value->class->toString(), '\\') . '::class';
        }
        // 'Some\Class' string literal
        if ($value instanceof Node\Scalar\String_) {
            return "'{$value->value}'";
        }
        return null;
    }
}

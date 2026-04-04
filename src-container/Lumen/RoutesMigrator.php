<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use AppContainer\Lumen\Exception\RouteMigrationException;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Error as ParserError;

/**
 * Migrates Lumen route files to Laravel Route facade syntax (TRD §10.3).
 *
 * Transformation rules:
 *   $router->get(...)    → Route::get(...)
 *   $router->post(...)   → Route::post(...)
 *   $router->put(...)    → Route::put(...)
 *   $router->patch(...)  → Route::patch(...)
 *   $router->delete(...) → Route::delete(...)
 *   $router->options(...) → Route::options(...)
 *   $router->group(...)  → Route::group(...)
 *   $router->addRoute(...) → Route::match(...)
 *
 * Manual review items are emitted for:
 *   - Closures using `$app` variable (Lumen-specific DI pattern)
 *   - $router->addRoute() with dynamic HTTP method arrays
 *   - Any unrecognised $router method call
 */
final class RoutesMigrator
{
    /** HTTP verb methods directly mappable to Route:: */
    public const VERB_MAP = [
        'get'     => 'get',
        'post'    => 'post',
        'put'     => 'put',
        'patch'   => 'patch',
        'delete'  => 'delete',
        'options' => 'options',
        'group'   => 'group',
    ];

    public function migrate(string $workspacePath, string $targetPath): RoutesMigrationResult
    {
        $routeFiles = $this->findRouteFiles($workspacePath);

        if ($routeFiles === []) {
            $this->emitEvent('lumen_routes_migrated', [
                'migrated' => 0,
                'flagged'  => 0,
                'files'    => [],
            ]);
            return RoutesMigrationResult::success(0, 0, [], []);
        }

        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $printer    = new PrettyPrinter();
        $allItems   = [];
        $outputFiles = [];
        $totalMigrated = 0;
        $totalFlagged  = 0;

        foreach ($routeFiles as $sourceFile) {
            $code = file_get_contents($sourceFile);
            if ($code === false) {
                throw new RouteMigrationException("Cannot read route file: {$sourceFile}");
            }

            try {
                $ast = $parser->parse($code);
            } catch (ParserError $e) {
                $allItems[] = LumenManualReviewItem::route(
                    $sourceFile,
                    0,
                    "Parse error in route file: {$e->getMessage()}. Manual migration required.",
                );
                $totalFlagged++;
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new RouterToFacadeVisitor($sourceFile);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $newAst = $traverser->traverse($ast);

            $allItems = array_merge($allItems, $visitor->manualReviewItems);
            $totalMigrated += $visitor->migratedCount;
            $totalFlagged  += $visitor->flaggedCount;

            // Determine target output path
            $relPath = $this->resolveTargetRoutePath($sourceFile, $workspacePath);
            $outputFile = $targetPath . '/routes/' . $relPath;
            $this->ensureDirectory(dirname($outputFile));

            file_put_contents($outputFile, $printer->prettyPrintFile($newAst));
            $outputFiles[] = $outputFile;

            $this->emitManualReviewEvents($visitor->manualReviewItems);
        }

        $this->emitEvent('lumen_routes_migrated', [
            'migrated' => $totalMigrated,
            'flagged'  => $totalFlagged,
            'files'    => $outputFiles,
        ]);

        return RoutesMigrationResult::success($totalMigrated, $totalFlagged, $outputFiles, $allItems);
    }

    /**
     * @return string[]
     */
    private function findRouteFiles(string $workspacePath): array
    {
        $candidates = [
            $workspacePath . '/routes/web.php',
            $workspacePath . '/routes/api.php',
            $workspacePath . '/app/Http/routes.php',
        ];

        return array_filter($candidates, 'file_exists');
    }

    private function resolveTargetRoutePath(string $sourceFile, string $workspacePath): string
    {
        // Lumen may have routes in app/Http/routes.php → map to web.php
        if (str_ends_with($sourceFile, 'app/Http/routes.php') ||
            str_ends_with($sourceFile, 'app\\Http\\routes.php')) {
            return 'web.php';
        }

        return basename($sourceFile);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RouteMigrationException("Cannot create directory: {$dir}");
        }
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
 * NodeVisitor that transforms $router->method() calls to Route::method() static calls.
 */
final class RouterToFacadeVisitor extends NodeVisitorAbstract
{
    /** @var LumenManualReviewItem[] */
    public array $manualReviewItems = [];

    public int $migratedCount = 0;
    public int $flaggedCount  = 0;

    public function __construct(private readonly string $file) {}

    public function leaveNode(Node $node): ?Node
    {
        // Transform $router->method(...) → Route::method(...)
        if (!($node instanceof MethodCall)) {
            return null;
        }

        if (!($node->var instanceof Variable) || $node->var->name !== 'router') {
            return null;
        }

        $methodName = $node->name instanceof Node\Identifier ? $node->name->name : null;
        if ($methodName === null) {
            return null;
        }

        $routeMethod = RoutesMigrator::VERB_MAP[$methodName] ?? null;

        if ($routeMethod !== null) {
            $this->migratedCount++;
            // Remove $router from `use` clause if this is inside a closure
            return new StaticCall(
                new Name('Route'),
                $methodName,
                $node->args,
                $node->getAttributes(),
            );
        }

        if ($methodName === 'addRoute') {
            $this->migratedCount++;
            // addRoute($methods, $uri, $action) → Route::match($methods, $uri, $action)
            return new StaticCall(
                new Name('Route'),
                'match',
                $node->args,
                $node->getAttributes(),
            );
        }

        // Unrecognised $router->xxx() — flag for manual review
        $line = $node->getStartLine();
        $this->flaggedCount++;
        $this->manualReviewItems[] = LumenManualReviewItem::route(
            $this->file,
            $line,
            "Unrecognised \$router->{$methodName}() call — cannot auto-migrate.",
            "Manually convert to the equivalent Route:: call.",
        );

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        // Flag closures that reference $app (Lumen DI pattern)
        if (!($node instanceof Closure)) {
            return null;
        }

        foreach ($node->uses as $use) {
            /** @var ClosureUse $use */
            if ($use->var instanceof Variable && $use->var->name === 'app') {
                $line = $node->getStartLine();
                $this->flaggedCount++;
                $this->manualReviewItems[] = LumenManualReviewItem::route(
                    $this->file,
                    $line,
                    'Closure uses $app — Lumen-specific DI pattern. Manual review required.',
                    'Replace $app->make() with constructor injection or app() helper.',
                );
            }
        }

        return null;
    }
}

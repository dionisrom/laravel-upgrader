<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Error as ParserError;

/**
 * Migrates `app/Providers/RouteServiceProvider.php` to L11's
 * `->withRouting()` parameters in `bootstrap/app.php`.
 *
 * Extracts paths for: web, api, console route files.
 * Routes using dynamic loading (loops/globs) are flagged as manual review.
 *
 * (Design spike §5.4, TRD-P2SLIM-001)
 */
final class RouteServiceProviderMigrator
{
    public function migrate(string $workspacePath): RouteServiceProviderMigrationResult
    {
        $rspFile = $workspacePath . '/app/Providers/RouteServiceProvider.php';

        if (!file_exists($rspFile)) {
            // Fall back to conventional routes — use well-known defaults
            return RouteServiceProviderMigrationResult::success(
                webRoutes: file_exists($workspacePath . '/routes/web.php')
                    ? "__DIR__.'/../routes/web.php'"
                    : null,
                apiRoutes: file_exists($workspacePath . '/routes/api.php')
                    ? "__DIR__.'/../routes/api.php'"
                    : null,
                consoleRoutes: file_exists($workspacePath . '/routes/console.php')
                    ? "__DIR__.'/../routes/console.php'"
                    : null,
                manualReviewItems: [],
            );
        }

        $code = file_get_contents($rspFile);
        if ($code === false) {
            return RouteServiceProviderMigrationResult::failure("Cannot read {$rspFile}");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast    = $parser->parse($code);
        } catch (ParserError $e) {
            return RouteServiceProviderMigrationResult::failure("Parse error in {$rspFile}: {$e->getMessage()}");
        }

        if ($ast === null) {
            return RouteServiceProviderMigrationResult::failure("Empty AST from {$rspFile}");
        }

        $visitor   = new RouteServiceProviderVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $manualReviewItems = [];

        if ($visitor->hasDynamicLoading) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::routes(
                $rspFile,
                0,
                'Dynamic route loading (loop/glob-based) found in RouteServiceProvider — cannot be automatically migrated.',
                'warning',
                'Manually convert dynamic route loading to explicit paths in the withRouting() call or a dedicated route file.'
            );
        }

        // Use detected paths or fall back to conventional defaults
        $webRoutes = $visitor->webRoutesPath
            ?? (file_exists($workspacePath . '/routes/web.php') ? "__DIR__.'/../routes/web.php'" : null);

        $apiRoutes = $visitor->apiRoutesPath
            ?? (file_exists($workspacePath . '/routes/api.php') ? "__DIR__.'/../routes/api.php'" : null);

        $consoleRoutes = file_exists($workspacePath . '/routes/console.php')
            ? "__DIR__.'/../routes/console.php'"
            : null;

        $this->emitEvent('slim_routes_migrated', [
            'workspace'        => $workspacePath,
            'web_routes'       => $webRoutes,
            'api_routes'       => $apiRoutes,
            'console_routes'   => $consoleRoutes,
            'manual_review'    => count($manualReviewItems),
        ]);

        return RouteServiceProviderMigrationResult::success(
            webRoutes: $webRoutes,
            apiRoutes: $apiRoutes,
            consoleRoutes: $consoleRoutes,
            manualReviewItems: $manualReviewItems,
        );
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
final class RouteServiceProviderVisitor extends NodeVisitorAbstract
{
    public string|null $webRoutesPath = null;
    public string|null $apiRoutesPath = null;
    public bool $hasDynamicLoading    = false;

    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter();
    }

    public function enterNode(Node $node): null
    {
        if (!$node instanceof ClassMethod) {
            return null;
        }

        $methodName = $node->name->toString();
        if (!in_array($methodName, ['boot', 'map', 'mapWebRoutes', 'mapApiRoutes'], true)) {
            return null;
        }

        if ($node->stmts === null) {
            return null;
        }

        $code = $this->printer->prettyPrint($node->stmts);

        // Detect dynamic loading patterns
        if (str_contains($code, 'glob(') || str_contains($code, 'foreach') || str_contains($code, 'array_map')) {
            $this->hasDynamicLoading = true;
        }

        // Extract web routes path
        if ($this->webRoutesPath === null) {
            if (preg_match('/routes\/web\.php/', $code)) {
                $this->webRoutesPath = "__DIR__.'/../routes/web.php'";
            }
        }

        // Extract api routes path
        if ($this->apiRoutesPath === null) {
            if (preg_match('/routes\/api\.php/', $code)) {
                $this->apiRoutesPath = "__DIR__.'/../routes/api.php'";
            }
        }

        return null;
    }
}

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
 * Migrates `app/Exceptions/Handler.php` from L10 to the L11
 * `->withExceptions()` callback (Design spike §2, TRD-P2SLIM-001).
 *
 * Extracts:
 *   - $dontReport   → diff against L11 defaults → $exceptions->dontReport()
 *   - $dontFlash    → diff against L11 defaults → $exceptions->dontFlash()
 *   - report() body → typed closures → $exceptions->report(...)
 *   - render() body → typed closures → $exceptions->render(...)
 *
 * Non-standard handler logic (Sentry/Bugsnag, shouldReport override,
 * complex render logic) is backed up and flagged for manual review.
 */
final class ExceptionHandlerMigrator
{
    /**
     * Exception classes already in L11's default $dontReport.
     * @var string[]
     */
    private const L11_DEFAULT_DONT_REPORT = [
        'Illuminate\\Auth\\AuthenticationException',
        'Illuminate\\Auth\\Access\\AuthorizationException',
        'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
        'Illuminate\\Http\\Exceptions\\HttpResponseException',
        'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
        'Illuminate\\Http\\Exceptions\\MaintenanceModeException',
        'Illuminate\\Session\\TokenMismatchException',
        'Illuminate\\Validation\\ValidationException',
    ];

    /**
     * Input keys already excluded by L11 default.
     * @var string[]
     */
    private const L11_DEFAULT_DONT_FLASH = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function migrate(string $workspacePath): ExceptionHandlerMigrationResult
    {
        $handlerFile = $workspacePath . '/app/Exceptions/Handler.php';

        if (!file_exists($handlerFile)) {
            return ExceptionHandlerMigrationResult::noHandlerFile();
        }

        $code = file_get_contents($handlerFile);
        if ($code === false) {
            return ExceptionHandlerMigrationResult::failure("Cannot read {$handlerFile}");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast    = $parser->parse($code);
        } catch (ParserError $e) {
            return ExceptionHandlerMigrationResult::failure("Parse error in {$handlerFile}: {$e->getMessage()}");
        }

        if ($ast === null) {
            return ExceptionHandlerMigrationResult::failure("Empty AST from {$handlerFile}");
        }

        $visitor   = new HandlerVisitor();
        $traverser = new NodeTraverser();
        // NameResolver is required: use-imported short names must be resolved
        // to FQCNs so that L11 default diff matching works correctly.
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $manualReviewItems = [];
        $backupFiles       = [];

        // Detect patterns requiring manual review / backup
        $needsBackup = $visitor->hasThirdPartyReporting
            || $visitor->hasShouldReportOverride
            || $visitor->hasCustomContextMethod
            || $visitor->hasComplexRenderLogic
            || $visitor->hasUnauthenticatedOverride;

        if ($needsBackup) {
            $backupFile = $handlerFile . '.laravel-backup';
            if (!file_exists($backupFile)) {
                copy($handlerFile, $backupFile);
            }
            $backupFiles[] = $backupFile;
        }

        if ($visitor->hasThirdPartyReporting) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::exceptionHandler(
                $handlerFile,
                0,
                'Third-party error reporting (Sentry/Bugsnag/Rollbar) detected in Handler — remove manual call and verify auto-reporting via service provider.',
                'warning',
                'The Sentry/Bugsnag L11 SDK registers itself automatically via a service provider. Remove the manual captureUnhandledException() call.'
            );
        }

        if ($visitor->hasShouldReportOverride) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::exceptionHandler(
                $handlerFile,
                0,
                'shouldReport() override has no direct L11 equivalent.',
                'error',
                'Use $exceptions->dontReport() or a custom middleware to replicate shouldReport() logic.'
            );
        }

        if ($visitor->hasCustomContextMethod) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::exceptionHandler(
                $handlerFile,
                0,
                'Custom context() method detected — provides extra log context in L10.',
                'info',
                'Move extra context to a custom log channel or a reporting closure in withExceptions().'
            );
        }

        if ($visitor->hasUnauthenticatedOverride) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::exceptionHandler(
                $handlerFile,
                0,
                'Custom unauthenticated() redirect logic detected.',
                'warning',
                'In L11, use AuthenticationException::redirectTo() or a render() closure for unauthenticated handling.'
            );
        }

        if ($visitor->hasComplexRenderLogic) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::exceptionHandler(
                $handlerFile,
                0,
                'render() method contains logic that cannot be automatically split into typed closures.',
                'warning',
                'Review render() manually and convert each branch to a typed $exceptions->render(function (ExcType $e, Request $request) { ... }) closure.'
            );
        }

        // Diff dontReport against L11 defaults
        $customDontReport = array_values(array_filter(
            $visitor->dontReport,
            fn(string $fqcn) => !$this->isL11DefaultDontReport($fqcn)
        ));

        // Diff dontFlash against L11 defaults
        $customDontFlash = array_values(array_filter(
            $visitor->dontFlash,
            fn(string $key) => !in_array($key, self::L11_DEFAULT_DONT_FLASH, true)
        ));

        $this->emitEvent('slim_exception_handler_migrated', [
            'workspace'              => $workspacePath,
            'dont_report_count'      => count($customDontReport),
            'dont_flash_count'       => count($customDontFlash),
            'report_closure_count'   => count($visitor->reportClosures),
            'render_closure_count'   => count($visitor->renderClosures),
            'needs_backup'           => $needsBackup,
            'manual_review_count'    => count($manualReviewItems),
        ]);

        return ExceptionHandlerMigrationResult::success(
            dontReport: $customDontReport,
            dontFlash: $customDontFlash,
            reportClosures: $visitor->reportClosures,
            renderClosures: $visitor->renderClosures,
            manualReviewItems: $manualReviewItems,
            backupFiles: $backupFiles,
        );
    }

    private function isL11DefaultDontReport(string $fqcn): bool
    {
        $normalised = ltrim($fqcn, '\\');
        foreach (self::L11_DEFAULT_DONT_REPORT as $default) {
            if (ltrim($default, '\\') === $normalised) {
                return true;
            }
        }
        return false;
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
final class HandlerVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $dontReport = [];

    /** @var string[] */
    public array $dontFlash = [];

    /** @var string[] */
    public array $reportClosures = [];

    /** @var string[] */
    public array $renderClosures = [];

    public bool $hasThirdPartyReporting  = false;
    public bool $hasShouldReportOverride = false;
    public bool $hasCustomContextMethod  = false;
    public bool $hasComplexRenderLogic   = false;
    public bool $hasUnauthenticatedOverride = false;

    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter();
    }

    public function leaveNode(Node $node): null
    {
        if (!$node instanceof Class_) {
            return null;
        }

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    if ($name === 'dontReport' && $prop->default instanceof Array_) {
                        $this->dontReport = $this->extractClassNames($prop->default);
                    }
                    if ($name === 'dontFlash' && $prop->default instanceof Array_) {
                        $this->dontFlash = $this->extractStrings($prop->default);
                    }
                }
            }

            if ($stmt instanceof ClassMethod) {
                $this->processMethod($stmt);
            }
        }

        return null;
    }

    private function processMethod(ClassMethod $method): void
    {
        $name = $method->name->toString();

        match ($name) {
            'shouldReport'    => $this->hasShouldReportOverride    = true,
            'context'         => $this->hasCustomContextMethod     = true,
            'unauthenticated' => $this->hasUnauthenticatedOverride = true,
            'report'          => $this->processReportMethod($method),
            'render'          => $this->processRenderMethod($method),
            default           => null,
        };
    }

    private function processReportMethod(ClassMethod $method): void
    {
        if ($method->stmts === null) {
            return;
        }

        foreach ($method->stmts as $stmt) {
            // Detect third-party reporting
            $stmtCode = $this->printer->prettyPrint([$stmt]);
            if (
                str_contains($stmtCode, 'Sentry') ||
                str_contains($stmtCode, 'bugsnag') ||
                str_contains($stmtCode, 'Bugsnag') ||
                str_contains($stmtCode, 'Rollbar') ||
                str_contains($stmtCode, 'rollbar')
            ) {
                $this->hasThirdPartyReporting = true;
            }

            // Extract instanceof-typed branches
            if ($stmt instanceof Node\Stmt\If_) {
                $closure = $this->extractTypedReportBranch($stmt);
                if ($closure !== null) {
                    $this->reportClosures[] = $closure;
                }
            }
        }
    }

    private function processRenderMethod(ClassMethod $method): void
    {
        if ($method->stmts === null) {
            return;
        }

        $branchCount       = 0;
        $extractableCount  = 0;

        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\If_) {
                $branchCount++;
                $closure = $this->extractTypedRenderBranch($stmt);
                if ($closure !== null) {
                    $this->renderClosures[] = $closure;
                    $extractableCount++;
                }
            }
        }

        // If there are branches that could not be extracted, flag for manual review
        if ($branchCount > $extractableCount) {
            $this->hasComplexRenderLogic = true;
        }
    }

    private function extractTypedReportBranch(Node\Stmt\If_ $if): string|null
    {
        $cond = $if->cond;
        if (!$cond instanceof Node\Expr\Instanceof_) {
            return null;
        }

        $type = $cond->class;
        if (!$type instanceof Node\Name) {
            return null;
        }

        $typeName = $type->toString();
        $bodyCode = $this->printer->prettyPrint($if->stmts ?? []);

        return "function ({$typeName} \$e) {\n    {$bodyCode}\n}";
    }

    private function extractTypedRenderBranch(Node\Stmt\If_ $if): string|null
    {
        $cond = $if->cond;
        if (!$cond instanceof Node\Expr\Instanceof_) {
            return null;
        }

        $type = $cond->class;
        if (!$type instanceof Node\Name) {
            return null;
        }

        $typeName = $type->toString();

        // Extract the return statement from inside the if
        $returnStmt = null;
        foreach ($if->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_) {
                $returnStmt = $stmt;
                break;
            }
        }

        if ($returnStmt === null) {
            return null;
        }

        $returnCode = $this->printer->prettyPrint([$returnStmt]);

        return "function ({$typeName} \$e, \\Illuminate\\Http\\Request \$request) {\n    {$returnCode}\n}";
    }

    /**
     * @return string[]
     */
    private function extractClassNames(Array_ $array): array
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
     * @return string[]
     */
    private function extractStrings(Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if ($item instanceof ArrayItem && $item->value instanceof Node\Scalar\String_) {
                $result[] = $item->value->value;
            }
        }
        return $result;
    }

    private function resolveClassConst(Node $node): string|null
    {
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $class    = $node->class;
            $resolved = $class->getAttribute('resolvedName');
            return $resolved instanceof Node\Name\FullyQualified
                ? $resolved->toString()
                : $class->toString();
        }
        return null;
    }
}

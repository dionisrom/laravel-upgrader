<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Error as ParserError;

/**
 * Migrates Lumen's exception handler to Laravel 9 signatures (TRD §10.6).
 *
 * Lumen Handler: extends `Laravel\Lumen\Exceptions\Handler`
 * Laravel Handler: extends `Illuminate\Foundation\Exceptions\Handler`
 *
 * Method signature differences:
 *
 * | Method        | Lumen signature                                | Laravel 9 signature                               |
 * |---------------|------------------------------------------------|---------------------------------------------------|
 * | report        | report(Throwable $e): void                     | report(Throwable $e): void  (same)                |
 * | render        | render($request, Throwable $e): Response       | render($request, Throwable $e): Response (same)   |
 * | shouldReport  | shouldReport(Throwable $e): bool               | shouldReport(Throwable $e): bool (same — skipped) |
 * | renderForConsole | renderForConsole($output, Throwable $e): void | renderForConsole($output, Throwable $e): void (same) |
 *
 * The main change is the parent class reference.
 * Any custom $dontReport / $internalDontReport entries are preserved.
 * Lumen-specific methods with no Laravel equivalent are flagged for review.
 */
final class ExceptionHandlerMigrator
{
    /** Methods with identical signatures — safe to auto-migrate */
    public const AUTO_MIGRATED_METHODS = ['report', 'render', 'shouldReport', 'renderForConsole'];

    public function migrate(string $workspacePath, string $targetPath): ExceptionHandlerMigrationResult
    {
        $sourceHandler = $workspacePath . '/app/Exceptions/Handler.php';
        if (!file_exists($sourceHandler)) {
            return ExceptionHandlerMigrationResult::success([]);
        }

        $code = file_get_contents($sourceHandler);
        if ($code === false) {
            return ExceptionHandlerMigrationResult::failure("Cannot read app/Exceptions/Handler.php");
        }

        try {
            $parser  = (new ParserFactory())->createForNewestSupportedVersion();
            $printer = new PrettyPrinter();
            $ast     = $parser->parse($code);
        } catch (ParserError $e) {
            return ExceptionHandlerMigrationResult::failure("Parse error in Handler.php: {$e->getMessage()}");
        }

        if ($ast === null) {
            return ExceptionHandlerMigrationResult::success([]);
        }

        $visitor = new HandlerClassVisitor($sourceHandler);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($ast);

        // Write migrated handler
        $targetHandler = $targetPath . '/app/Exceptions/Handler.php';
        $this->ensureDirectory(dirname($targetHandler));
        file_put_contents($targetHandler, $printer->prettyPrintFile($newAst));

        $this->emitManualReviewEvents($visitor->manualReviewItems);
        $this->emitEvent('lumen_exception_handler_migrated', [
            'source'         => $sourceHandler,
            'target'         => $targetHandler,
            'mapped_methods' => $visitor->mappedMethods,
            'flagged'        => count($visitor->manualReviewItems),
        ]);

        return ExceptionHandlerMigrationResult::success($visitor->mappedMethods, $visitor->manualReviewItems);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
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
 * Transforms the Lumen Handler class:
 *   - Replaces `extends Laravel\Lumen\Exceptions\Handler`
 *     with    `extends Illuminate\Foundation\Exceptions\Handler`
 *   - Collects auto-migrated method names
 *   - Flags non-standard methods for manual review
 */
final class HandlerClassVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $mappedMethods = [];

    /** @var LumenManualReviewItem[] */
    public array $manualReviewItems = [];

    public function __construct(private readonly string $file) {}

    public function leaveNode(Node $node): Node|null
    {
        if (!($node instanceof Node\Stmt\Class_)) {
            return null;
        }

        // Replace parent class reference
        if ($node->extends !== null) {
            $parentName = $node->extends->toString();
            if ($parentName === 'Handler' ||
                str_ends_with($parentName, 'Lumen\\Exceptions\\Handler')) {
                $node->extends = new Node\Name\FullyQualified(
                    'Illuminate\\Foundation\\Exceptions\\Handler'
                );
            }
        }

        // Audit class methods
        foreach ($node->stmts as $stmt) {
            if (!($stmt instanceof ClassMethod)) {
                continue;
            }

            $name = $stmt->name->name;

            if (in_array($name, ExceptionHandlerMigrator::AUTO_MIGRATED_METHODS, true)) {
                $this->mappedMethods[] = $name;
                continue;
            }

            // Skip __construct and dontReport property setters
            if (in_array($name, ['__construct', '__destruct'], true)) {
                continue;
            }

            // Any other overridden method is a manual review candidate
            $this->manualReviewItems[] = LumenManualReviewItem::exceptionHandler(
                $this->file,
                $stmt->getStartLine(),
                "Handler method '{$name}' has no direct Laravel 9 equivalent — manual review required.",
                "Verify if '{$name}' is needed in Laravel 9 and refactor accordingly.",
            );
        }

        return $node;
    }
}

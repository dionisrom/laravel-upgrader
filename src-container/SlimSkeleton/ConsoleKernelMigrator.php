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
 * Migrates `app/Console/Kernel.php` from L10 to L11 conventions:
 *   - schedule() method body → routes/console.php (Schedule facade calls)
 *   - $commands array        → bootstrap/app.php withCommands()
 *
 * (Design spike §3, TRD-P2SLIM-001)
 */
final class ConsoleKernelMigrator
{
    public function migrate(string $workspacePath): ConsoleKernelMigrationResult
    {
        $kernelFile = $workspacePath . '/app/Console/Kernel.php';

        if (!file_exists($kernelFile)) {
            return ConsoleKernelMigrationResult::noConsoleKernel();
        }

        $code = file_get_contents($kernelFile);
        if ($code === false) {
            return ConsoleKernelMigrationResult::failure("Cannot read {$kernelFile}");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast    = $parser->parse($code);
        } catch (ParserError $e) {
            return ConsoleKernelMigrationResult::failure("Parse error in {$kernelFile}: {$e->getMessage()}");
        }

        if ($ast === null) {
            return ConsoleKernelMigrationResult::failure("Empty AST from {$kernelFile}");
        }

        $visitor   = new ConsoleKernelVisitor();
        $traverser = new NodeTraverser();
        // NameResolver is required: use-imported short names must be resolved
        // to FQCNs so withCommands() output contains valid class references.
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $manualReviewItems = [];
        $backupFiles       = [];

        $needsBackup = $visitor->hasBootstrapWithOverride || $visitor->hasDynamicCommandsOverride;

        if ($needsBackup) {
            $backupFile = $kernelFile . '.laravel-backup';
            if (!file_exists($backupFile)) {
                copy($kernelFile, $backupFile);
            }
            $backupFiles[] = $backupFile;
        }

        if ($visitor->hasBootstrapWithOverride) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::consoleKernel(
                $kernelFile,
                0,
                'bootstrapWith() override found in Console Kernel — no direct L11 equivalent.',
                'error',
                'Review bootstrapWith() and determine if the bootstrapper can be registered via a service provider instead.'
            );
        }

        if ($visitor->hasDynamicCommandsOverride) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::consoleKernel(
                $kernelFile,
                0,
                'Dynamic commands() override found — requires explicit paths in L11.',
                'warning',
                'Use withCommands() with explicit class list or directory paths in bootstrap/app.php.'
            );
        }

        if ($visitor->hasConditionalScheduling) {
            $manualReviewItems[] = SlimSkeletonManualReviewItem::consoleKernel(
                $kernelFile,
                0,
                'Environment-based conditional scheduling detected in schedule() — verify L11 ->environments() chain is applied.',
                'info',
                'L11 supports $schedule->command(...)->environments([\'production\']. Verify conditions in routes/console.php.'
            );
        }

        // Transform schedule() body — replace $schedule-> with Schedule::
        $scheduleStatements = $this->transformScheduleStatements($visitor->scheduleStatements);

        $this->emitEvent('slim_console_kernel_migrated', [
            'workspace'               => $workspacePath,
            'schedule_statement_count' => count($scheduleStatements),
            'command_class_count'     => count($visitor->commandClasses),
            'needs_backup'            => $needsBackup,
            'manual_review_count'     => count($manualReviewItems),
        ]);

        return ConsoleKernelMigrationResult::success(
            scheduleStatements: $scheduleStatements,
            commandClasses: $visitor->commandClasses,
            manualReviewItems: $manualReviewItems,
            backupFiles: $backupFiles,
        );
    }

    /**
     * Convert statements from $schedule->... form to Schedule::... form.
     *
     * @param string[] $statements
     * @return string[]
     */
    private function transformScheduleStatements(array $statements): array
    {
        return array_map(
            fn(string $stmt) => str_replace('$schedule->', 'Schedule::', $stmt),
            $statements
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
final class ConsoleKernelVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $scheduleStatements = [];

    /** @var string[] */
    public array $commandClasses = [];

    public bool $hasBootstrapWithOverride    = false;
    public bool $hasDynamicCommandsOverride  = false;
    public bool $hasConditionalScheduling    = false;

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
                    if ($prop->name->toString() === 'commands' && $prop->default instanceof Array_) {
                        $this->commandClasses = $this->extractClassNames($prop->default);
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

        if ($name === 'bootstrapWith') {
            $this->hasBootstrapWithOverride = true;
            return;
        }

        if ($name === 'commands') {
            // A commands() method override (rather than $commands property) may use dynamic discovery
            $this->hasDynamicCommandsOverride = true;
            return;
        }

        if ($name !== 'schedule') {
            return;
        }

        if ($method->stmts === null) {
            return;
        }

        foreach ($method->stmts as $stmt) {
            // Detect conditional scheduling
            if ($stmt instanceof Node\Stmt\If_) {
                $this->hasConditionalScheduling = true;
            }

            $stmtCode = $this->printer->prettyPrint([$stmt]);
            if (trim($stmtCode) !== '') {
                $this->scheduleStatements[] = $stmtCode;
            }
        }
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
            if (
                $item->value instanceof Node\Expr\ClassConstFetch &&
                $item->value->class instanceof Node\Name
            ) {
                $class    = $item->value->class;
                $resolved = $class->getAttribute('resolvedName');
                $result[] = $resolved instanceof Node\Name\FullyQualified
                    ? $resolved->toString()
                    : $class->toString();
            }
        }
        return $result;
    }
}

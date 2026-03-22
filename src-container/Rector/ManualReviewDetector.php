<?php

declare(strict_types=1);

namespace AppContainer\Rector;

use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

final class ManualReviewDetector
{
    /** @var string[] */
    public const MAGIC_METHODS = [
        '__call',
        '__callStatic',
        '__get',
        '__set',
        '__invoke',
    ];

    /**
     * Traverses all PHP files under $workspacePath and collects patterns that
     * require manual review (magic methods, macros, dynamic calls, etc.).
     *
     * This method is READ-ONLY — no files are modified.
     *
     * @return ManualReviewItem[]
     */
    public function detect(string $workspacePath): array
    {
        $items = [];

        $phpFiles = $this->collectPhpFiles($workspacePath);

        foreach ($phpFiles as $filePath) {
            $fileItems = $this->analyseFile($filePath);
            foreach ($fileItems as $item) {
                $this->emitEvent($item);
            }
            $items = [...$items, ...$fileItems];
        }

        return $items;
    }

    /**
     * @return ManualReviewItem[]
     */
    private function analyseFile(string $filePath): array
    {
        $source = file_get_contents($filePath);

        if ($source === false) {
            return [];
        }

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));

        try {
            $stmts = $parser->parse($source);
        } catch (PhpParserError) {
            // Unparseable file — skip silently to avoid halting analysis
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        $visitor = new class ($filePath) extends NodeVisitorAbstract {
            /** @var ManualReviewItem[] */
            public array $items = [];

            public function __construct(private readonly string $filePath)
            {
            }

            public function enterNode(Node $node): null
            {
                // 1. Magic method definitions
                if ($node instanceof ClassMethod) {
                    $methodName = $node->name->name;
                    if (in_array($methodName, ManualReviewDetector::MAGIC_METHODS, true)) {
                        $this->items[] = new ManualReviewItem(
                            file: $this->filePath,
                            line: $node->getStartLine(),
                            pattern: 'magic_method',
                            detail: $methodName,
                        );
                    }
                }

                // 2. Macro registrations: SomeClass::macro(...)
                if ($node instanceof StaticCall) {
                    $methodName = $node->name;
                    if ($methodName instanceof Identifier && $methodName->name === 'macro') {
                        $this->items[] = new ManualReviewItem(
                            file: $this->filePath,
                            line: $node->getStartLine(),
                            pattern: 'macro',
                            detail: $this->resolveStaticClassName($node->class),
                        );
                    }
                }

                // 3. Macroable trait usage
                if ($node instanceof TraitUse) {
                    foreach ($node->traits as $trait) {
                        if ($trait instanceof Name && $trait->getLast() === 'Macroable') {
                            $this->items[] = new ManualReviewItem(
                                file: $this->filePath,
                                line: $node->getStartLine(),
                                pattern: 'macroable_trait',
                                detail: $trait->toString(),
                            );
                        }
                    }
                }

                // 4. Dynamic class instantiation: new $variable(...)
                if ($node instanceof New_) {
                    if ($node->class instanceof Variable && is_string($node->class->name)) {
                        $this->items[] = new ManualReviewItem(
                            file: $this->filePath,
                            line: $node->getStartLine(),
                            pattern: 'dynamic_instantiation',
                            detail: '$' . $node->class->name,
                        );
                    }
                }

                // 5. String-based method calls: $obj->$methodName(...)
                if ($node instanceof MethodCall) {
                    if ($node->name instanceof Variable && is_string($node->name->name)) {
                        $this->items[] = new ManualReviewItem(
                            file: $this->filePath,
                            line: $node->getStartLine(),
                            pattern: 'dynamic_call',
                            detail: '$' . $node->name->name,
                        );
                    }
                }

                return null;
            }

            private function resolveStaticClassName(Node $classNode): string
            {
                if ($classNode instanceof Name) {
                    return $classNode->toString();
                }

                if ($classNode instanceof Node\Expr\Variable && is_string($classNode->name)) {
                    return '$' . $classNode->name;
                }

                return '(dynamic)';
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->items;
    }

    /**
     * Recursively collects all .php files under $dir, skipping vendor/ and
     * .upgrader-state/ directories which should not be analysed.
     *
     * @return string[]
     */
    private function collectPhpFiles(string $dir): array
    {
        $skipDirs = ['vendor', '.upgrader-state', 'storage'];

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
                ),
                static function (\SplFileInfo $fileInfo) use ($skipDirs): bool {
                    if ($fileInfo->isDir()) {
                        return !in_array($fileInfo->getFilename(), $skipDirs, true);
                    }

                    return true;
                },
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    private function emitEvent(ManualReviewItem $item): void
    {
        echo json_encode([
            'type' => 'manual_review_required',
            'data' => [
                'file' => $item->file,
                'line' => $item->line,
                'pattern' => $item->pattern,
                'detail' => $item->detail,
            ],
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }
}

<?php

declare(strict_types=1);

namespace AppContainer\Verification;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class ClassResolutionVerifier implements VerifierInterface
{
    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
    {
        $start  = microtime(true);
        $appDir = $workspacePath . '/app';

        if (!is_dir($appDir)) {
            return new VerifierResult(
                passed:          true,
                verifierName:    'ClassResolutionVerifier',
                issueCount:      0,
                issues:          [],
                durationSeconds: microtime(true) - $start,
            );
        }

        $files  = $this->findPhpFiles($appDir);
        $parser = $this->createParser();
        $issues = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $classes = $this->extractUseStatements($content, $parser);

            foreach ($classes as $fqcn) {
                if (
                    !class_exists($fqcn, true)
                    && !interface_exists($fqcn, true)
                    && !trait_exists($fqcn, true)
                    && !enum_exists($fqcn, true)
                ) {
                    $issues[] = new VerificationIssue(
                        file:     $file,
                        line:     0,
                        message:  "Cannot resolve class: {$fqcn}",
                        severity: 'error',
                    );
                }
            }
        }

        return new VerifierResult(
            passed:          count($issues) === 0,
            verifierName:    'ClassResolutionVerifier',
            issueCount:      count($issues),
            issues:          $issues,
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * Extract fully-qualified class names from all `use` statements using AST.
     * Skips function and const imports.
     *
     * @return list<string>
     */
    private function extractUseStatements(string $content, \PhpParser\Parser $parser): array
    {
        try {
            $stmts = $parser->parse($content);
        } catch (\PhpParser\Error) {
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        $classes   = [];
        $traverser = new NodeTraverser();
        $visitor   = new class () extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $found = [];

            public function enterNode(Node $node)
            {
                // Regular use: use Foo\Bar;
                if ($node instanceof Use_ && $node->type === Use_::TYPE_NORMAL) {
                    foreach ($node->uses as $use) {
                        $this->found[] = $use->name->toString();
                    }
                }

                // Grouped use: use Foo\{Bar, Baz};
                if ($node instanceof GroupUse) {
                    $prefix = $node->prefix->toString();
                    foreach ($node->uses as $use) {
                        if ($use->type === Use_::TYPE_NORMAL) {
                            $this->found[] = $prefix . '\\' . $use->name->toString();
                        }
                    }
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->found;
    }

    /**
     * @return list<string>
     */
    private function findPhpFiles(string $dir): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    private function createParser(): \PhpParser\Parser
    {
        $factory = new ParserFactory();

        if (method_exists($factory, 'createForHostVersion')) {
            return $factory->createForHostVersion();
        }

        // @phpstan-ignore-next-line
        return $factory->create(4);
    }
}

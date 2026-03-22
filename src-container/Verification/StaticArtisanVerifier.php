<?php

declare(strict_types=1);

namespace AppContainer\Verification;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class StaticArtisanVerifier implements VerifierInterface
{
    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
    {
        $start  = microtime(true);
        $parser = $this->createParser();
        $issues = [];

        $issues = array_merge($issues, $this->verifyConfigFiles($workspacePath, $parser));
        $issues = array_merge($issues, $this->verifyRouteControllers($workspacePath, $parser));
        $issues = array_merge($issues, $this->verifyProviders($workspacePath, $parser));

        return new VerifierResult(
            passed:          count($issues) === 0,
            verifierName:    'StaticArtisanVerifier',
            issueCount:      count($issues),
            issues:          $issues,
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * @return list<VerificationIssue>
     */
    private function verifyConfigFiles(string $workspacePath, \PhpParser\Parser $parser): array
    {
        $configDir = $workspacePath . '/config';
        $issues    = [];

        if (!is_dir($configDir)) {
            return $issues;
        }

        foreach (glob($configDir . '/*.php') ?: [] as $configFile) {
            $content = file_get_contents($configFile);

            if ($content === false) {
                continue;
            }

            try {
                $stmts = $parser->parse($content);
            } catch (\PhpParser\Error $e) {
                $issues[] = new VerificationIssue(
                    file:     $configFile,
                    line:     $e->getStartLine(),
                    message:  "Config file parse error: {$e->getMessage()}",
                    severity: 'error',
                );
                continue;
            }

            if ($stmts === null || count($stmts) === 0) {
                $issues[] = new VerificationIssue(
                    file:     $configFile,
                    line:     0,
                    message:  'Config file ' . basename($configFile) . ' is empty',
                    severity: 'error',
                );
                continue;
            }

            $firstStmt = $stmts[0];

            if (!($firstStmt instanceof Return_) || !($firstStmt->expr instanceof Array_)) {
                $issues[] = new VerificationIssue(
                    file:     $configFile,
                    line:     0,
                    message:  'Config file ' . basename($configFile) . ' does not return a plain PHP array',
                    severity: 'error',
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<VerificationIssue>
     */
    private function verifyRouteControllers(string $workspacePath, \PhpParser\Parser $parser): array
    {
        $routesDir = $workspacePath . '/routes';
        $issues    = [];

        if (!is_dir($routesDir)) {
            return $issues;
        }

        foreach (glob($routesDir . '/*.php') ?: [] as $routeFile) {
            $content = file_get_contents($routeFile);

            if ($content === false) {
                continue;
            }

            $controllers = $this->extractControllerReferences($content);

            foreach ($controllers as $controller) {
                if (!class_exists($controller, true)) {
                    $issues[] = new VerificationIssue(
                        file:     $routeFile,
                        line:     0,
                        message:  "Route controller not found: {$controller}",
                        severity: 'error',
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Extract string literals from route files that look like controller FQCNs.
     *
     * @return list<string>
     */
    private function extractControllerReferences(string $content): array
    {
        $controllers = [];

        if (preg_match_all('/[\'"]([A-Z][A-Za-z0-9_\\\\]*Controller)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                if (str_contains($match, '\\')) {
                    $controllers[] = $match;
                }
            }
        }

        return $controllers;
    }

    /**
     * @return list<VerificationIssue>
     */
    private function verifyProviders(string $workspacePath, \PhpParser\Parser $parser): array
    {
        $appConfig = $workspacePath . '/config/app.php';
        $issues    = [];

        if (!file_exists($appConfig)) {
            return $issues;
        }

        $content = file_get_contents($appConfig);

        if ($content === false) {
            return $issues;
        }

        try {
            $stmts = $parser->parse($content);
        } catch (\PhpParser\Error $e) {
            return $issues;
        }

        if ($stmts === null) {
            return $issues;
        }

        $providers = $this->extractProvidersFromAst($stmts);

        foreach ($providers as $providerClass) {
            if (!class_exists($providerClass, true)) {
                $issues[] = new VerificationIssue(
                    file:     $appConfig,
                    line:     0,
                    message:  "Provider class not found: {$providerClass}",
                    severity: 'error',
                );
            }
        }

        return $issues;
    }

    /**
     * Walk the AST looking for a 'providers' array key and collect its string values.
     *
     * @param  list<\PhpParser\Node\Stmt> $stmts
     * @return list<string>
     */
    private function extractProvidersFromAst(array $stmts): array
    {
        $providers = [];

        $traverser = new NodeTraverser();
        $visitor   = new class () extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $found = [];

            public function enterNode(Node $node): null
            {
                if (!($node instanceof Node\Expr\ArrayItem)) {
                    return null;
                }

                $key = $node->key;

                if ($key instanceof String_ && $key->value === 'providers') {
                    $value = $node->value;

                    if ($value instanceof Array_) {
                        foreach ($value->items as $item) {
                            if ($item !== null && $item->value instanceof String_) {
                                $this->found[] = $item->value->value;
                            }
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

    private function createParser(): \PhpParser\Parser
    {
        $factory = new ParserFactory();

        // Support both php-parser v4 and v5
        if (method_exists($factory, 'createForHostVersion')) {
            return $factory->createForHostVersion();
        }

        // @phpstan-ignore-next-line
        return $factory->create(4); // ParserFactory::PREFER_PHP7 = 4 in v4
    }
}

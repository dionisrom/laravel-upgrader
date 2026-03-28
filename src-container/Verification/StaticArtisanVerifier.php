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
use Symfony\Component\Process\Process;

final class StaticArtisanVerifier implements VerifierInterface
{
    /** @var callable(list<string>, string): Process */
    private $processFactory;

    /**
     * @param callable(list<string>, string): Process|null $processFactory
     */
    public function __construct(?callable $processFactory = null)
    {
        $this->processFactory = $processFactory ?? static function (array $cmd, string $cwd): Process {
            return new Process($cmd, $cwd);
        };
    }

    public function verify(string $workspacePath, VerificationContext $ctx): VerifierResult
    {
        $start  = microtime(true);
        $parser = $this->createParser();
        $issues = [];

        $issues = array_merge($issues, $this->verifyConfigFiles($workspacePath, $parser));
        $issues = array_merge($issues, $this->verifyRouteControllers($workspacePath, $parser));
        $issues = array_merge($issues, $this->verifyProviders($workspacePath, $parser));

        if ($ctx->withArtisanVerify) {
            $issues = array_merge($issues, $this->runArtisanVerification($workspacePath, $ctx));
        }

        $staticIssues = array_filter($issues, fn(VerificationIssue $i) => $i->severity === 'error');

        return new VerifierResult(
            passed:          count($staticIssues) === 0,
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

            $hasReturnArray = false;
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Return_ && $stmt->expr instanceof Array_) {
                    $hasReturnArray = true;
                    break;
                }
            }

            if (!$hasReturnArray) {
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

            $controllers = $this->extractControllerReferencesFromAst($content, $parser);

            foreach ($controllers as $controller) {
                if (!class_exists($controller, false)) {
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
     * Extract controller class references from route files using AST.
     * Finds ::class references and string literals matching controller FQCNs.
     *
     * @return list<string>
     */
    private function extractControllerReferencesFromAst(string $content, \PhpParser\Parser $parser): array
    {
        try {
            $stmts = $parser->parse($content);
        } catch (\PhpParser\Error) {
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        $controllers = [];
        $traverser   = new NodeTraverser();
        $visitor     = new class () extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $found = [];

            public function enterNode(Node $node)
            {
                // Match Foo\Bar\Controller::class
                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                    && $node->name->name === 'class'
                ) {
                    $name = $node->class->toString();
                    if (str_ends_with($name, 'Controller')) {
                        $this->found[] = $name;
                    }
                }

                // Match string literals like 'App\Http\Controllers\FooController'
                if ($node instanceof String_
                    && str_contains($node->value, '\\')
                    && str_ends_with($node->value, 'Controller')
                ) {
                    $this->found[] = $node->value;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->found;
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
            if (!class_exists($providerClass, false)) {
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

            public function enterNode(Node $node)
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

    /**
     * Run artisan commands as advisory checks (warnings only, non-blocking).
     *
     * @return list<VerificationIssue>
     */
    private function runArtisanVerification(string $workspacePath, VerificationContext $ctx): array
    {
        $issues   = [];
        $commands = [
            [$ctx->phpBin, 'artisan', 'config:cache', '--quiet'],
            [$ctx->phpBin, 'artisan', 'route:list', '--json'],
        ];

        foreach ($commands as $cmd) {
            $process = ($this->processFactory)($cmd, $workspacePath);
            $process->run();

            if ($process->getExitCode() !== 0) {
                $output = trim($process->getErrorOutput() ?: $process->getOutput());
                $issues[] = new VerificationIssue(
                    file:     '',
                    line:     0,
                    message:  'Artisan command failed: ' . implode(' ', $cmd) . ($output !== '' ? " — {$output}" : ''),
                    severity: 'warning',
                );
            }
        }

        return $issues;
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

<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Arg;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Error as ParserError;

/**
 * Extracts inline config registrations from Lumen's `bootstrap/app.php` (TRD §10.5).
 *
 * Lumen pattern:
 *   $app->configure('database');
 *   $app->configure('auth');
 *
 * Migration strategy:
 *   1. Parse bootstrap/app.php, collect all `$app->configure('x')` calls
 *   2. For each config name:
 *      a. Found in Lumen `config/x.php` → copy into target `config/x.php`
 *      b. Not found                     → generate a stub + flag manual review
 *
 * Emits:
 *   - `lumen_config_extracted`  for each config file processed
 *   - `lumen_manual_review`     for each stubbed config
 */
final class InlineConfigExtractor
{
    /** Stub template for configs that don't exist in the Lumen source. */
    private const STUB_TEMPLATE = <<<'PHP'
<?php

/**
 * Auto-generated stub for config '%s'.
 *
 * TODO: Populate this file with the appropriate configuration values.
 * This stub was created because the original Lumen app registered this
 * config via $app->configure('%s') but had no corresponding config/%s.php file.
 *
 * @see https://laravel.com/docs/9.x/configuration
 */
return [
    //
];
PHP;

    public function extract(string $workspacePath, string $targetPath): ConfigExtractionResult
    {
        $bootstrapFile = $workspacePath . '/bootstrap/app.php';
        if (!file_exists($bootstrapFile)) {
            return ConfigExtractionResult::success([], []);
        }

        $code = file_get_contents($bootstrapFile);
        if ($code === false) {
            return ConfigExtractionResult::failure("Cannot read bootstrap/app.php");
        }

        try {
            $parser  = (new ParserFactory())->createForNewestSupportedVersion();
            $ast     = $parser->parse($code);
        } catch (ParserError $e) {
            return ConfigExtractionResult::failure("Parse error: {$e->getMessage()}");
        }

        if ($ast === null) {
            return ConfigExtractionResult::success([], []);
        }

        $collector = new ConfigureCallCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        if ($collector->configNames === []) {
            return ConfigExtractionResult::success([], []);
        }

        $this->ensureDirectory($targetPath . '/config');

        $copied   = [];
        $stubbed  = [];
        $manualReview = [];

        foreach ($collector->configNames as $configName) {
            $sourceConfig = $workspacePath . '/config/' . $configName . '.php';
            $targetConfig = $targetPath . '/config/' . $configName . '.php';

            if (file_exists($sourceConfig)) {
                // Only copy if target doesn't already have it (Laravel scaffold provides many)
                if (!file_exists($targetConfig)) {
                    if (!copy($sourceConfig, $targetConfig)) {
                        $manualReview[] = LumenManualReviewItem::config(
                            $sourceConfig,
                            0,
                            "Failed to copy config/{$configName}.php to target.",
                            "Manually copy config/{$configName}.php to the target project.",
                        );
                        continue;
                    }
                }
                $copied[] = $configName;
                $this->emitEvent('lumen_config_extracted', [
                    'config_name' => $configName,
                    'action'      => 'copied',
                    'source'      => $sourceConfig,
                    'target'      => $targetConfig,
                ]);
            } else {
                // Generate stub
                if (!file_exists($targetConfig)) {
                    $stub = sprintf(self::STUB_TEMPLATE, $configName, $configName, $configName);
                    file_put_contents($targetConfig, $stub);
                }
                $stubbed[] = $configName;
                $manualReview[] = LumenManualReviewItem::config(
                    $bootstrapFile,
                    $collector->configLines[$configName] ?? 0,
                    "Config '{$configName}' registered via \$app->configure() but no config/{$configName}.php found. A stub was generated.",
                    "Populate config/{$configName}.php with the appropriate values.",
                );
                $this->emitEvent('lumen_config_extracted', [
                    'config_name' => $configName,
                    'action'      => 'stubbed',
                    'target'      => $targetConfig,
                ]);
            }
        }

        $this->emitManualReviewEvents($manualReview);

        return ConfigExtractionResult::success($copied, $stubbed, $manualReview);
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
 * Collects all `$app->configure('x')` call arguments from the AST.
 */
final class ConfigureCallCollector extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $configNames = [];

    /** @var array<string, int> configName → source line number */
    public array $configLines = [];

    public function leaveNode(Node $node)
    {
        if (!($node instanceof MethodCall)) {
            return null;
        }
        if (!($node->var instanceof Variable) || $node->var->name !== 'app') {
            return null;
        }
        if (!($node->name instanceof Node\Identifier) || $node->name->name !== 'configure') {
            return null;
        }

        if (count($node->args) === 0) {
            return null;
        }

        $arg = $node->args[0];
        if (!($arg instanceof Arg)) {
            return null;
        }

        if (!($arg->value instanceof String_)) {
            return null;
        }

        $configName = $arg->value->value;
        if (!in_array($configName, $this->configNames, true)) {
            $this->configNames[]             = $configName;
            $this->configLines[$configName]  = $node->getStartLine();
        }

        return null;
    }
}

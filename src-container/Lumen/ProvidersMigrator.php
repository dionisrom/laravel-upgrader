<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Arg;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Error as ParserError;

/**
 * Migrates Lumen service provider registrations to `config/app.php` (TRD §10.1).
 *
 * Lumen pattern (bootstrap/app.php):
 *   $app->register(App\Providers\AuthServiceProvider::class);
 *   $app->register(new App\Providers\EventServiceProvider($app));
 *
 * Laravel 9 output (config/app.php, providers array):
 *   App\Providers\AuthServiceProvider::class,
 *   // Note: EventServiceProvider with constructor args needs manual review
 *
 * Emits `lumen_providers_migrated` JSON-ND event.
 */
final class ProvidersMigrator
{
    /**
     * Lumen built-in providers that Laravel already includes by default —
     * skip these to avoid duplicates in the migrated config/app.php.
     */
    private const LUMEN_BUILTIN_PROVIDERS = [
        'Laravel\Lumen\Providers\EventServiceProvider',
        'Laravel\Lumen\Providers\EventServiceProvider::class',
        'Illuminate\Auth\AuthServiceProvider',
        'Illuminate\Broadcasting\BroadcastServiceProvider',
        'Illuminate\Cache\CacheServiceProvider',
        'Illuminate\Foundation\Providers\ConsoleSupportServiceProvider',
        'Illuminate\Cookie\CookieServiceProvider',
        'Illuminate\Database\DatabaseServiceProvider',
        'Illuminate\Encryption\EncryptionServiceProvider',
        'Illuminate\Filesystem\FilesystemServiceProvider',
        'Illuminate\Foundation\Providers\FoundationServiceProvider',
        'Illuminate\Hashing\HashServiceProvider',
        'Illuminate\Mail\MailServiceProvider',
        'Illuminate\Notifications\NotificationServiceProvider',
        'Illuminate\Pagination\PaginationServiceProvider',
        'Illuminate\Pipeline\PipelineServiceProvider',
        'Illuminate\Queue\QueueServiceProvider',
        'Illuminate\Redis\RedisServiceProvider',
        'Illuminate\Auth\Passwords\PasswordResetServiceProvider',
        'Illuminate\Session\SessionServiceProvider',
        'Illuminate\Translation\TranslationServiceProvider',
        'Illuminate\Validation\ValidationServiceProvider',
        'Illuminate\View\ViewServiceProvider',
    ];

    public function migrate(string $workspacePath, string $targetPath): ProvidersMigrationResult
    {
        $bootstrapFile = $workspacePath . '/bootstrap/app.php';
        if (!file_exists($bootstrapFile)) {
            return ProvidersMigrationResult::success([]);
        }

        $code = file_get_contents($bootstrapFile);
        if ($code === false) {
            return ProvidersMigrationResult::failure("Cannot read bootstrap/app.php");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast = $parser->parse($code);
        } catch (ParserError $e) {
            return ProvidersMigrationResult::failure("Parse error: {$e->getMessage()}");
        }

        if ($ast === null) {
            return ProvidersMigrationResult::success([]);
        }

        $collector = new ProviderCallCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        $providers      = [];
        $manualReview   = [];

        foreach ($collector->registrations as $reg) {
            $className = $reg['class'];

            // Skip Lumen built-ins already present in the Laravel scaffold
            if (in_array($className, self::LUMEN_BUILTIN_PROVIDERS, true)) {
                continue;
            }

            if ($reg['has_constructor_args']) {
                $manualReview[] = LumenManualReviewItem::provider(
                    $bootstrapFile,
                    $reg['line'],
                    "Provider {$className} is instantiated with constructor arguments — cannot auto-register.",
                    "Manually add to config/app.php providers array and remove constructor arguments if possible.",
                );
                continue;
            }

            $providers[] = $className;
        }

        if ($providers !== []) {
            $this->appendProvidersToConfig($targetPath, $providers);
        }

        $this->emitManualReviewEvents($manualReview);
        $this->emitEvent('lumen_providers_migrated', [
            'count'    => count($providers),
            'providers' => $providers,
        ]);

        return ProvidersMigrationResult::success($providers, $manualReview);
    }

    /**
     * @param string[] $providers
     */
    private function appendProvidersToConfig(string $targetPath, array $providers): void
    {
        $configFile = $targetPath . '/config/app.php';
        if (!file_exists($configFile)) {
            return;
        }

        $content = (string) file_get_contents($configFile);

        // Find the 'providers' array closing bracket and insert before it.
        // The standard Laravel config/app.php ends the providers array with
        //   App\Providers\RouteServiceProvider::class,
        // followed by whitespace/comments and then `],`
        $insertMarker = 'App\Providers\RouteServiceProvider::class,';
        if (!str_contains($content, $insertMarker)) {
            // fallback: append comment at end of providers section
            $insertMarker = "'providers' =>";
        }

        $lines = implode("\n", array_map(
            fn(string $p) => "        {$p}::class,",
            $providers
        ));

        $replacement = $insertMarker . "\n\n        // Migrated from Lumen bootstrap/app.php\n{$lines}";
        $content = str_replace($insertMarker, $replacement, $content);

        file_put_contents($configFile, $content);
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
 * Collects all $app->register() calls from the bootstrap/app.php AST.
 */
final class ProviderCallCollector extends NodeVisitorAbstract
{
    /**
     * @var array<int, array{class: string, has_constructor_args: bool, line: int}>
     */
    public array $registrations = [];

    public function leaveNode(Node $node): null
    {
        if (!($node instanceof MethodCall)) {
            return null;
        }
        if (!($node->var instanceof Variable) || $node->var->name !== 'app') {
            return null;
        }
        if (!($node->name instanceof Node\Identifier) || $node->name->name !== 'register') {
            return null;
        }

        if (count($node->args) === 0) {
            return null;
        }

        $arg = $node->args[0];
        if (!($arg instanceof Arg)) {
            return null;
        }

        $argValue = $arg->value;

        // $app->register(SomeProvider::class)
        if ($argValue instanceof Node\Expr\ClassConstFetch) {
            $class = $argValue->class;
            if ($class instanceof Node\Name) {
                $this->registrations[] = [
                    'class'                => '\\' . ltrim($class->toString(), '\\'),
                    'has_constructor_args' => false,
                    'line'                 => $node->getStartLine(),
                ];
            }
            return null;
        }

        // $app->register(new SomeProvider($app))
        if ($argValue instanceof New_) {
            $class = $argValue->class;
            if ($class instanceof Node\Name) {
                $this->registrations[] = [
                    'class'                => '\\' . ltrim($class->toString(), '\\'),
                    'has_constructor_args' => count($argValue->args) > 0,
                    'line'                 => $node->getStartLine(),
                ];
            }
            return null;
        }

        return null;
    }
}

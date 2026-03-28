<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Error as ParserError;

/**
 * Migrates `config/app.php` providers array to `bootstrap/providers.php`,
 * removing Laravel framework providers that auto-register in L11.
 *
 * (Design spike §4, TRD-P2SLIM-001)
 */
final class BootstrapProvidersMigrator
{
    /**
     * Laravel framework providers auto-registered in L11 — strip from bootstrap/providers.php.
     * @var string[]
     */
    private const LARAVEL_AUTO_PROVIDERS = [
        'Illuminate\\Auth\\AuthServiceProvider',
        'Illuminate\\Broadcasting\\BroadcastServiceProvider',
        'Illuminate\\Bus\\BusServiceProvider',
        'Illuminate\\Cache\\CacheServiceProvider',
        'Illuminate\\Foundation\\Providers\\ConsoleSupportServiceProvider',
        'Illuminate\\Cookie\\CookieServiceProvider',
        'Illuminate\\Database\\DatabaseServiceProvider',
        'Illuminate\\Encryption\\EncryptionServiceProvider',
        'Illuminate\\Filesystem\\FilesystemServiceProvider',
        'Illuminate\\Foundation\\Providers\\FoundationServiceProvider',
        'Illuminate\\Hashing\\HashServiceProvider',
        'Illuminate\\Log\\LogServiceProvider',
        'Illuminate\\Mail\\MailServiceProvider',
        'Illuminate\\Notifications\\NotificationServiceProvider',
        'Illuminate\\Pagination\\PaginationServiceProvider',
        'Illuminate\\Pipeline\\PipelineServiceProvider',
        'Illuminate\\Queue\\QueueServiceProvider',
        'Illuminate\\Redis\\RedisServiceProvider',
        'Illuminate\\Auth\\Passwords\\PasswordResetServiceProvider',
        'Illuminate\\Session\\SessionServiceProvider',
        'Illuminate\\Translation\\TranslationServiceProvider',
        'Illuminate\\Validation\\ValidationServiceProvider',
        'Illuminate\\View\\ViewServiceProvider',
    ];

    public function migrate(string $workspacePath): ProvidersBootstrapResult
    {
        $configFile = $workspacePath . '/config/app.php';

        if (!file_exists($configFile)) {
            return ProvidersBootstrapResult::noConfigApp();
        }

        $code = file_get_contents($configFile);
        if ($code === false) {
            return ProvidersBootstrapResult::failure("Cannot read {$configFile}");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast    = $parser->parse($code);
        } catch (ParserError $e) {
            return ProvidersBootstrapResult::failure("Parse error in {$configFile}: {$e->getMessage()}");
        }

        if ($ast === null) {
            return ProvidersBootstrapResult::failure("Empty AST from {$configFile}");
        }

        $visitor   = new ConfigAppProvidersVisitor();
        $traverser = new NodeTraverser();
        // NameResolver is required: earlier hops run Rector with withImportNames(),
        // which converts FQCNs to short imports. Without NameResolver, toString()
        // returns the short name (e.g. "AuthServiceProvider") instead of its FQCN,
        // producing invalid bootstrap/providers.php entries.
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $manualReviewItems = [];

        // Filter out auto-registering framework providers
        $appProviders = array_values(array_filter(
            $visitor->providers,
            fn(string $fqcn) => !$this->isAutoProvider($fqcn)
        ));

        // Write bootstrap/providers.php
        $bootstrapDir = $workspacePath . '/bootstrap';
        if (is_dir($bootstrapDir)) {
            $providersFile = $bootstrapDir . '/providers.php';
            if (!file_exists($providersFile)) {
                $content = $this->generateProvidersFile($appProviders);
                file_put_contents($providersFile, $content);
            } else {
                $manualReviewItems[] = SlimSkeletonManualReviewItem::providers(
                    $providersFile,
                    0,
                    'bootstrap/providers.php already exists — skipped overwrite.',
                    'info',
                    'Manually merge any additional providers from config/app.php into bootstrap/providers.php.'
                );
            }
        }

        $this->emitEvent('slim_providers_migrated', [
            'workspace'        => $workspacePath,
            'provider_count'   => count($appProviders),
            'manual_review'    => count($manualReviewItems),
        ]);

        return ProvidersBootstrapResult::success($appProviders, $manualReviewItems);
    }

    private function isAutoProvider(string $fqcn): bool
    {
        $normalised = ltrim($fqcn, '\\');
        foreach (self::LARAVEL_AUTO_PROVIDERS as $auto) {
            if (ltrim($auto, '\\') === $normalised) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $providers
     */
    private function generateProvidersFile(array $providers): string
    {
        $lines = ["<?php\n\ndeclare(strict_types=1);\n\nreturn ["];
        foreach ($providers as $provider) {
            $lines[] = "    {$provider}::class,";
        }
        $lines[] = "];\n";
        return implode("\n", $lines);
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
 * Extracts the 'providers' array from config/app.php.
 */
final class ConfigAppProvidersVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    public array $providers = [];

    public function leaveNode(Node $node): null
    {
        // Looking for: 'providers' => [...] in the top-level return array
        if (!$node instanceof ArrayItem || $node->key === null) {
            return null;
        }

        $key = $node->key;
        if (!$key instanceof Node\Scalar\String_ || $key->value !== 'providers') {
            return null;
        }

        if (!$node->value instanceof Array_) {
            return null;
        }

        foreach ($node->value->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }
            if (
                $item->value instanceof Node\Expr\ClassConstFetch &&
                $item->value->class instanceof Node\Name
            ) {
                $class    = $item->value->class;
                $resolved = $class->getAttribute('resolvedName');
                $this->providers[] = $resolved instanceof Node\Name\FullyQualified
                    ? $resolved->toString()
                    : $class->toString();
            }
        }

        return null;
    }
}

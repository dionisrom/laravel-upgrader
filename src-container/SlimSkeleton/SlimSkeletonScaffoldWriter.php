<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

/**
 * Assembles all migrator partial outputs and writes the final `bootstrap/app.php`
 * for a Laravel 11 application.
 *
 * The writer implements idempotency: if `bootstrap/app.php` already contains
 * the L11 Application::configure() pattern, it skips writing and returns early.
 *
 * (Design spike §7, TRD-P2SLIM-001)
 */
final class SlimSkeletonScaffoldWriter
{
    /**
     * Signature that indicates L11 slim skeleton is already present.
     */
    private const SLIM_SKELETON_SIGNATURE = 'Application::configure';

    public function write(
        string $workspacePath,
        KernelMigrationResult $kernelResult,
        ExceptionHandlerMigrationResult $handlerResult,
        ConsoleKernelMigrationResult $consoleResult,
        RouteServiceProviderMigrationResult $routesResult,
    ): bool {
        $bootstrapFile = $workspacePath . '/bootstrap/app.php';

        // Idempotency check — already a slim skeleton
        if (file_exists($bootstrapFile)) {
            $existing = file_get_contents($bootstrapFile);
            if ($existing !== false && str_contains($existing, self::SLIM_SKELETON_SIGNATURE)) {
                $this->emitEvent('slim_skeleton_already_present', [
                    'workspace' => $workspacePath,
                    'file'      => $bootstrapFile,
                ]);
                return true;
            }
        }

        $content = $this->buildBootstrapApp($kernelResult, $handlerResult, $consoleResult, $routesResult);

        $bootstrapDir = dirname($bootstrapFile);
        if (!is_dir($bootstrapDir) && !mkdir($bootstrapDir, 0755, true) && !is_dir($bootstrapDir)) {
            return false;
        }

        $result = file_put_contents($bootstrapFile, $content);
        if ($result === false) {
            return false;
        }

        $this->emitEvent('slim_skeleton_written', [
            'workspace' => $workspacePath,
            'file'      => $bootstrapFile,
            'bytes'     => strlen($content),
        ]);

        return true;
    }

    private function buildBootstrapApp(
        KernelMigrationResult $kernel,
        ExceptionHandlerMigrationResult $handler,
        ConsoleKernelMigrationResult $console,
        RouteServiceProviderMigrationResult $routes,
    ): string {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Illuminate\Foundation\Application;',
            'use Illuminate\Foundation\Configuration\Exceptions;',
            'use Illuminate\Foundation\Configuration\Middleware;',
            '',
            'return Application::configure(basePath: dirname(__DIR__))',
            $this->buildWithRouting($routes),
            $this->buildWithMiddleware($kernel),
            $this->buildWithExceptions($handler),
        ];

        // Add withCommands if console kernel has command classes
        if ($console->commandClasses !== []) {
            $lines[] = $this->buildWithCommands($console);
        }

        $lines[] = '    ->create();';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function buildWithRouting(RouteServiceProviderMigrationResult $routes): string
    {
        $args = [];
        if ($routes->webRoutes !== null) {
            $args[] = "        web: {$routes->webRoutes}";
        }
        if ($routes->apiRoutes !== null) {
            $args[] = "        api: {$routes->apiRoutes}";
        }
        if ($routes->consoleRoutes !== null) {
            $args[] = "        commands: {$routes->consoleRoutes}";
        }
        $args[] = "        health: '/up'";

        $argList = implode(",\n", $args);
        return "    ->withRouting(\n{$argList}\n    )";
    }

    private function buildWithMiddleware(KernelMigrationResult $kernel): string
    {
        $body = [];

        if ($kernel->appendedGlobalMiddleware !== []) {
            $entries = implode(",\n        ", array_map(
                fn(string $m) => '\\' . ltrim($m, '\\') . '::class',
                $kernel->appendedGlobalMiddleware
            ));
            $body[] = "    \$middleware->append([\n        {$entries},\n    ]);";
        }

        foreach ($kernel->middlewareGroupDeltas as $group => $entries) {
            $entryList = implode(",\n        ", array_map(
                fn(string $e) => '\\' . ltrim($e, '\\') . '::class',
                $entries
            ));
            $body[] = "    \$middleware->{$group}(append: [\n        {$entryList},\n    ]);";
        }

        if ($kernel->middlewareAliases !== []) {
            $aliasList = [];
            foreach ($kernel->middlewareAliases as $alias => $fqcn) {
                $aliasList[] = "        '{$alias}' => \\" . ltrim($fqcn, '\\') . "::class";
            }
            $body[] = "    \$middleware->alias([\n" . implode(",\n", $aliasList) . ",\n    ]);";
        }

        if ($kernel->middlewarePriority !== []) {
            $priorityList = implode(",\n        ", array_map(
                fn(string $m) => '\\' . ltrim($m, '\\') . '::class',
                $kernel->middlewarePriority
            ));
            $body[] = "    \$middleware->priority([\n        {$priorityList},\n    ]);";
        }

        if ($kernel->trustProxiesAt !== null) {
            $at = $kernel->trustProxiesAt;
            $body[] = "    \$middleware->trustProxies(at: ['" . str_replace(',', "', '", $at) . "']);";
        }

        $bodyCode = $body !== []
            ? "\n" . implode("\n", $body) . "\n"
            : '';

        return "    ->withMiddleware(function (Middleware \$middleware) {{$bodyCode}})";  
    }

    private function buildWithExceptions(ExceptionHandlerMigrationResult $handler): string
    {
        $body = [];

        if ($handler->dontReport !== []) {
            $classes = implode(",\n        ", array_map(
                fn(string $c) => '\\' . ltrim($c, '\\') . '::class',
                $handler->dontReport
            ));
            $body[] = "    \$exceptions->dontReport([\n        {$classes},\n    ]);";
        }

        if ($handler->dontFlash !== []) {
            $values = implode(",\n        ", array_map(fn(string $k) => "'{$k}'", $handler->dontFlash));
            $body[] = "    \$exceptions->dontFlash([\n        {$values},\n    ]);";
        }

        foreach ($handler->reportClosures as $closure) {
            $body[] = "    \$exceptions->report({$closure});";
        }

        foreach ($handler->renderClosures as $closure) {
            $body[] = "    \$exceptions->render({$closure});";
        }

        $bodyCode = $body !== []
            ? "\n" . implode("\n", $body) . "\n"
            : '';

        return "    ->withExceptions(function (Exceptions \$exceptions) {{$bodyCode}})";
    }

    private function buildWithCommands(ConsoleKernelMigrationResult $console): string
    {
        $classes = implode(",\n    ", array_map(
            fn(string $c) => '\\' . ltrim($c, '\\') . '::class',
            $console->commandClasses
        ));
        return "    ->withCommands([\n    {$classes},\n    ])";
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

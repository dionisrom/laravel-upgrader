<?php

declare(strict_types=1);

namespace AppContainer\SlimSkeleton;

/**
 * Main orchestrator for the L10→L11 slim skeleton migration.
 *
 * Coordinates all migrator classes in sequence and assembles
 * the final `bootstrap/app.php`. Emits JSON-ND events throughout.
 *
 * Invoked by the hop-10-to-11 container entrypoint.
 *
 * Usage: php SlimSkeletonGenerator.php /path/to/workspace
 *
 * (Design spike §6, TRD-P2SLIM-001)
 */
final class SlimSkeletonGenerator
{
    public function __construct(
        private readonly KernelMigrator $kernelMigrator,
        private readonly ExceptionHandlerMigrator $handlerMigrator,
        private readonly ConsoleKernelMigrator $consoleMigrator,
        private readonly BootstrapProvidersMigrator $providersMigrator,
        private readonly RouteServiceProviderMigrator $routesMigrator,
        private readonly ConfigDefaultsAuditor $configAuditor,
        private readonly SlimSkeletonScaffoldWriter $scaffoldWriter,
        private readonly SlimSkeletonAuditReport $auditReport,
    ) {}

    public static function create(): self
    {
        return new self(
            kernelMigrator: new KernelMigrator(),
            handlerMigrator: new ExceptionHandlerMigrator(),
            consoleMigrator: new ConsoleKernelMigrator(),
            providersMigrator: new BootstrapProvidersMigrator(),
            routesMigrator: new RouteServiceProviderMigrator(),
            configAuditor: new ConfigDefaultsAuditor(),
            scaffoldWriter: new SlimSkeletonScaffoldWriter(),
            auditReport: new SlimSkeletonAuditReport(),
        );
    }

    public function generate(string $workspacePath): SlimSkeletonAuditResult
    {
        $this->emitEvent('slim_skeleton_start', ['workspace' => $workspacePath]);

        // --- Step 1: HTTP Kernel migration ---
        $kernelResult = $this->kernelMigrator->migrate($workspacePath);
        if (!$kernelResult->success) {
            $this->emitError('KernelMigrator', $kernelResult->errorMessage ?? 'Unknown error');
        }
        $this->auditReport->addItems($kernelResult->manualReviewItems);

        // --- Step 2: Exception Handler migration ---
        $handlerResult = $this->handlerMigrator->migrate($workspacePath);
        if (!$handlerResult->success) {
            $this->emitError('ExceptionHandlerMigrator', $handlerResult->errorMessage ?? 'Unknown error');
        }
        $this->auditReport->addItems($handlerResult->manualReviewItems);

        // --- Step 3: Console Kernel migration ---
        $consoleResult = $this->consoleMigrator->migrate($workspacePath);
        if (!$consoleResult->success) {
            $this->emitError('ConsoleKernelMigrator', $consoleResult->errorMessage ?? 'Unknown error');
        }
        $this->auditReport->addItems($consoleResult->manualReviewItems);

        // --- Step 4: bootstrap/providers.php ---
        $providersResult = $this->providersMigrator->migrate($workspacePath);
        if (!$providersResult->success) {
            $this->emitError('BootstrapProvidersMigrator', $providersResult->errorMessage ?? 'Unknown error');
        }
        $this->auditReport->addItems($providersResult->manualReviewItems);

        // --- Step 5: RouteServiceProvider migration ---
        $routesResult = $this->routesMigrator->migrate($workspacePath);
        if (!$routesResult->success) {
            $this->emitError('RouteServiceProviderMigrator', $routesResult->errorMessage ?? 'Unknown error');
        }
        $this->auditReport->addItems($routesResult->manualReviewItems);

        // --- Step 6: Config defaults audit ---
        $configResult = $this->configAuditor->audit($workspacePath);
        if (!$configResult->success) {
            $this->emitError('ConfigDefaultsAuditor', $configResult->errorMessage ?? 'Unknown error');
        }
        $this->auditReport->addItems($configResult->manualReviewItems);

        // --- Step 7: Write bootstrap/app.php ---
        $written = $this->scaffoldWriter->write(
            workspacePath: $workspacePath,
            kernelResult: $kernelResult,
            handlerResult: $handlerResult,
            consoleResult: $consoleResult,
            routesResult: $routesResult,
        );

        if (!$written) {
            $this->emitError('SlimSkeletonScaffoldWriter', 'Failed to write bootstrap/app.php');
        }

        // --- Step 8: Console schedule → routes/console.php ---
        if ($consoleResult->scheduleStatements !== []) {
            $this->writeConsoleRoutes($workspacePath, $consoleResult);
        }

        // --- Step 9: Audit report ---
        $summary = [
            'kernel_file_existed'    => $kernelResult->kernelFileExists,
            'handler_file_existed'   => $handlerResult->handlerFileExists,
            'console_kernel_existed' => $consoleResult->consoleKernelExists,
            'providers_count'        => count($providersResult->providers),
            'all_backup_files'       => array_merge(
                $kernelResult->backupFiles,
                $handlerResult->backupFiles,
                $consoleResult->backupFiles,
            ),
        ];

        $result = $this->auditReport->generate($workspacePath, $summary);

        $this->emitEvent('slim_skeleton_complete', [
            'workspace'      => $workspacePath,
            'manual_review'  => $result->totalManualReviewItems,
            'errors'         => $result->errorCount,
            'warnings'       => $result->warningCount,
        ]);

        return $result;
    }

    private function writeConsoleRoutes(string $workspacePath, ConsoleKernelMigrationResult $console): void
    {
        $routesFile  = $workspacePath . '/routes/console.php';
        $routesDir   = dirname($routesFile);

        if (!is_dir($routesDir)) {
            return;
        }

        // Idempotency: if file already has Schedule:: calls, skip
        if (file_exists($routesFile)) {
            $existing = file_get_contents($routesFile);
            if ($existing !== false && str_contains($existing, 'Schedule::')) {
                return;
            }
        }

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Illuminate\\Support\\Facades\\Schedule;',
            '',
        ];

        foreach ($console->scheduleStatements as $stmt) {
            $lines[] = $stmt;
        }

        $lines[] = '';

        file_put_contents($routesFile, implode("\n", $lines));

        $this->emitEvent('slim_console_routes_written', [
            'workspace'   => $workspacePath,
            'file'        => $routesFile,
            'statements'  => count($console->scheduleStatements),
        ]);
    }

    private function emitError(string $stage, string $message): void
    {
        $payload = [
            'event'   => 'slim_skeleton_stage_error',
            'stage'   => $stage,
            'message' => $message,
        ];
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
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

// CLI entry point
if (isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    // Bootstrap the Composer autoloader so cross-file class references resolve.
    // In the container the layout is /upgrader/src/SlimSkeleton/SlimSkeletonGenerator.php
    // and the vendor directory is /upgrader/vendor/autoload.php (two levels up from __DIR__).
    $autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoloader)) {
        $payload = ['event' => 'config_error', 'error' => "Autoloader not found: {$autoloader}"];
        echo json_encode($payload) . "\n";
        exit(2);
    }
    require_once $autoloader;

    if (!isset($argv[1])) {
        fwrite(STDERR, "Usage: php SlimSkeletonGenerator.php <workspace_path>\n");
        exit(2);
    }

    $workspacePath = rtrim($argv[1], '/');

    if (!is_dir($workspacePath)) {
        $payload = ['event' => 'config_error', 'error' => "Workspace not found: {$workspacePath}"];
        echo json_encode($payload) . "\n";
        exit(2);
    }

    $generator = SlimSkeletonGenerator::create();
    $result    = $generator->generate($workspacePath);

    exit($result->errorCount > 0 ? 1 : 0);
}

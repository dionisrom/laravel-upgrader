<?php

declare(strict_types=1);

namespace AppContainer\Lumen;

use AppContainer\Composer\GitSafeDirectoryManager;
use AppContainer\Report\ReportBuilder;
use AppContainer\Report\ReportData;
use AppContainer\Verification\PhpStanVerifier;
use AppContainer\Verification\StaticArtisanVerifier;
use AppContainer\Verification\SyntaxVerifier;
use AppContainer\Verification\VerificationContext;
use AppContainer\Verification\VerificationIssue;
use AppContainer\Verification\VerificationPipeline;
use AppContainer\Verification\VerifierInterface;
use AppContainer\Verification\VerifierResult;
use Symfony\Component\Process\Process;

final class LumenMigrationPipeline
{
    private const HOP = 'lumen-migrator';

    /** @var list<array<string, mixed>> */
    private array $auditEvents = [];

    /** @var list<LumenManualReviewItem> */
    private array $manualReviewItems = [];

    /** @var list<string> */
    private array $changedFiles = [];

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $assetsDir,
        private readonly string $rectorConfigPath,
        private readonly string $composerBin = 'composer',
        private readonly GitSafeDirectoryManager $safeDirectoryManager = new GitSafeDirectoryManager(),
    ) {}

    public function run(): void
    {
        $stateDir = $this->workspacePath . '/.upgrader';
        $targetPath = $stateDir . '/laravel-target-' . bin2hex(random_bytes(4));
        $reportDir = $stateDir;

        $this->ensureDirectory($stateDir);
        $this->ensureDirectory($targetPath);

        $lumenDetector = new LumenDetector(new \AppContainer\Detector\FrameworkDetector());
        $detection = $this->runStage('LumenDetector', fn() => $lumenDetector->detect($this->workspacePath));
        if (!$detection instanceof LumenDetectionResult || $detection->framework !== 'lumen') {
            throw new \RuntimeException('The mounted workspace is not a definitive Lumen application.');
        }

        $scaffoldGenerator = new ScaffoldGenerator(
            composerBin: $this->composerBin,
            templatePath: getenv('UPGRADER_LARAVEL_SCAFFOLD_TEMPLATE') ?: null,
        );
        $scaffoldResult = $this->runStage('ScaffoldGenerator', fn() => $scaffoldGenerator->generate($targetPath, $this->workspacePath));
        if (!$scaffoldResult instanceof ScaffoldResult || !$scaffoldResult->success) {
            throw new \RuntimeException($scaffoldResult instanceof ScaffoldResult ? (string) $scaffoldResult->errorMessage : 'Scaffold generation failed.');
        }

        $this->copyPreservedBootstrapToTarget($targetPath);

        $composerMigrator = new LumenComposerManifestMigrator();
        $composerResult = $this->runStage('ComposerManifestMigrator', fn() => $composerMigrator->migrate($this->workspacePath, $targetPath));
        if ($composerResult instanceof LumenComposerMigrationResult) {
            $this->manualReviewItems = array_merge($this->manualReviewItems, $composerResult->manualReviewItems);
        }

        $this->runStage('SourceOverlay', function () use ($targetPath): bool {
            $this->overlaySourceIntoTarget($this->workspacePath, $targetPath);
            return true;
        });

        $routesResult = $this->runStage('RoutesMigrator', fn() => (new RoutesMigrator())->migrate($this->workspacePath, $targetPath));
        $this->collectResultItems($routesResult);
        $this->recordChangedFiles($routesResult instanceof RoutesMigrationResult ? $routesResult->outputFiles : []);

        $providersResult = $this->runStage('ProvidersMigrator', fn() => (new ProvidersMigrator())->migrate($this->workspacePath, $targetPath));
        $this->collectResultItems($providersResult);
        $this->recordChangedFiles([$targetPath . '/config/app.php']);

        $middlewareResult = $this->runStage('MiddlewareMigrator', fn() => (new MiddlewareMigrator())->migrate($this->workspacePath, $targetPath));
        $this->collectResultItems($middlewareResult);
        $this->recordChangedFiles([$targetPath . '/app/Http/Kernel.php']);

        $exceptionResult = $this->runStage('ExceptionHandlerMigrator', fn() => (new ExceptionHandlerMigrator())->migrate($this->workspacePath, $targetPath));
        $this->collectResultItems($exceptionResult);
        $this->recordChangedFiles([$targetPath . '/app/Exceptions/Handler.php']);

        $configResult = $this->runStage('InlineConfigExtractor', fn() => (new InlineConfigExtractor())->extract($this->workspacePath, $targetPath));
        $this->collectResultItems($configResult);
        if ($configResult instanceof ConfigExtractionResult) {
            foreach (array_merge($configResult->copiedConfigs, $configResult->stubbedConfigs) as $configName) {
                $this->changedFiles[] = $targetPath . '/config/' . $configName . '.php';
            }
        }

        $facadeResult = $this->runStage('FacadeBootstrapMigrator', fn() => (new FacadeBootstrapMigrator())->migrate($this->workspacePath));
        if ($facadeResult instanceof FacadeBootstrapResult && !$facadeResult->facadesEnabled) {
            $this->manualReviewItems[] = LumenManualReviewItem::other(
                'bootstrap/app.php',
                0,
                'Facades were disabled in the Lumen bootstrap but will be available in the migrated Laravel app.',
                'warning',
                'Verify that enabling facades by default does not change application behavior.',
            );
        }

        $eloquentResult = $this->runStage('EloquentBootstrapDetector', fn() => (new EloquentBootstrapDetector())->detect($this->workspacePath));
        if ($eloquentResult instanceof EloquentDetectionResult && $eloquentResult->warning !== null) {
            $this->manualReviewItems[] = LumenManualReviewItem::other(
                'bootstrap/app.php',
                0,
                $eloquentResult->warning,
                'warning',
                'Review database configuration after the Lumen to Laravel migration.',
            );
        }

        $auditReport = new LumenAuditReport();
        $auditReport->addItems($this->manualReviewItems);
        $this->runStage('LumenAuditReport', fn() => $auditReport->generate($this->workspacePath, [
            'manual_review_items' => count($this->manualReviewItems),
        ]));

        if ($this->shouldRunComposerInstall($targetPath)) {
            $this->runStage('ComposerInstall', function () use ($targetPath): bool {
                $this->installTargetDependencies($targetPath);
                return true;
            });
        } else {
            $this->emit('composer_install_skipped', [
                'reason' => $this->composerInstallSkipReason($targetPath),
            ]);
        }

        $this->runStage('RectorRunner', function () use ($targetPath): bool {
            $this->runRector($targetPath);
            return true;
        });

        $verificationResults = $this->runStage('VerificationPipeline', fn() => $this->runVerification($targetPath));
        if (!is_array($verificationResults)) {
            throw new \RuntimeException('Verification pipeline did not return any results.');
        }

        $this->runStage('ReportBuilder', function () use ($targetPath, $reportDir, $verificationResults): bool {
            $this->buildReport($targetPath, $reportDir, $verificationResults);
            return true;
        });

        $this->runStage('WorkspacePromotion', function () use ($targetPath): bool {
            $this->promoteTargetWorkspace($targetPath);
            return true;
        });
    }

    /**
     * @param callable(): mixed $callback
     */
    private function runStage(string $stage, callable $callback): mixed
    {
        $startedAt = microtime(true);
        $this->emit('stage_start', ['stage' => $stage]);

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->emit('stage_error', [
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->emit('stage_complete', [
            'stage' => $stage,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    private function shouldRunComposerInstall(string $targetPath): bool
    {
        $extraCacheDir = getenv('UPGRADER_EXTRA_COMPOSER_CACHE_DIR');
        if (!is_string($extraCacheDir) || trim($extraCacheDir) === '' || !is_dir($extraCacheDir)) {
            return false;
        }

        return is_file($targetPath . '/composer.lock');
    }

    private function composerInstallSkipReason(string $targetPath): string
    {
        $extraCacheDir = getenv('UPGRADER_EXTRA_COMPOSER_CACHE_DIR');
        if (!is_string($extraCacheDir) || trim($extraCacheDir) === '' || !is_dir($extraCacheDir)) {
            return 'No extra Composer cache directory was provided for offline dependency resolution.';
        }

        if (!is_file($targetPath . '/composer.lock')) {
            return 'Skipped Composer install because the migrated Laravel target has no composer.lock and offline update resolution would not be deterministic.';
        }

        return 'Composer install was skipped.';
    }

    private function installTargetDependencies(string $targetPath): void
    {
        $lockFile = $targetPath . '/composer.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        $this->prepareGitSafeDirectory($targetPath);
        [$env, $cleanupDir] = $this->prepareComposerEnvironment();

        $process = new Process([
            $this->composerBin,
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--no-scripts',
        ], $targetPath, $env === [] ? null : $env, null, 600);

        $process->run();

        if ($cleanupDir !== null) {
            $this->removeDirectory($cleanupDir);
        }

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Composer install failed during Lumen migration.');
        }
    }

    private function prepareGitSafeDirectory(string $targetPath): void
    {
        $this->safeDirectoryManager->markDirectory($targetPath);
    }

    /**
     * @return array{array<string, string>, string|null}
     */
    private function prepareComposerEnvironment(): array
    {
        $extraCacheDir = getenv('UPGRADER_EXTRA_COMPOSER_CACHE_DIR');
        if (!is_string($extraCacheDir) || trim($extraCacheDir) === '' || !is_dir($extraCacheDir)) {
            return [[], null];
        }

        $defaultCacheDir = '/home/upgrader/.composer/cache';
        $mergedCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upgrader-lumen-composer-cache-' . bin2hex(random_bytes(4));
        if (!mkdir($mergedCacheDir, 0700, true) && !is_dir($mergedCacheDir)) {
            throw new \RuntimeException("Failed to create merged Composer cache directory: {$mergedCacheDir}");
        }

        if (is_dir($defaultCacheDir)) {
            $this->copyDirectory($defaultCacheDir, $mergedCacheDir);
        }

        $this->copyDirectory($extraCacheDir, $mergedCacheDir);
        $this->safeDirectoryManager->markComposerCacheDirectories($mergedCacheDir);

        return [['COMPOSER_CACHE_DIR' => $mergedCacheDir], $mergedCacheDir];
    }

    private function runRector(string $targetPath): void
    {
        $process = new Process([
            PHP_BINARY,
            '/upgrader/vendor/bin/rector',
            'process',
            $targetPath,
            '--config=' . $this->rectorConfigPath,
            '--no-progress-bar',
        ], $targetPath, null, null, 600);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Rector failed during Lumen migration.');
        }

        $this->emit('rector_completed', [
            'workspace' => $targetPath,
        ]);
    }

    /**
     * @return list<VerifierResult>
     */
    private function runVerification(string $targetPath): array
    {
        $verifiers = [
            new SyntaxVerifier(),
            new StaticArtisanVerifier(),
        ];

        if ((bool) getenv('UPGRADER_SKIP_PHPSTAN') !== true) {
            $verifiers[] = new PhpStanVerifier();
        }

        $pipeline = new VerificationPipeline($verifiers);
        $context = new VerificationContext(
            workspacePath: $targetPath,
            phpBin: PHP_BINARY,
            composerBin: $this->composerBin,
            phpstanBin: '/upgrader/vendor/bin/phpstan',
            withArtisanVerify: getenv('UPGRADER_WITH_ARTISAN_VERIFY') === '1',
            skipPhpStan: getenv('UPGRADER_SKIP_PHPSTAN') === '1',
        );

        $results = $pipeline->run($targetPath, $context);

        $this->emit('verification_summary', [
            'passed' => $pipeline->passed($results),
            'steps' => array_map(
                static fn(VerifierResult $result): array => [
                    'step' => $result->verifierName,
                    'passed' => $result->passed,
                    'issue_count' => $result->issueCount,
                ],
                $results,
            ),
        ]);

        if (!$pipeline->passed($results)) {
            throw new \RuntimeException('Verification failed for the migrated Lumen workspace.');
        }

        return $results;
    }

    /**
     * @param list<VerifierResult> $verificationResults
     */
    private function buildReport(string $targetPath, string $reportDir, array $verificationResults): void
    {
        $composerJson = $this->readJsonFile($targetPath . '/composer.json');
        $repoName = is_array($composerJson) && is_string($composerJson['name'] ?? null)
            ? $composerJson['name']
            : basename($this->workspacePath);

        $reportData = new ReportData(
            repoName: $repoName,
            fromVersion: '8',
            toVersion: '9',
            runId: uniqid('lumen-', true),
            repoSha: substr(hash('sha256', $this->workspacePath), 0, 12),
            hostVersion: 'lumen-migrator',
            timestamp: gmdate('c'),
            fileDiffs: $this->buildFileDiffs($targetPath),
            manualReviewItems: $this->buildManualReviewReportItems(),
            dependencyBlockers: [],
            phpstanRegressions: [],
            verificationResults: array_map(
                static fn(VerifierResult $result): array => [
                    'step' => $result->verifierName,
                    'passed' => $result->passed,
                    'issue_count' => $result->issueCount,
                ],
                $verificationResults,
            ),
            auditEvents: $this->auditEvents,
            hasSyntaxError: $this->hasSyntaxErrors($verificationResults),
            totalFilesScanned: $this->countPhpFiles($targetPath),
            totalFilesChanged: count($this->uniqueRelativeChangedFiles($targetPath)),
        );

        $builder = new ReportBuilder($reportDir, $this->assetsDir);
        $builder->build($reportData);
    }

    private function promoteTargetWorkspace(string $targetPath): void
    {
        foreach (scandir($targetPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $targetPath . DIRECTORY_SEPARATOR . $entry;
            $destinationPath = $this->workspacePath . DIRECTORY_SEPARATOR . $entry;
            $this->copyPath($sourcePath, $destinationPath);
        }
    }

    private function overlaySourceIntoTarget(string $sourcePath, string $targetPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $absolutePath = $item->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($sourcePath) + 1));

            if ($this->shouldSkipOverlayPath($relativePath)) {
                continue;
            }

            $destinationPath = $targetPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if ($item->isDir()) {
                $this->ensureDirectory($destinationPath);
                continue;
            }

            $this->ensureDirectory(dirname($destinationPath));
            if (!copy($absolutePath, $destinationPath)) {
                throw new \RuntimeException("Failed to overlay Lumen source file: {$relativePath}");
            }

            $this->changedFiles[] = $destinationPath;
        }
    }

    private function shouldSkipOverlayPath(string $relativePath): bool
    {
        foreach ([
            '.upgrader',
            'vendor',
            'composer.json',
            'composer.lock',
            'bootstrap/app.php',
            'bootstrap/lumen-app-original.php',
            'config',
            'routes',
            'app/Exceptions/Handler.php',
            'app/Http/Kernel.php',
        ] as $skipPath) {
            if ($relativePath === $skipPath || str_starts_with($relativePath, $skipPath . '/')) {
                return true;
            }
        }

        return false;
    }

    private function copyPreservedBootstrapToTarget(string $targetPath): void
    {
        $preservedBootstrap = $this->workspacePath . '/bootstrap/lumen-app-original.php';
        if (!file_exists($preservedBootstrap)) {
            return;
        }

        $targetBootstrap = $targetPath . '/bootstrap/lumen-app-original.php';
        $this->ensureDirectory(dirname($targetBootstrap));
        if (!copy($preservedBootstrap, $targetBootstrap)) {
            throw new \RuntimeException('Failed to copy preserved Lumen bootstrap into the Laravel target.');
        }

        $this->changedFiles[] = $targetBootstrap;
    }

    private function collectResultItems(mixed $result): void
    {
        if (is_object($result) && property_exists($result, 'manualReviewItems') && is_array($result->manualReviewItems)) {
            /** @var list<LumenManualReviewItem> $items */
            $items = $result->manualReviewItems;
            $this->manualReviewItems = array_merge($this->manualReviewItems, $items);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function recordChangedFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $this->changedFiles[] = $path;
            }
        }
    }

    /**
     * @return list<array{file: string, diff: string, rules: list<string>}>
     */
    private function buildFileDiffs(string $targetPath): array
    {
        $diffs = [];
        foreach ($this->uniqueRelativeChangedFiles($targetPath) as $relativeFile) {
            $diffs[] = [
                'file' => $relativeFile,
                'diff' => '',
                'rules' => [],
            ];
        }

        return $diffs;
    }

    /**
     * @return list<array{id: string, automated: bool, reason: string, files: list<string>}>
     */
    private function buildManualReviewReportItems(): array
    {
        $items = [];

        foreach (array_values($this->manualReviewItems) as $index => $item) {
            $relativeFile = $this->toRelativePath($item->file);
            $items[] = [
                'id' => sprintf('LUMEN-%02d', $index + 1),
                'automated' => false,
                'reason' => $item->description,
                'files' => [$relativeFile],
            ];
        }

        return $items;
    }

    /**
     * @param list<VerifierResult> $results
     */
    private function hasSyntaxErrors(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->verifierName !== 'SyntaxVerifier') {
                continue;
            }

            return !$result->passed;
        }

        return false;
    }

    private function countPhpFiles(string $path): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function uniqueRelativeChangedFiles(string $targetPath): array
    {
        $relativePaths = [];
        foreach ($this->changedFiles as $path) {
            $relativePath = $this->toRelativePath($path, $targetPath);
            if ($relativePath !== '' && !isset($relativePaths[$relativePath])) {
                $relativePaths[$relativePath] = true;
            }
        }

        return array_keys($relativePaths);
    }

    private function toRelativePath(string $path, ?string $targetPath = null): string
    {
        $normalized = str_replace('\\', '/', $path);
        foreach (array_filter([$targetPath, $this->workspacePath]) as $basePath) {
            $normalizedBase = str_replace('\\', '/', (string) $basePath);
            if (str_starts_with($normalized, $normalizedBase . '/')) {
                return substr($normalized, strlen($normalizedBase) + 1);
            }
        }

        return ltrim($normalized, '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function emit(string $event, array $data): void
    {
        $payload = array_merge($data, [
            'event' => $event,
            'hop' => self::HOP,
            'ts' => microtime(true),
        ]);

        $this->auditEvents[] = $payload;
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException("Failed to create directory: {$path}");
        }
    }

    private function resetDirectory(string $path): void
    {
        if (is_dir($path)) {
            $this->removeDirectory($path);
        }

        $this->ensureDirectory($path);
    }

    private function copyPath(string $sourcePath, string $destinationPath): void
    {
        if (is_dir($sourcePath)) {
            $this->copyDirectory($sourcePath, $destinationPath);
            return;
        }

        $this->ensureDirectory(dirname($destinationPath));
        if (!copy($sourcePath, $destinationPath)) {
            throw new \RuntimeException("Failed to copy {$sourcePath} to {$destinationPath}");
        }
    }

    private function copyDirectory(string $sourceDir, string $targetDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = substr($sourcePath, strlen($sourceDir) + 1);
            $destinationPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                $this->ensureDirectory($destinationPath);
                continue;
            }

            $this->ensureDirectory(dirname($destinationPath));
            if (!copy($sourcePath, $destinationPath)) {
                throw new \RuntimeException("Failed to copy {$sourcePath} to {$destinationPath}");
            }
        }
    }

    private function removePath(string $path): void
    {
        if (is_dir($path)) {
            $this->removeDirectory($path);
            return;
        }

        if (file_exists($path) && !unlink($path)) {
            throw new \RuntimeException("Failed to remove file: {$path}");
        }
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                if (!rmdir($itemPath)) {
                    throw new \RuntimeException("Failed to remove directory: {$itemPath}");
                }

                continue;
            }

            if (!unlink($itemPath)) {
                throw new \RuntimeException("Failed to remove file: {$itemPath}");
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException("Failed to remove directory: {$path}");
        }
    }
}

if (isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoloader)) {
        fwrite(STDERR, "Autoloader not found: {$autoloader}\n");
        exit(2);
    }

    require_once $autoloader;

    if (!isset($argv[1])) {
        fwrite(STDERR, "Usage: php LumenMigrationPipeline.php <workspace_path>\n");
        exit(2);
    }

    try {
        (new LumenMigrationPipeline(
            workspacePath: rtrim($argv[1], '/\\'),
            assetsDir: dirname(__DIR__, 2) . '/assets',
            rectorConfigPath: dirname(__DIR__, 2) . '/rector-configs/rector.l8-to-l9.php',
        ))->run();
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
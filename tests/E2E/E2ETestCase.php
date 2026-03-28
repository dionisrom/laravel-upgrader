<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Orchestrator\ChainResumeHandler;
use App\Orchestrator\ChainRunResult;
use App\Orchestrator\ChainRunner;
use App\Orchestrator\DockerRunner;
use App\Orchestrator\EventStreamer;
use App\Orchestrator\MultiHopPlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Base test case for all E2E tests.
 *
 * Provides helpers for Docker availability detection, fixture path resolution,
 * temporary workspace management, and fake DockerRunner construction.
 */
abstract class E2ETestCase extends TestCase
{
    /** @var list<string> Temp dirs created during this test, to be cleaned up. */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Docker / environment guards
    // -------------------------------------------------------------------------

    /**
     * Skips the test if the DOCKER_AVAILABLE environment variable is not set.
     * Set DOCKER_AVAILABLE=1 in CI or locally when a Docker daemon is running.
     */
    protected function requireDocker(): void
    {
        if (getenv('DOCKER_AVAILABLE') !== '1') {
            $this->markTestSkipped(
                'E2E test requires a Docker daemon. Set DOCKER_AVAILABLE=1 to run.',
            );
        }

        foreach ((new MultiHopPlanner())->getHopImages() as $image) {
            $process = new Process(['docker', 'image', 'inspect', $image]);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->markTestSkipped(sprintf('Required Docker image %s is not available.', $image));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Fixture path helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the absolute path to a named fixture directory.
     * Fixture must exist under tests/Fixtures/{name}.
     */
    protected function getFixturePath(string $name): string
    {
        return dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'Fixtures' . \DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Copies a named fixture directory to a fresh temporary workspace and registers
     * the parent temp directory for cleanup in tearDown.
     *
     * Returns the absolute path to the copied workspace directory.
     */
    protected function copyFixtureToTemp(string $name): string
    {
        $src = $this->getFixturePath($name);
        $base = sys_get_temp_dir() . '/upgrader-e2e-' . uniqid('', true);
        $dest = $base . \DIRECTORY_SEPARATOR . $name;

        $this->tempDirs[] = $base;
        mkdir($dest, 0700, true);
        $this->copyDir($src, $dest);

        return $dest;
    }

    /**
     * Creates a fresh empty temporary directory and registers it for cleanup.
     */
    protected function makeTempDir(string $suffix = ''): string
    {
        $dir = sys_get_temp_dir() . '/upgrader-e2e-' . uniqid('', true) . $suffix;
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    // -------------------------------------------------------------------------
    // Fake DockerRunner factories
    // -------------------------------------------------------------------------

    /**
     * Creates a DockerRunner whose process emits the given events as JSON-ND lines
     * on stdout and exits 0.
     *
     * Defaults to a single pipeline_complete(passed=true) event when none provided.
     *
     * @param list<array<string, mixed>> $events
     */
    protected function makeSuccessfulDockerRunner(array $events = []): DockerRunner
    {
        if (empty($events)) {
            $events = [['event' => 'pipeline_complete', 'passed' => true, 'ts' => time()]];
        }

        $lines = implode('', array_map(
            static fn (array $e): string => json_encode($e) . "\n",
            $events,
        ));

        return new DockerRunner(
            processFactory: static function (array $command) use ($lines): Process {
                $escaped = str_replace(
                    ['\\', '"', '$', "\n"],
                    ['\\\\', '\\"', '\\$', '\n'],
                    $lines,
                );

                return new Process(['php', '-r', "echo \"{$escaped}\";"]);
            },
        );
    }

    /**
     * Creates a DockerRunner whose process exits with a non-zero code.
     */
    protected function makeFailingDockerRunner(int $exitCode = 1): DockerRunner
    {
        return new DockerRunner(
            processFactory: static function (array $command) use ($exitCode): Process {
                return new Process(['php', '-r', "exit({$exitCode});"]);
            },
        );
    }

    // -------------------------------------------------------------------------
    // ChainRunner factory
    // -------------------------------------------------------------------------

    /**
     * Builds a fully-wired ChainRunner using the provided DockerRunner.
     * Temporary output and checkpoint directories are auto-created and registered
     * for cleanup.
     */
    protected function makeChainRunner(
        DockerRunner $dockerRunner,
        ?string $outputBaseDir = null,
        ?string $checkpointDir = null,
    ): ChainRunner {
        $outputBaseDir ??= $this->makeTempDir('-output');
        $checkpointDir ??= $this->makeTempDir('-checkpoints');

        return new ChainRunner(
            planner:       new MultiHopPlanner(),
            dockerRunner:  $dockerRunner,
            streamer:      new EventStreamer(),
            resumeHandler: new ChainResumeHandler(),
            outputBaseDir: $outputBaseDir,
            checkpointDir: $checkpointDir,
        );
    }

    protected function makeRealChainRunner(
        ?string $outputBaseDir = null,
        ?string $checkpointDir = null,
        int $timeoutSeconds = 1800,
    ): ChainRunner {
        return $this->makeChainRunner(
            new DockerRunner(timeoutSeconds: $timeoutSeconds),
            $outputBaseDir,
            $checkpointDir,
        );
    }

    protected function runFixtureChain(string $fixtureName, bool $resume = false): ChainRunResult
    {
        $workspace = $this->copyFixtureToTemp($fixtureName);
        $runner    = $this->makeRealChainRunner();

        return $runner->run($workspace, '8', '13', $resume);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readJsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, sprintf('Expected valid JSON at %s', $path));

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readComposerManifest(string $workspacePath): array
    {
        return $this->readJsonFile($workspacePath . '/composer.json');
    }

    protected function assertChainCompleted(ChainRunResult $result, int $expectedHops = 5): void
    {
        self::assertCount($expectedHops, $result->hops);

        foreach ($result->hopEvents as $hopKey => $events) {
            $eventNames = array_column($events, 'event');
            self::assertContains('pipeline_complete', $eventNames, sprintf('Hop %s did not complete.', $hopKey));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertUnifiedReportGenerated(ChainRunResult $result): array
    {
        self::assertNotNull($result->reportHtmlPath);
        self::assertNotNull($result->reportJsonPath);
        self::assertFileExists($result->reportHtmlPath);
        self::assertFileExists($result->reportJsonPath);

        $report = $this->readJsonFile($result->reportJsonPath);
        self::assertArrayHasKey('hops', $report);
        self::assertArrayHasKey('total_files_changed', $report);
        self::assertArrayHasKey('total_manual_review_items', $report);

        $totalFilesChanged = 0;
        $totalManualReviewItems = 0;

        foreach ($report['hops'] as $hopReport) {
            self::assertIsArray($hopReport);
            self::assertArrayHasKey('changed_files', $hopReport);
            self::assertArrayHasKey('manual_review_items', $hopReport);
            self::assertIsArray($hopReport['changed_files']);
            self::assertIsArray($hopReport['manual_review_items']);
            self::assertSame($hopReport['files_changed'], count($hopReport['changed_files']));
            self::assertSame($hopReport['manual_review'], count($hopReport['manual_review_items']));

            if (array_key_exists('resource_usage', $hopReport) && $hopReport['resource_usage'] !== null) {
                self::assertIsArray($hopReport['resource_usage']);
                self::assertArrayHasKey('memory_peak_bytes', $hopReport['resource_usage']);
            }

            $totalFilesChanged += (int) $hopReport['files_changed'];
            $totalManualReviewItems += (int) $hopReport['manual_review'];
        }

        self::assertSame($totalFilesChanged, (int) $report['total_files_changed']);
        self::assertSame($totalManualReviewItems, (int) $report['total_manual_review_items']);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertFinalOutputPhpStanLevel6Passes(string $workspacePath): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $phpstanCommand = $this->resolveRootPhpStanCommand($repoRoot);
        $autoloadFile = $workspacePath . '/vendor/autoload.php';

        self::assertFileExists($autoloadFile, 'Upgraded workspace is missing vendor/autoload.php.');

        $paths = [];
        foreach (['app', 'bootstrap', 'config', 'database', 'modules', 'routes', 'tests'] as $path) {
            if (is_dir($workspacePath . '/' . $path)) {
                $paths[] = $path;
            }
        }

        if ($paths === []) {
            $paths[] = '.';
        }

        $process = new Process(
            array_merge(
                $phpstanCommand,
                [
                    'analyse',
                    '--level=6',
                    '--no-progress',
                    '--error-format=json',
                    '--memory-limit=512M',
                    '--autoload-file=' . $autoloadFile,
                ],
                $paths,
            ),
            $workspacePath,
        );

        $process->setTimeout(600);
        $process->run();

        $decoded = json_decode($process->getOutput(), true);
        self::assertIsArray(
            $decoded,
            sprintf(
                'Expected JSON output from PHPStan level 6 run. stderr: %s',
                trim($process->getErrorOutput()),
            ),
        );

        $totalErrors = (int) (($decoded['totals']['file_errors'] ?? 0) + ($decoded['totals']['other_errors'] ?? 0));
        self::assertSame(
            0,
            $totalErrors,
            sprintf(
                'Final upgraded output failed PHPStan level 6 with %d errors. stderr: %s stdout: %s',
                $totalErrors,
                trim($process->getErrorOutput()),
                trim($process->getOutput()),
            ),
        );

        return $decoded;
    }

    /**
     * @return non-empty-list<string>
     */
    protected function resolveRootPhpStanCommand(?string $repoRoot = null): array
    {
        $repoRoot ??= dirname(__DIR__, 2);

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                ['path' => $repoRoot . '/vendor/bin/phpstan.phar.bat', 'use_php' => false],
                ['path' => $repoRoot . '/vendor/bin/phpstan.bat', 'use_php' => false],
                ['path' => $repoRoot . '/vendor/bin/phpstan.phar', 'use_php' => true],
                ['path' => $repoRoot . '/vendor/bin/phpstan', 'use_php' => true],
            ]
            : [
                ['path' => $repoRoot . '/vendor/bin/phpstan', 'use_php' => true],
                ['path' => $repoRoot . '/vendor/bin/phpstan.phar', 'use_php' => true],
            ];

        foreach ($candidates as $candidate) {
            if (!is_file($candidate['path'])) {
                continue;
            }

            return $candidate['use_php']
                ? [PHP_BINARY, $candidate['path']]
                : [$candidate['path']];
        }

        self::fail(sprintf(
            'Root phpstan binary is required for final-output validation. Looked for: %s',
            implode(', ', array_column($candidates, 'path')),
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function assertPerHopContainerMemoryWithinBudget(ChainRunResult $result): array
    {
        $budgetBytes = $this->containerMemoryBudgetBytes();
        $telemetryByHop = [];

        foreach ($result->hopEvents as $hopKey => $events) {
            $telemetryEvents = array_values(array_filter(
                $events,
                static fn (array $event): bool => ($event['event'] ?? '') === 'container_resource_usage',
            ));

            self::assertCount(1, $telemetryEvents, sprintf('Expected one container_resource_usage event for hop %s.', $hopKey));

            $telemetry = $telemetryEvents[0];
            self::assertNotNull($telemetry['memory_peak_bytes'] ?? null, sprintf('Hop %s did not report memory_peak_bytes.', $hopKey));

            $peakBytes = (int) $telemetry['memory_peak_bytes'];
            self::assertGreaterThan(0, $peakBytes, sprintf('Hop %s reported a non-positive memory peak.', $hopKey));
            self::assertLessThanOrEqual(
                $budgetBytes,
                $peakBytes,
                sprintf('Hop %s exceeded the configured container memory budget (%d bytes).', $hopKey, $budgetBytes),
            );

            $telemetryByHop[$hopKey] = $telemetry;
        }

        return $telemetryByHop;
    }

    /**
     * @return list<string>
     */
    protected function changedFilesFromReport(array $report): array
    {
        $files = [];

        foreach ($report['hops'] as $hopReport) {
            foreach ((array) ($hopReport['changed_files'] ?? []) as $file) {
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function containerMemoryBudgetBytes(): int
    {
        return (int) ($this->floatFromEnv('E2E_MAX_CONTAINER_MEMORY_MB', 512.0) * 1024 * 1024);
    }

    private function floatFromEnv(string $key, float $default): float
    {
        $value = getenv($key);

        return is_string($value) && is_numeric($value) ? (float) $value : $default;
    }

    protected function assertComposerConstraintContains(array $composer, string $package, string $expected): void
    {
        $constraint = $composer['require'][$package] ?? null;
        self::assertIsString($constraint, sprintf('Expected %s to remain in composer.json', $package));
        self::assertStringContainsString($expected, $constraint);
    }

    // -------------------------------------------------------------------------
    // Filesystem helpers
    // -------------------------------------------------------------------------

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function copyDir(string $src, string $dest): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            $target = $dest . substr($item->getPathname(), strlen($src));

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0700, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}

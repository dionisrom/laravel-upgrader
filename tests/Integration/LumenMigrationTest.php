<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the Lumen migration container.
 * Mounts a Lumen 8 fixture into a temp directory and runs
 * upgrader/lumen-migrator:latest against it.
 *
 * Requirements:
 *   - Docker daemon must be running
 *   - Image upgrader/lumen-migrator:latest must be built
 *
 * Run via: composer test:integration
 */
#[Group('integration')]
final class LumenMigrationTest extends TestCase
{
    private const DOCKER_IMAGE = 'upgrader/lumen-migrator:latest';
    private const FIXTURE_DIR  = __DIR__ . '/../Fixtures/lumen-8-sample';

    private string $tempWorkspace = '';
    private string $originalFixtureHash = '';

    protected function setUp(): void
    {
        if (!$this->isDockerAvailable()) {
            self::markTestSkipped('Docker is not available in this environment.');
        }

        if (!$this->isImageAvailable(self::DOCKER_IMAGE)) {
            self::markTestSkipped(sprintf(
                'Docker image %s is not available. Run: docker buildx bake lumen-migrator',
                self::DOCKER_IMAGE,
            ));
        }

        if (!is_dir(self::FIXTURE_DIR)) {
            self::markTestSkipped('lumen-8-sample fixture directory does not exist.');
        }

        $this->tempWorkspace = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'upgrader-lumen-' . uniqid('', true);

        $this->copyDirectory(self::FIXTURE_DIR, $this->tempWorkspace);
        $this->originalFixtureHash = $this->hashDirectory(self::FIXTURE_DIR);
    }

    protected function tearDown(): void
    {
        if ($this->tempWorkspace !== '' && is_dir($this->tempWorkspace)) {
            $this->removeDirectory($this->tempWorkspace);
        }
    }

    public function testLumenMigrationOnSampleFixture(): void
    {
        $command = [
            'docker', 'run', '--rm',
            '--network=none',
            '-v', "{$this->tempWorkspace}:/workspace:rw",
            '--env', 'UPGRADER_WORKSPACE=/workspace',
            self::DOCKER_IMAGE,
        ];

        [$exitCode, $stdout, $stderr] = $this->runProcess($command, 300);

        // 1. Container must exit successfully
        self::assertSame(
            0,
            $exitCode,
            sprintf(
                "Lumen migrator container exited with code %d.\nStderr:\n%s",
                $exitCode,
                $stderr,
            ),
        );

        // 2. Parse JSON-ND events from stdout
        $events = $this->parseJsonNd($stdout);
        $eventTypes = array_column($events, 'event');

        // 3. Container must emit pipeline_complete
        self::assertContains(
            'pipeline_complete',
            $eventTypes,
            sprintf(
                "Expected 'pipeline_complete' event.\nEvents: %s\nStdout:\n%s",
                implode(', ', $eventTypes),
                $stdout,
            ),
        );

        // 4. Verify report.json was written to the workspace
        $reportPath    = $this->tempWorkspace . DIRECTORY_SEPARATOR . '.upgrader' . DIRECTORY_SEPARATOR . 'report.json';
        $reportPathAlt = $this->tempWorkspace . DIRECTORY_SEPARATOR . 'report.json';
        $actualReport  = file_exists($reportPath) ? $reportPath : $reportPathAlt;

        self::assertFileExists(
            $actualReport,
            'report.json was not written to the workspace by the lumen-migrator container.',
        );

        // 5. Parse report confidence
        $reportJson = file_get_contents($actualReport);
        self::assertNotFalse($reportJson, 'Failed to read report.json');

        /** @var array<string, mixed>|null $report */
        $report = json_decode((string) $reportJson, true);
        self::assertIsArray($report, 'report.json is not valid JSON');

        $confidence = $this->extractConfidence($report);
        self::assertGreaterThan(
            80,
            $confidence,
            sprintf(
                'Lumen migration: expected confidence > 80, got %s.',
                $confidence,
            ),
        );

        // 6. Write events to audit.log.json for inspection
        $auditLogPath = $this->tempWorkspace . DIRECTORY_SEPARATOR . 'audit.log.json';
        $auditLines = array_map(
            static fn (array $event): string => (string) json_encode(
                array_merge($event, ['hop' => 'lumen-migration']),
                JSON_UNESCAPED_SLASHES,
            ),
            $events,
        );
        file_put_contents($auditLogPath, implode("\n", $auditLines) . "\n");

        self::assertFileExists($auditLogPath);

        $auditContent = (string) file_get_contents($auditLogPath);
        self::assertStringContainsString(
            'pipeline_complete',
            $auditContent,
            "audit.log.json does not contain pipeline_complete event",
        );

        // 7. Original fixture must be unmodified
        $currentHash = $this->hashDirectory(self::FIXTURE_DIR);
        self::assertSame(
            $this->originalFixtureHash,
            $currentHash,
            'Original Lumen fixture files were modified by the integration test.',
        );
    }

    public function testLumenBootstrapFileIsTransformed(): void
    {
        $command = [
            'docker', 'run', '--rm',
            '--network=none',
            '-v', "{$this->tempWorkspace}:/workspace:rw",
            '--env', 'UPGRADER_WORKSPACE=/workspace',
            self::DOCKER_IMAGE,
        ];

        [$exitCode, $stdout] = $this->runProcess($command, 300);

        self::assertSame(0, $exitCode);

        // Verify the Lumen bootstrap/app.php was processed
        $bootstrapPath = $this->tempWorkspace . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        self::assertFileExists($bootstrapPath, 'bootstrap/app.php must exist in the workspace');

        $bootstrapContent = (string) file_get_contents($bootstrapPath);

        // The migrated bootstrap should not reference Laravel\Lumen\Application
        // after migration it should reference the standard Laravel Application
        self::assertStringNotContainsString(
            'Laravel\\Lumen\\Application',
            $bootstrapContent,
            'Lumen Application reference was not migrated in bootstrap/app.php',
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function isDockerAvailable(): bool
    {
        $result = shell_exec('docker info 2>/dev/null');
        return is_string($result) && str_contains($result, 'Server Version');
    }

    private function isImageAvailable(string $image): bool
    {
        $escaped = escapeshellarg($image);
        $result  = shell_exec("docker image inspect {$escaped} 2>/dev/null");
        return is_string($result) && str_contains($result, 'Id');
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private function runProcess(array $command, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($proc, 'Failed to start Docker process');

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start  = time();

        while (true) {
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;

            $changed = stream_select($read, $write, $except, 1);

            if ($changed === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            if ((time() - $start) > $timeoutSeconds) {
                proc_terminate($proc, 9);
                break;
            }
        }

        $remaining1 = stream_get_contents($pipes[1]);
        $remaining2 = stream_get_contents($pipes[2]);
        if (is_string($remaining1)) {
            $stdout .= $remaining1;
        }
        if (is_string($remaining2)) {
            $stderr .= $remaining2;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJsonNd(string $output): array
    {
        $events = [];

        foreach (explode("\n", $output) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function extractConfidence(array $report): float
    {
        if (isset($report['confidence'])) {
            if (is_array($report['confidence']) && isset($report['confidence']['score'])) {
                return (float) $report['confidence']['score'];
            }
            return (float) $report['confidence'];
        }

        if (isset($report['summary']['confidence'])) {
            return (float) $report['summary']['confidence'];
        }

        return 0.0;
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $destPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $destPath);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function hashDirectory(string $dir): string
    {
        $hashes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($dir, '', $file->getPathname());
                $hashes[$relativePath] = md5_file($file->getPathname()) ?: '';
            }
        }

        ksort($hashes);
        return md5(serialize($hashes));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\ConfigDefaultsAuditor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\ConfigDefaultsAuditor
 */
final class ConfigDefaultsAuditorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/config_auditor_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_returns_empty_when_no_config_dir(): void
    {
        $auditor = new ConfigDefaultsAuditor();

        ob_start();
        $result = $auditor->audit($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $this->assertSame([], $result->manualReviewItems);
    }

    public function test_emits_migration_numbering_info_when_migrations_dir_exists(): void
    {
        mkdir($this->tmpDir . '/config', 0777, true);
        mkdir($this->tmpDir . '/database/migrations', 0777, true);

        $auditor = new ConfigDefaultsAuditor();

        ob_start();
        $result = $auditor->audit($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        $migrationItems = array_filter(
            $result->manualReviewItems,
            fn($i) => str_contains($i->description, 'migration numbering')
        );
        $this->assertNotEmpty($migrationItems);
    }

    public function test_detects_database_config_without_db_connection(): void
    {
        mkdir($this->tmpDir . '/config', 0777, true);
        file_put_contents($this->tmpDir . '/config/database.php', "<?php return ['default' => 'mysql'];");

        $auditor = new ConfigDefaultsAuditor();

        ob_start();
        $result = $auditor->audit($this->tmpDir);
        ob_end_clean();

        $dbItems = array_filter(
            $result->manualReviewItems,
            fn($i) => str_contains($i->description, 'SQLite')
        );
        $this->assertNotEmpty($dbItems);
    }

    public function test_skips_database_warning_when_db_connection_is_set(): void
    {
        mkdir($this->tmpDir . '/config', 0777, true);
        file_put_contents($this->tmpDir . '/config/database.php', "<?php return ['default' => env('DB_CONNECTION', 'mysql')];");

        $auditor = new ConfigDefaultsAuditor();

        ob_start();
        $result = $auditor->audit($this->tmpDir);
        ob_end_clean();

        $dbItems = array_filter(
            $result->manualReviewItems,
            fn($i) => str_contains($i->description, 'SQLite')
        );
        $this->assertEmpty($dbItems);
    }

    public function test_detects_queue_sync_driver(): void
    {
        mkdir($this->tmpDir . '/config', 0777, true);
        file_put_contents($this->tmpDir . '/config/queue.php', "<?php return ['default' => 'sync'];");

        $auditor = new ConfigDefaultsAuditor();

        ob_start();
        $result = $auditor->audit($this->tmpDir);
        ob_end_clean();

        $queueItems = array_filter(
            $result->manualReviewItems,
            fn($i) => str_contains($i->description, 'QUEUE_CONNECTION')
        );
        $this->assertNotEmpty($queueItems);
    }

    public function test_detects_sanctum_missing_encrypt_cookies(): void
    {
        mkdir($this->tmpDir . '/config', 0777, true);
        file_put_contents($this->tmpDir . '/config/sanctum.php', "<?php return ['stateful' => []];");

        $auditor = new ConfigDefaultsAuditor();

        ob_start();
        $result = $auditor->audit($this->tmpDir);
        ob_end_clean();

        $sanctumItems = array_filter(
            $result->manualReviewItems,
            fn($i) => str_contains($i->description, 'encrypt_cookies')
        );
        $this->assertNotEmpty($sanctumItems);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

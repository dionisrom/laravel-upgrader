<?php

declare(strict_types=1);

namespace Tests\Unit\SlimSkeleton;

use AppContainer\SlimSkeleton\BootstrapProvidersMigrator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AppContainer\SlimSkeleton\BootstrapProvidersMigrator
 */
final class BootstrapProvidersMigratorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/providers_migrator_test_' . uniqid();
        mkdir($this->tmpDir . '/config', 0777, true);
        mkdir($this->tmpDir . '/bootstrap', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function test_returns_no_config_app_result_when_absent(): void
    {
        $migrator = new BootstrapProvidersMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->configAppExists);
        $this->assertTrue($result->success);
        $this->assertSame([], $result->providers);
    }

    public function test_filters_out_framework_auto_providers(): void
    {
        $this->writeConfigApp(<<<'PHP'
<?php
return [
    'providers' => [
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        App\Providers\AppServiceProvider::class,
        App\Providers\TenancyServiceProvider::class,
    ],
];
PHP);

        $migrator = new BootstrapProvidersMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $this->assertTrue($result->success);
        // Framework providers should be removed
        $this->assertNotContains('Illuminate\\Auth\\AuthServiceProvider', $result->providers);
        $this->assertNotContains('Illuminate\\Database\\DatabaseServiceProvider', $result->providers);
        // App providers should remain
        $this->assertContains('App\\Providers\\AppServiceProvider', $result->providers);
        $this->assertContains('App\\Providers\\TenancyServiceProvider', $result->providers);
    }

    public function test_writes_bootstrap_providers_php(): void
    {
        $this->writeConfigApp(<<<'PHP'
<?php
return [
    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],
];
PHP);

        $migrator = new BootstrapProvidersMigrator();

        ob_start();
        $migrator->migrate($this->tmpDir);
        ob_end_clean();

        $providersFile = $this->tmpDir . '/bootstrap/providers.php';
        $this->assertFileExists($providersFile);
        $this->assertStringContainsString('App\\Providers\\AppServiceProvider', file_get_contents($providersFile) ?: '');
    }

    public function test_skips_writing_if_providers_file_already_exists(): void
    {
        $this->writeConfigApp(<<<'PHP'
<?php
return [
    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],
];
PHP);

        $existingContent = '<?php return [/* existing */];';
        file_put_contents($this->tmpDir . '/bootstrap/providers.php', $existingContent);

        $migrator = new BootstrapProvidersMigrator();

        ob_start();
        $result = $migrator->migrate($this->tmpDir);
        ob_end_clean();

        // File should not be overwritten
        $this->assertSame($existingContent, file_get_contents($this->tmpDir . '/bootstrap/providers.php'));
        // Should have a manual review item about the skip
        $this->assertCount(1, $result->manualReviewItems);
    }

    public function test_failure_result_on_parse_error(): void
    {
        $this->writeConfigApp('<?php nonsense {{{');

        $migrator = new BootstrapProvidersMigrator();
        $result   = $migrator->migrate($this->tmpDir);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
    }

    private function writeConfigApp(string $code): void
    {
        file_put_contents($this->tmpDir . '/config/app.php', $code);
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

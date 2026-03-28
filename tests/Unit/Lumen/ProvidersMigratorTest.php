<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\ProvidersMigrator;
use PHPUnit\Framework\TestCase;

final class ProvidersMigratorTest extends TestCase
{
    private string $tempDir;
    private string $workspace;
    private string $target;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader-providers-test-' . uniqid('', true);
        $this->workspace = $this->tempDir . '/workspace';
        $this->target = $this->tempDir . '/target';
        mkdir($this->workspace . '/bootstrap', 0777, true);
        mkdir($this->target . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testMigratesSimpleProviderRegistrations(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->register(App\Providers\AuthServiceProvider::class);
$app->register(App\Providers\EventServiceProvider::class);
PHP);

        // Create a minimal Laravel config/app.php with providers array
        file_put_contents($this->target . '/config/app.php', <<<'PHP'
<?php
return [
    'providers' => [
        App\Providers\RouteServiceProvider::class,
    ],
];
PHP);

        $migrator = new ProvidersMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertSame(2, $result->migratedCount);

        $config = file_get_contents($this->target . '/config/app.php');
        self::assertStringContainsString('AuthServiceProvider::class', $config);
        self::assertStringContainsString('EventServiceProvider::class', $config);
        self::assertStringContainsString('Migrated from Lumen', $config);
    }

    public function testSkipsLumenBuiltinProviders(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->register(Illuminate\Auth\AuthServiceProvider::class);
$app->register(App\Providers\CustomProvider::class);
PHP);

        file_put_contents($this->target . '/config/app.php', <<<'PHP'
<?php
return [
    'providers' => [
        App\Providers\RouteServiceProvider::class,
    ],
];
PHP);

        $migrator = new ProvidersMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertSame(1, $result->migratedCount);
        self::assertContains('\App\Providers\CustomProvider', $result->migratedProviders);
    }

    public function testProviderWithConstructorArgsFlaggedForManualReview(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->register(new App\Providers\CustomProvider($app));
PHP);

        file_put_contents($this->target . '/config/app.php', <<<'PHP'
<?php
return ['providers' => [App\Providers\RouteServiceProvider::class]];
PHP);

        $migrator = new ProvidersMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertSame(0, $result->migratedCount);
        self::assertCount(1, $result->manualReviewItems);
        self::assertStringContainsString('constructor', $result->manualReviewItems[0]->description);
    }

    public function testMissingBootstrapFileReturnsSuccessEmpty(): void
    {
        rmdir($this->workspace . '/bootstrap');

        $migrator = new ProvidersMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertSame(0, $result->migratedCount);
    }

    public function testEmitsJsonNdEvents(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->register(App\Providers\MyProvider::class);
PHP);

        file_put_contents($this->target . '/config/app.php', <<<'PHP'
<?php
return ['providers' => [App\Providers\RouteServiceProvider::class]];
PHP);

        $migrator = new ProvidersMigrator();
        ob_start();
        $migrator->migrate($this->workspace, $this->target);
        $output = (string) ob_get_clean();

        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $lastEvent = json_decode(end($lines), true);
        self::assertSame('lumen_providers_migrated', $lastEvent['event']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

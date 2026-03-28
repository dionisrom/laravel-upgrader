<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\EloquentBootstrapDetector;
use PHPUnit\Framework\TestCase;

final class EloquentBootstrapDetectorTest extends TestCase
{
    private string $tempDir;
    private string $workspace;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader-eloquent-test-' . uniqid('', true);
        $this->workspace = $this->tempDir . '/workspace';
        mkdir($this->workspace . '/bootstrap', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testEloquentEnabledWithDbConfig(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->withEloquent();
PHP);
        mkdir($this->workspace . '/config', 0777, true);
        file_put_contents($this->workspace . '/config/database.php', '<?php return [];');

        $detector = new EloquentBootstrapDetector();
        ob_start();
        $result = $detector->detect($this->workspace);
        ob_end_clean();

        self::assertTrue($result->eloquentEnabled);
        self::assertTrue($result->databaseConfigExists);
        self::assertNull($result->warning);
    }

    public function testEloquentEnabledWithoutDbConfigEmitsWarning(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->withEloquent();
PHP);

        $detector = new EloquentBootstrapDetector();
        ob_start();
        $result = $detector->detect($this->workspace);
        $output = (string) ob_get_clean();

        self::assertTrue($result->eloquentEnabled);
        self::assertFalse($result->databaseConfigExists);
        self::assertNotNull($result->warning);
        self::assertStringContainsString('lumen_manual_review', $output);
    }

    public function testEloquentDisabledEmitsFeatureDisabledEvent(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
PHP);

        $detector = new EloquentBootstrapDetector();
        ob_start();
        $result = $detector->detect($this->workspace);
        $output = (string) ob_get_clean();

        self::assertFalse($result->eloquentEnabled);
        self::assertStringContainsString('lumen_feature_disabled', $output);
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\InlineConfigExtractor;
use PHPUnit\Framework\TestCase;

final class InlineConfigExtractorTest extends TestCase
{
    private string $tempDir;
    private string $workspace;
    private string $target;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader-config-test-' . uniqid('', true);
        $this->workspace = $this->tempDir . '/workspace';
        $this->target = $this->tempDir . '/target';
        mkdir($this->workspace . '/bootstrap', 0777, true);
        mkdir($this->workspace . '/config', 0777, true);
        mkdir($this->target, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testCopiesExistingConfigFiles(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->configure('database');
$app->configure('auth');
PHP);
        file_put_contents($this->workspace . '/config/database.php', '<?php return ["driver" => "mysql"];');
        file_put_contents($this->workspace . '/config/auth.php', '<?php return ["guard" => "api"];');

        $extractor = new InlineConfigExtractor();
        ob_start();
        $result = $extractor->extract($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertContains('database', $result->copiedConfigs);
        self::assertContains('auth', $result->copiedConfigs);
        self::assertFileExists($this->target . '/config/database.php');
        self::assertFileExists($this->target . '/config/auth.php');
    }

    public function testGeneratesStubForMissingConfig(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->configure('custom');
PHP);

        $extractor = new InlineConfigExtractor();
        ob_start();
        $result = $extractor->extract($this->workspace, $this->target);
        ob_end_clean();

        self::assertContains('custom', $result->stubbedConfigs);
        self::assertFileExists($this->target . '/config/custom.php');
        self::assertNotEmpty($result->manualReviewItems);

        $stub = file_get_contents($this->target . '/config/custom.php');
        self::assertStringContainsString('Auto-generated stub', $stub);
    }

    public function testSkipsCopyWhenTargetAlreadyHasConfig(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->configure('app');
PHP);
        file_put_contents($this->workspace . '/config/app.php', '<?php return ["name" => "Lumen"];');
        mkdir($this->target . '/config', 0777, true);
        file_put_contents($this->target . '/config/app.php', '<?php return ["name" => "Laravel"];');

        $extractor = new InlineConfigExtractor();
        ob_start();
        $result = $extractor->extract($this->workspace, $this->target);
        ob_end_clean();

        // Should still report as copied
        self::assertContains('app', $result->copiedConfigs);
        // But target content should remain unchanged (not overwritten)
        $content = file_get_contents($this->target . '/config/app.php');
        self::assertStringContainsString('Laravel', $content);
    }

    public function testNoConfigureCallsReturnsEmpty(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
PHP);

        $extractor = new InlineConfigExtractor();
        ob_start();
        $result = $extractor->extract($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertEmpty($result->copiedConfigs);
        self::assertEmpty($result->stubbedConfigs);
    }

    public function testMissingBootstrapReturnsEmpty(): void
    {
        rmdir($this->workspace . '/bootstrap');

        $extractor = new InlineConfigExtractor();
        ob_start();
        $result = $extractor->extract($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertEmpty($result->copiedConfigs);
    }

    public function testEmitsJsonNdEvents(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->configure('cache');
PHP);
        file_put_contents($this->workspace . '/config/cache.php', '<?php return [];');

        $extractor = new InlineConfigExtractor();
        ob_start();
        $extractor->extract($this->workspace, $this->target);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('lumen_config_extracted', $output);
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

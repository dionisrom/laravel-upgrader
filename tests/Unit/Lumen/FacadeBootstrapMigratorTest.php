<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\FacadeBootstrapMigrator;
use PHPUnit\Framework\TestCase;

final class FacadeBootstrapMigratorTest extends TestCase
{
    private string $tempDir;
    private string $workspace;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader-facade-test-' . uniqid('', true);
        $this->workspace = $this->tempDir . '/workspace';
        mkdir($this->workspace . '/bootstrap', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testDetectsBothFacadesAndEloquent(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
$app->withFacades();
$app->withEloquent();
PHP);

        $migrator = new FacadeBootstrapMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace);
        ob_end_clean();

        self::assertTrue($result->facadesEnabled);
        self::assertTrue($result->eloquentEnabled);
    }

    public function testMissingFacadesEmitsDisabledEvent(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
// No withFacades() or withEloquent()
PHP);

        $migrator = new FacadeBootstrapMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace);
        $output = (string) ob_get_clean();

        self::assertFalse($result->facadesEnabled);
        self::assertFalse($result->eloquentEnabled);

        // Should emit lumen_feature_disabled for facades only (eloquent delegated to detector)
        self::assertStringContainsString('lumen_feature_disabled', $output);
        self::assertStringContainsString('"feature":"facades"', str_replace(' ', '', $output));
    }

    public function testMissingBootstrapReturnsDisabled(): void
    {
        rmdir($this->workspace . '/bootstrap');

        $migrator = new FacadeBootstrapMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace);
        ob_end_clean();

        self::assertFalse($result->facadesEnabled);
        self::assertFalse($result->eloquentEnabled);
    }

    public function testCommentedOutFacadeAndEloquentCallsAreIgnored(): void
    {
        file_put_contents($this->workspace . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new Laravel\Lumen\Application(dirname(__DIR__));
// $app->withFacades();
// $app->withEloquent();
PHP);

        $migrator = new FacadeBootstrapMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace);
        $output = (string) ob_get_clean();

        self::assertFalse($result->facadesEnabled);
        self::assertFalse($result->eloquentEnabled);
        self::assertStringContainsString('lumen_feature_disabled', $output);
        self::assertStringContainsString('"feature":"facades"', str_replace(' ', '', $output));
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

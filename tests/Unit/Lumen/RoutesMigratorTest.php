<?php

declare(strict_types=1);

namespace Tests\Unit\Lumen;

use AppContainer\Lumen\RoutesMigrator;
use PHPUnit\Framework\TestCase;

final class RoutesMigratorTest extends TestCase
{
    private string $tempDir;
    private string $workspace;
    private string $target;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/upgrader-routes-test-' . uniqid('', true);
        $this->workspace = $this->tempDir . '/workspace';
        $this->target = $this->tempDir . '/target';
        mkdir($this->workspace . '/routes', 0777, true);
        mkdir($this->target, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testSimpleRouterVerbsConvertedToRouteFacade(): void
    {
        file_put_contents($this->workspace . '/routes/web.php', <<<'PHP'
<?php
$router->get('/', function () { return 'hello'; });
$router->post('/submit', 'FormController@store');
$router->put('/update/{id}', 'FormController@update');
$router->delete('/delete/{id}', 'FormController@destroy');
PHP);

        $migrator = new RoutesMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertSame(4, $result->migratedCount);
        self::assertSame(0, $result->flaggedCount);

        $output = file_get_contents($this->target . '/routes/web.php');
        self::assertStringContainsString('Route::get', $output);
        self::assertStringContainsString('Route::post', $output);
        self::assertStringContainsString('Route::put', $output);
        self::assertStringContainsString('Route::delete', $output);
        self::assertStringNotContainsString('$router->', $output);
    }

    public function testGroupMethodConverted(): void
    {
        file_put_contents($this->workspace . '/routes/web.php', <<<'PHP'
<?php
$router->group(['prefix' => 'api'], function () use ($router) {
    $router->get('/users', 'UserController@index');
});
PHP);

        $migrator = new RoutesMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertSame(2, $result->migratedCount);
        $output = file_get_contents($this->target . '/routes/web.php');
        self::assertStringContainsString('Route::group', $output);
        self::assertStringContainsString('Route::get', $output);
    }

    public function testAddRouteConvertedToMatch(): void
    {
        file_put_contents($this->workspace . '/routes/web.php', <<<'PHP'
<?php
$router->addRoute(['GET', 'POST'], '/form', 'FormController@handle');
PHP);

        $migrator = new RoutesMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertSame(1, $result->migratedCount);
        $output = file_get_contents($this->target . '/routes/web.php');
        self::assertStringContainsString('Route::match', $output);
    }

    public function testUnrecognisedMethodFlagged(): void
    {
        file_put_contents($this->workspace . '/routes/web.php', <<<'PHP'
<?php
$router->customMethod('/test', 'TestController@test');
PHP);

        $migrator = new RoutesMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertSame(0, $result->migratedCount);
        self::assertSame(1, $result->flaggedCount);
        self::assertCount(1, $result->manualReviewItems);
        self::assertStringContainsString('customMethod', $result->manualReviewItems[0]->description);
    }

    public function testClosureUsingAppFlagged(): void
    {
        file_put_contents($this->workspace . '/routes/web.php', <<<'PHP'
<?php
$router->get('/test', function () use ($app) {
    return $app->make('something');
});
PHP);

        $migrator = new RoutesMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertGreaterThanOrEqual(1, $result->flaggedCount);
        $descriptions = array_map(fn($i) => $i->description, $result->manualReviewItems);
        self::assertTrue(
            in_array(true, array_map(fn($d) => str_contains($d, '$app'), $descriptions)),
            'Expected a manual review item about $app usage'
        );
    }

    public function testNoRouteFilesReturnsEmptyResult(): void
    {
        // Remove the routes dir
        rmdir($this->workspace . '/routes');

        $migrator = new RoutesMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertTrue($result->success);
        self::assertSame(0, $result->migratedCount);
    }

    public function testAppHttpRoutesPhpMappedToWebPhp(): void
    {
        rmdir($this->workspace . '/routes');
        mkdir($this->workspace . '/app/Http', 0777, true);
        file_put_contents($this->workspace . '/app/Http/routes.php', <<<'PHP'
<?php
$router->get('/', 'HomeController@index');
PHP);

        $migrator = new RoutesMigrator();
        ob_start();
        $result = $migrator->migrate($this->workspace, $this->target);
        ob_end_clean();

        self::assertSame(1, $result->migratedCount);
        self::assertFileExists($this->target . '/routes/web.php');
    }

    public function testEmitsJsonNdEvents(): void
    {
        file_put_contents($this->workspace . '/routes/web.php', <<<'PHP'
<?php
$router->get('/', 'HomeController@index');
PHP);

        $migrator = new RoutesMigrator();
        ob_start();
        $migrator->migrate($this->workspace, $this->target);
        $output = (string) ob_get_clean();

        $lines = array_filter(array_map('trim', explode("\n", $output)));
        self::assertNotEmpty($lines);

        $event = json_decode(end($lines), true);
        self::assertSame('lumen_routes_migrated', $event['event']);
        self::assertArrayHasKey('migrated', $event);
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

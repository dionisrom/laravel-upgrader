<?php

declare(strict_types=1);

namespace Tests\Unit;

use AppContainer\Config\EnvMigrator;
use PHPUnit\Framework\TestCase;

class EnvMigratorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/env_migrator_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        // Clean up
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function writeEnv(string $content): void
    {
        file_put_contents($this->tmpDir . '/.env', $content);
    }

    private function readEnv(): string
    {
        return file_get_contents($this->tmpDir . '/.env');
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function testMixKeyRenamedToVite(): void
    {
        $this->writeEnv("APP_NAME=MyApp\nMIX_APP_URL=http://localhost\n");

        $migrator = new EnvMigrator();
        $result = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('MIX_APP_URL', $result->renamedKeys);
        $this->assertSame('VITE_APP_URL', $result->renamedKeys['MIX_APP_URL']);

        $output = $this->readEnv();
        $this->assertStringContainsString('VITE_APP_URL=http://localhost', $output);
        $this->assertStringContainsString('# DEPRECATED: use VITE_APP_URL', $output);
        $this->assertStringContainsString('MIX_APP_URL=http://localhost', $output);
    }

    public function testViteAppNameAddedWhenMixAppUrlPresent(): void
    {
        $this->writeEnv("MIX_APP_URL=http://localhost\n");

        $migrator = new EnvMigrator();
        $result = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        $this->assertContains('VITE_APP_NAME', $result->addedKeys);

        $output = $this->readEnv();
        $this->assertStringContainsString('VITE_APP_NAME=${APP_NAME}', $output);
    }

    public function testCommentsAndBlankLinesPreserved(): void
    {
        $this->writeEnv("# This is a comment\n\nAPP_NAME=Test\n\n# Another comment\n");

        $migrator = new EnvMigrator();
        $result = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        $this->assertSame([], $result->renamedKeys);

        $output = $this->readEnv();
        $this->assertStringContainsString('# This is a comment', $output);
        $this->assertStringContainsString('# Another comment', $output);
    }

    public function testQuotedValuesPreserved(): void
    {
        $this->writeEnv("MIX_PUSHER_KEY=\"my-key-with-spaces\"\n");

        $migrator = new EnvMigrator();
        $result = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        $output = $this->readEnv();
        $this->assertStringContainsString('VITE_PUSHER_KEY="my-key-with-spaces"', $output);
    }

    public function testNoOpWhenNoMixKeys(): void
    {
        $this->writeEnv("APP_NAME=Test\nAPP_ENV=local\n");

        $migrator = new EnvMigrator();
        $result = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        $this->assertSame([], $result->renamedKeys);
        $this->assertSame([], $result->addedKeys);
    }

    public function testMissingEnvFileReturnsSuccess(): void
    {
        $migrator = new EnvMigrator();
        $result = $migrator->migrate($this->tmpDir); // no .env created

        $this->assertTrue($result->success);
        $this->assertSame([], $result->renamedKeys);
    }

    public function testMultipleMixKeysAllRenamed(): void
    {
        $this->writeEnv("MIX_PUSHER_APP_KEY=key1\nMIX_PUSHER_APP_CLUSTER=cluster1\n");

        $migrator = new EnvMigrator();
        $result = $migrator->migrate($this->tmpDir);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->renamedKeys);
        $this->assertSame('VITE_PUSHER_APP_KEY', $result->renamedKeys['MIX_PUSHER_APP_KEY']);
        $this->assertSame('VITE_PUSHER_APP_CLUSTER', $result->renamedKeys['MIX_PUSHER_APP_CLUSTER']);
    }
}

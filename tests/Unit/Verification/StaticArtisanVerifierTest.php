<?php

declare(strict_types=1);

namespace Tests\Unit\Verification;

use AppContainer\Verification\StaticArtisanVerifier;
use AppContainer\Verification\VerificationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StaticArtisanVerifierTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/static_artisan_test_' . uniqid();
        mkdir($this->tmpDir . '/config', 0755, true);
        mkdir($this->tmpDir . '/routes', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testPassesWithValidConfigFile(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/app.php',
            '<?php return ["debug" => true, "name" => "TestApp"];',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
    }

    public function testFailsWhenConfigFileDoesNotReturnArray(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/services.php',
            '<?php $config = ["key" => "value"];',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertGreaterThan(0, $result->issueCount);

        $messages = array_map(fn($i) => $i->message, $result->issues);
        self::assertStringContainsString('services.php', implode(' ', $messages));
        self::assertStringContainsString('does not return a plain PHP array', implode(' ', $messages));
    }

    public function testFailsWhenConfigReturnsNonArray(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/cache.php',
            '<?php return "not_an_array";',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        $messages = array_map(fn($i) => $i->message, $result->issues);
        self::assertStringContainsString('cache.php', implode(' ', $messages));
    }

    public function testPassesWithEmptyWorkspace(): void
    {
        // Remove the directories created in setUp
        $this->removeDirectory($this->tmpDir . '/config');
        $this->removeDirectory($this->tmpDir . '/routes');

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
    }

    public function testPassesWithEmptyConfigDirectory(): void
    {
        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
    }

    public function testReportsIssueForConfigParseError(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/broken.php',
            '<?php return [;',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertFalse($result->passed);
        self::assertGreaterThan(0, $result->issueCount);
    }

    public function testPassesWhenConfigReturnsArrayAfterOtherStatements(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/database.php',
            '<?php $default = "mysql"; return ["default" => $default, "connections" => []];',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->issueCount);
    }

    public function testVerifierNameIsCorrect(): void
    {
        $ctx    = new VerificationContext(workspacePath: $this->tmpDir);
        $result = (new StaticArtisanVerifier())->verify($this->tmpDir, $ctx);

        self::assertSame('StaticArtisanVerifier', $result->verifierName);
    }

    public function testArtisanVerifyNotRunByDefault(): void
    {
        $called  = 0;
        $factory = function (array $cmd, string $cwd) use (&$called): Process {
            $called++;
            $mock = $this->createMock(Process::class);
            $mock->method('run')->willReturn(0);
            $mock->method('getExitCode')->willReturn(0);
            return $mock;
        };

        file_put_contents(
            $this->tmpDir . '/config/app.php',
            '<?php return ["name" => "Test"];',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir, withArtisanVerify: false);
        $result = (new StaticArtisanVerifier($factory))->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertSame(0, $called);
    }

    public function testArtisanVerifyRunsWhenOptedIn(): void
    {
        $commands = [];
        $factory  = function (array $cmd, string $cwd) use (&$commands): Process {
            $commands[] = $cmd;
            $mock = $this->createMock(Process::class);
            $mock->method('run')->willReturn(0);
            $mock->method('getExitCode')->willReturn(0);
            return $mock;
        };

        file_put_contents(
            $this->tmpDir . '/config/app.php',
            '<?php return ["name" => "Test"];',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir, withArtisanVerify: true);
        $result = (new StaticArtisanVerifier($factory))->verify($this->tmpDir, $ctx);

        self::assertTrue($result->passed);
        self::assertCount(2, $commands);
        self::assertContains('config:cache', $commands[0]);
        self::assertContains('route:list', $commands[1]);
    }

    public function testArtisanVerifyFailureIsAdvisoryNotBlocking(): void
    {
        $factory = function (array $cmd, string $cwd): Process {
            $mock = $this->createMock(Process::class);
            $mock->method('run')->willReturn(1);
            $mock->method('getExitCode')->willReturn(1);
            $mock->method('getOutput')->willReturn('');
            $mock->method('getErrorOutput')->willReturn('artisan error');
            return $mock;
        };

        file_put_contents(
            $this->tmpDir . '/config/app.php',
            '<?php return ["name" => "Test"];',
        );

        $ctx    = new VerificationContext(workspacePath: $this->tmpDir, withArtisanVerify: true);
        $result = (new StaticArtisanVerifier($factory))->verify($this->tmpDir, $ctx);

        // Should still pass — artisan failures are warnings, not errors
        self::assertTrue($result->passed);
        self::assertGreaterThan(0, $result->issueCount);
        $warnings = array_filter($result->issues, fn($i) => $i->severity === 'warning');
        self::assertNotEmpty($warnings);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
